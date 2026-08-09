<?php

namespace App\Support\Roster;

use App\Models\Level;
use App\Models\Person;
use App\Models\Position;
use App\Support\Calendar;
use App\Support\LevelAssignment;
use App\Support\PositionChange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ST-04's importer. `preview()` and `commit()` share ONE analysis (`analyse()`) — that is what
 * makes the preview honest: it is not a separate estimate, it is the same function, called with
 * an empty `$confirmations` for a preview and the operator's own for a commit.
 *
 * MATCHING is `Person::matchByEmail()` alone (`withTrashed()` — a soft-deleted person is
 * REDISCOVERED, never duplicated) with `short_name` as the documented secondary key for a row
 * with no email. There is no second matching predicate anywhere in this file.
 *
 * NOTHING HERE CREATES AN ACCOUNT. A roster row creates or updates a `people` row and nothing
 * else — see `tests/Feature/Build/RosterNeverMintsCredentialsTest.php`.
 */
final class RosterImport
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    /** No email, no short_name match — an operator tick is required before this can create. */
    public const SKIP_NO_EMAIL_UNCONFIRMED = 'skip_no_email_unconfirmed';

    /** The matched person already has a claimed account — a spreadsheet must not rename one. */
    public const SKIP_HAS_ACCOUNT = 'skip_has_account';

    public const ERROR = 'error';

    /** Columns the operator maps a source header onto. `full_name` and `position` are required. */
    private const REQUIRED_FIELDS = ['full_name', 'position'];

    private const OPTIONAL_FIELDS = ['short_name', 'email', 'phone', 'level', 'joined_at'];

    /**
     * @param  array<string, string>  $mapping  destination field => source header text
     * @return array{file_errors: list<string>, rows: list<array<string, mixed>>, summary: array<string, int>}
     */
    public static function preview(RosterReader $reader, array $mapping): array
    {
        return self::analyse($reader, $mapping, []);
    }

    /**
     * Applies only rows whose FRESH analysis (re-run inside this transaction, never trusted from
     * an earlier preview call) resolves to `create` or `update`. Refuses the WHOLE import
     * outright when `file_errors` is non-empty — never "7 of 8 imported": two rows disagreeing
     * about who owns an address is a question about the source file, and the operator answers it
     * there, not by having the system guess which 7 to keep.
     *
     * @param  array<string, string>  $mapping
     * @param  array<int, bool>  $confirmations  row POSITION (0-based, matching each row's own
     *                                           order) => operator confirmed a no-email create
     * @return array{summary: array<string, int>, rows: list<array<string, mixed>>, file_errors: list<string>}
     */
    public static function commit(RosterReader $reader, array $mapping, array $confirmations, Request $request): array
    {
        return DB::transaction(function () use ($reader, $mapping, $confirmations, $request): array {
            $analysis = self::analyse($reader, $mapping, $confirmations);

            $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0];

            if ($analysis['file_errors'] !== []) {
                return ['summary' => $summary, 'rows' => $analysis['rows'], 'file_errors' => $analysis['file_errors']];
            }

            $actorId = $request->user()?->getKey();

            foreach ($analysis['rows'] as &$row) {
                $outcome = $row['outcome'];

                if ($outcome === self::CREATE) {
                    self::applyCreate($row, $actorId, $request);
                    $summary['created']++;
                } elseif ($outcome === self::UPDATE) {
                    self::applyUpdate($row, $actorId, $request);
                    $summary['updated']++;
                } else {
                    $summary['skipped']++;
                }
            }
            unset($row);

            return ['summary' => $summary, 'rows' => $analysis['rows'], 'file_errors' => []];
        });
    }

    /**
     * The ONE analysis `preview()` and `commit()` both call — never a separate estimate.
     *
     * @param  array<string, string>  $mapping
     * @param  array<int, bool>  $confirmations
     * @return array{file_errors: list<string>, rows: list<array<string, mixed>>, summary: array<string, int>}
     */
    private static function analyse(RosterReader $reader, array $mapping, array $confirmations): array
    {
        $headers = $reader->headers();
        $fileErrors = [];

        $fieldLabels = ['full_name' => 'Full Name', 'position' => 'Position'];

        foreach (self::REQUIRED_FIELDS as $field) {
            $header = $mapping[$field] ?? '';
            if ($header === '' || ! in_array($header, $headers, true)) {
                $fileErrors[] = ($fieldLabels[$field] ?? $field).' column is required — map a column to it before importing.';
            }
        }

        $rawRows = [];
        foreach ($reader->rows() as $raw) {
            $rawRows[] = $raw;
        }

        // The in-file duplicate check (P1 plan item 13): BOTH `email` and `short_name` are
        // UNIQUE outright on `people`, so two rows sharing either pass row-by-row validation and
        // 23000 on insert. Run over the WHOLE parsed set, before any row is judged individually,
        // so the file-level refusal is complete rather than discovered mid-write.
        $fileErrors = array_merge($fileErrors, self::duplicateErrors($rawRows, $mapping, $headers));

        $rows = [];
        foreach ($rawRows as $position => $raw) {
            $rows[] = self::analyseRow($position, $raw, $mapping, $headers, $confirmations[$position] ?? false);
        }

        $summary = ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
        foreach ($rows as $row) {
            $summary[match ($row['outcome']) {
                self::CREATE => 'create',
                self::UPDATE => 'update',
                self::ERROR => 'error',
                default => 'skip',
            }]++;
        }

        return ['file_errors' => $fileErrors, 'rows' => $rows, 'summary' => $summary];
    }

    /**
     * @param  array<int, array<string, string>>  $rawRows
     * @param  array<string, string>  $mapping
     * @param  list<string>  $headers
     * @return list<string>
     */
    private static function duplicateErrors(array $rawRows, array $mapping, array $headers): array
    {
        $errors = [];

        foreach (['email' => 'email address', 'short_name' => 'short name'] as $field => $label) {
            $header = $mapping[$field] ?? '';
            if ($header === '' || ! in_array($header, $headers, true)) {
                continue;
            }

            $seenAt = [];
            foreach ($rawRows as $position => $raw) {
                $value = trim((string) ($raw[$header] ?? ''));
                if ($value === '') {
                    continue;
                }
                if ($field === 'email') {
                    $value = (string) Person::normalizeEmail($value);
                }

                $seenAt[$value][] = $position + 2; // line number: header is line 1
            }

            foreach ($seenAt as $value => $lines) {
                if (count($lines) > 1) {
                    $errors[] = "Duplicate {$label} '{$value}' on lines ".implode(', ', $lines)
                        .' — resolve this in the source file before importing.';
                }
            }
        }

        return $errors;
    }

    /** @param  array<string, string>  $raw  @param  array<string, string>  $mapping  @param  list<string>  $headers */
    private static function analyseRow(int $position, array $raw, array $mapping, array $headers, bool $confirmed): array
    {
        $line = $position + 2;
        $field = fn (string $name): string => trim((string) ($raw[$mapping[$name] ?? ''] ?? ''));

        $allBlank = true;
        foreach ($headers as $header) {
            if (trim((string) ($raw[$header] ?? '')) !== '') {
                $allBlank = false;
                break;
            }
        }

        if ($allBlank) {
            return [
                'line' => $line, 'outcome' => self::ERROR, 'full_name' => null,
                'matched_person_id' => null, 'matched_by' => null, 'changes' => [],
                'errors' => ['Blank row — skipped.'], 'values' => [],
            ];
        }

        $fullName = $field('full_name');
        $shortName = $field('short_name') ?: null;
        $email = Person::normalizeEmail($field('email') ?: null);
        $phone = $field('phone') ?: null;
        $joinedRaw = $field('joined_at');
        $positionRaw = $field('position');
        $levelRaw = $field('level');

        $errors = [];

        if ($fullName === '') {
            $errors[] = "Line {$line}: Full Name is required.";
        }

        $positionId = null;
        if ($positionRaw === '') {
            $errors[] = "Line {$line}: Position is required.";
        } else {
            $positionModel = Position::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($positionRaw)])->first();
            if ($positionModel === null) {
                $errors[] = "Line {$line}: unknown position '{$positionRaw}' — valid: "
                    .Position::orderBy('id')->pluck('name')->implode(', ').'.';
            } else {
                $positionId = (int) $positionModel->getKey();
            }
        }

        $levelId = null;
        $levelCode = null;
        if ($levelRaw !== '') {
            $level = Level::query()->where('code', $levelRaw)->first();
            if ($level === null) {
                $errors[] = "Line {$line}: unknown level code '{$levelRaw}' — valid: "
                    .Level::query()->ordered()->pluck('code')->implode(', ').'.';
            } else {
                $levelId = (int) $level->getKey();
                $levelCode = (string) $level->code;
            }
        }

        $joinedAt = null;
        if ($joinedRaw !== '') {
            try {
                $joinedAt = Calendar::parse($joinedRaw)->format(Calendar::YMD);
            } catch (\InvalidArgumentException) {
                $errors[] = "Line {$line}: unparseable Joined date '{$joinedRaw}' — use YYYY-MM-DD.";
            }
        }

        $values = [
            'full_name' => $fullName, 'short_name' => $shortName, 'email' => $email,
            'phone' => $phone, 'position_id' => $positionId, 'level_id' => $levelId,
            'level_code' => $levelCode, 'joined_at' => $joinedAt,
        ];

        if ($errors !== []) {
            return [
                'line' => $line, 'outcome' => self::ERROR, 'full_name' => $fullName ?: null,
                'matched_person_id' => null, 'matched_by' => null, 'changes' => [],
                'errors' => $errors, 'values' => $values,
            ];
        }

        // ONE matcher (finding 1 of the four things to get right): Person::matchByEmail(), which
        // is withTrashed() — a soft-deleted person is REDISCOVERED, never duplicated. A blank
        // email falls back to `short_name` (also UNIQUE outright) as the documented secondary
        // key; there is no third key and no fuzzy matching.
        $matched = null;
        $matchedBy = null;

        if ($email !== null) {
            $matched = Person::matchByEmail($email);
            $matchedBy = $matched !== null ? 'email' : null;
        }

        if ($matched === null && $shortName !== null) {
            $matched = Person::withTrashed()->where('short_name', $shortName)->first();
            $matchedBy = $matched !== null ? 'short_name' : null;
        }

        if ($matched !== null && $matched->hasAccount()) {
            return [
                'line' => $line, 'outcome' => self::SKIP_HAS_ACCOUNT, 'full_name' => $fullName,
                'matched_person_id' => (int) $matched->getKey(), 'matched_by' => $matchedBy,
                'changes' => [],
                'errors' => ["Line {$line}: matches an account holder — update the account from Admin \u{2192} People, not by import."],
                'values' => $values,
            ];
        }

        if ($matched === null && $email === null) {
            if (! $confirmed) {
                return [
                    'line' => $line, 'outcome' => self::SKIP_NO_EMAIL_UNCONFIRMED, 'full_name' => $fullName,
                    'matched_person_id' => null, 'matched_by' => null, 'changes' => [],
                    'errors' => ["Line {$line}: no email — cannot be matched. Tick to confirm this is a new person."],
                    'values' => $values,
                ];
            }

            return [
                'line' => $line, 'outcome' => self::CREATE, 'full_name' => $fullName,
                'matched_person_id' => null, 'matched_by' => null, 'changes' => [],
                'errors' => [], 'values' => $values,
            ];
        }

        if ($matched !== null) {
            return [
                'line' => $line, 'outcome' => self::UPDATE, 'full_name' => $fullName,
                'matched_person_id' => (int) $matched->getKey(), 'matched_by' => $matchedBy,
                'changes' => self::diff($matched, $values), 'errors' => [], 'values' => $values,
            ];
        }

        return [
            'line' => $line, 'outcome' => self::CREATE, 'full_name' => $fullName,
            'matched_person_id' => null, 'matched_by' => null, 'changes' => [],
            'errors' => [], 'values' => $values,
        ];
    }

    /** @param  array<string, mixed>  $values  @return array<string, array{from: mixed, to: mixed}> */
    private static function diff(Person $person, array $values): array
    {
        $changes = [];
        $current = [
            'full_name' => $person->full_name, 'short_name' => $person->short_name,
            'email' => $person->email, 'phone' => $person->phone,
            'joined_at' => $person->joined_at?->format(Calendar::YMD),
        ];

        foreach ($current as $field => $before) {
            $after = $values[$field] ?? null;
            if ($before !== $after) {
                $changes[$field] = ['from' => $before, 'to' => $after];
            }
        }

        $currentLevel = $person->levelAt(Calendar::todayYmd())?->code;
        if ($values['level_code'] !== null && $values['level_code'] !== $currentLevel) {
            $changes['level'] = ['from' => $currentLevel, 'to' => $values['level_code']];
        }

        return $changes;
    }

    /** @param  array<string, mixed>  $row */
    private static function applyCreate(array &$row, ?int $actorId, Request $request): void
    {
        $values = $row['values'];

        $person = Person::create([
            'full_name' => $values['full_name'],
            'short_name' => $values['short_name'],
            'email' => $values['email'],
            'phone' => $values['phone'],
            'position' => $values['position_id'],
            'joined_at' => $values['joined_at'],
            'external' => false,
            'active' => true,
        ]);

        PositionChange::apply($person, (int) $values['position_id'], $request);

        if ($values['level_id'] !== null) {
            LevelAssignment::assign($person, Level::find($values['level_id']), Calendar::todayYmd(), [
                'actor' => $actorId,
                'reason' => 'roster import',
            ]);
        }

        $row['matched_person_id'] = (int) $person->getKey();
    }

    /** @param  array<string, mixed>  $row */
    private static function applyUpdate(array &$row, ?int $actorId, Request $request): void
    {
        $values = $row['values'];

        /** @var Person $person */
        $person = Person::withTrashed()->findOrFail($row['matched_person_id']);

        if ($person->trashed()) {
            // Person::matchByEmail() is withTrashed() precisely so an import REDISCOVERS a
            // retired person instead of creating a duplicate — leaving them soft-deleted while
            // also writing fresh roster data onto them would be a contradiction in terms.
            $person->restore();
        }

        $person->update([
            'short_name' => $values['short_name'],
            'email' => $values['email'],
            'phone' => $values['phone'],
            'joined_at' => $values['joined_at'],
        ]);

        // full_name goes through the same update() call as every other field here — this row
        // was already refused above (SKIP_HAS_ACCOUNT) if the matched person holds an account,
        // so a plain field write can never silently rename someone whose name is frozen onto
        // signed evidence.
        $person->update(['full_name' => $values['full_name']]);

        PositionChange::apply($person, (int) $values['position_id'], $request);

        if ($values['level_id'] !== null) {
            LevelAssignment::assign($person, Level::find($values['level_id']), Calendar::todayYmd(), [
                'actor' => $actorId,
                'reason' => 'roster import',
            ]);
        }
    }
}
