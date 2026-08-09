<?php

namespace App\Support\Roster;

use App\Models\AuditLog;
use App\Models\Level;
use App\Models\Person;
use App\Models\Position;
use App\Support\Calendar;
use App\Support\LevelAssignment;
use App\Support\PositionChange;
use Illuminate\Database\UniqueConstraintViolationException;
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
        $result = DB::transaction(function () use ($reader, $mapping, $confirmations, $request): array {
            $analysis = self::analyse($reader, $mapping, $confirmations);

            $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0];

            if ($analysis['file_errors'] !== []) {
                return ['summary' => $summary, 'rows' => $analysis['rows'], 'file_errors' => $analysis['file_errors']];
            }

            $actorId = $request->user()?->getKey();

            foreach ($analysis['rows'] as &$row) {
                $outcome = $row['outcome'];

                try {
                    if ($outcome === self::CREATE) {
                        self::applyCreate($row, $actorId);
                        $summary['created']++;
                    } elseif ($outcome === self::UPDATE) {
                        self::applyUpdate($row, $actorId);
                        $summary['updated']++;
                    } else {
                        $summary['skipped']++;
                    }
                } catch (UniqueConstraintViolationException) {
                    // Review finding 1's second half: `crossMatchErrors()` above closes the KNOWN
                    // path to this, but defence in depth matters here specifically — Laravel's
                    // own `QueryException::formatMessage()` interpolates the failed query's BOUND
                    // VALUES into the exception message (this row's name, email, phone), and that
                    // message is what a logger would record. The original exception is
                    // deliberately NOT chained as $previous: chaining it would still carry the
                    // contact data into any renderer or logger that walks the previous chain.
                    throw new \RuntimeException(
                        "Line {$row['line']}: a database constraint was violated committing this row. ".
                        'Re-run the preview — if the problem persists, a value in this row collides '.
                        'with an existing person not shown in this file.'
                    );
                }
            }
            unset($row);

            return ['summary' => $summary, 'rows' => $analysis['rows'], 'file_errors' => []];
        });

        // Review finding 6: audited AFTER the transaction commits, never inside it —
        // `AuditLog::record()` opens its own transaction and locks the chain tail, so writing one
        // per row from inside the loop above (as `PositionChange::apply()` would) serialises the
        // whole chain for the import's duration, for no benefit: a failed import rolls the whole
        // transaction back regardless of when inside it an audit row was written. `applyCreate()`
        // and `applyUpdate()` leave a `position_changed` flag on each row rather than auditing
        // themselves (`PositionChange::applyWithoutAudit()`) — only a REAL change is worth a row
        // here; `user_role_change` is on `AuditAnomalies`' single-occurrence watch list, and
        // auditing every row unconditionally (the old behaviour) meant a routine re-import, which
        // touches mostly contact fields, raised a critical OpsAlert every time.
        if ($result['file_errors'] === []) {
            $actorId = $request->user()?->getKey();
            $ip = $request->ip();

            foreach ($result['rows'] as $row) {
                if (($row['position_changed'] ?? false) === true) {
                    AuditLog::record(
                        'user_role_change',
                        'person='.$row['matched_person_id'].';user=none;position='.$row['values']['position_id'],
                        $actorId,
                        $ip,
                    );
                }
            }
        }

        return $result;
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

        // Review finding 2: values went from CSV to the database with only `trim()`, against
        // `varchar(255)/50/255/32` — MySQL 8.4 strict mode throws an uncaught 1406 past these
        // limits; SQLite ignores VARCHAR length outright, which is exactly why this is a
        // VALIDATION check here rather than something left to the column. Limits mirror
        // `App\Http\Requests\Admin\PersonRequest`'s exactly, rather than re-typing them as a
        // second, driftable copy of the same numbers.
        self::checkLength($errors, $line, 'Full Name', $fullName === '' ? null : $fullName, 255);
        self::checkLength($errors, $line, 'Short Name', $shortName, 50);
        self::checkLength($errors, $line, 'Phone', $phone, 32);

        if ($email !== null) {
            if (mb_strlen($email) > 255) {
                $errors[] = "Line {$line}: Email is longer than 255 characters.";
            } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                // `Person::normalizeEmail()` only lowercases and trims — this is the ONLY format
                // check standing between a malformed cell and `people.email`, the address
                // `Person::matchByEmail()` uses for invitation claiming.
                $errors[] = "Line {$line}: '{$email}' is not a valid email address.";
            }
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

        // Review finding 1: matching finds who this row updates, but says nothing about the
        // row's OTHER identifying field — a row whose email matches person A but whose short
        // name already belongs to a DIFFERENT person B previewed clean and then threw a raw
        // UniqueConstraintViolationException on commit (name/email/phone interpolated into the
        // log by Laravel's own exception formatting; see `commit()`'s own catch for the second
        // half of this fix). Checked against every OTHER person — never the value, only the line.
        $crossMatchErrors = self::crossMatchErrors($line, $shortName, $email, $matched);
        if ($crossMatchErrors !== []) {
            return [
                'line' => $line, 'outcome' => self::ERROR, 'full_name' => $fullName,
                'matched_person_id' => $matched !== null ? (int) $matched->getKey() : null,
                'matched_by' => $matchedBy,
                'changes' => [], 'errors' => $crossMatchErrors, 'values' => $values,
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

    /** @param  list<string>  &$errors */
    private static function checkLength(array &$errors, int $line, string $label, ?string $value, int $max): void
    {
        if ($value !== null && mb_strlen($value) > $max) {
            $errors[] = "Line {$line}: {$label} is longer than {$max} characters.";
        }
    }

    /**
     * Review finding 1: `short_name` and `email` are BOTH unique outright on `people`, and
     * matching only ever checks one of them (whichever found `$matched`). This checks the
     * OTHER field — and, defensively, both — against every person that is NOT `$matched`, so a
     * row that would rename someone else's short name or email onto the matched person is
     * refused here rather than at the database, where the failure carries the row's own contact
     * data in its message.
     *
     * @return list<string>
     */
    private static function crossMatchErrors(int $line, ?string $shortName, ?string $email, ?Person $matched): array
    {
        $errors = [];
        $excludeId = $matched?->getKey();

        if ($shortName !== null) {
            $collides = Person::withTrashed()
                ->where('short_name', $shortName)
                ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
                ->exists();

            if ($collides) {
                $errors[] = "Line {$line}: short name belongs to a different person already on the roster — resolve this in the source file before importing.";
            }
        }

        if ($email !== null) {
            $collides = Person::withTrashed()
                ->where('email', $email)
                ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
                ->exists();

            if ($collides) {
                $errors[] = "Line {$line}: email address belongs to a different person already on the roster — resolve this in the source file before importing.";
            }
        }

        return $errors;
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

    /**
     * Leaves `position_changed` on `$row` rather than auditing here — `commit()` writes the
     * `user_role_change` row itself, AFTER the transaction commits (review finding 6).
     *
     * @param  array<string, mixed>  $row
     */
    private static function applyCreate(array &$row, ?int $actorId): void
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

        PositionChange::applyWithoutAudit($person, (int) $values['position_id']);
        // A brand-new person has no PRIOR position to compare against — every create is a
        // genuine assignment, never "unchanged" (unlike an update, below).
        $row['position_changed'] = true;

        if ($values['level_id'] !== null) {
            LevelAssignment::assign($person, Level::find($values['level_id']), Calendar::todayYmd(), [
                'actor' => $actorId,
                'reason' => 'roster import',
            ]);
        }

        $row['matched_person_id'] = (int) $person->getKey();
    }

    /**
     * Leaves `position_changed` on `$row` rather than auditing here — see `applyCreate()`'s own
     * note and `commit()`'s post-transaction audit loop (review finding 6).
     *
     * @param  array<string, mixed>  $row
     */
    private static function applyUpdate(array &$row, ?int $actorId): void
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

        // Read BEFORE the write below — this is the person's position as it stood before this
        // row touched it, which is what makes "did this import actually change the role" an
        // honest question rather than always comparing a value against itself.
        $before = (int) $person->position;
        $target = (int) $values['position_id'];

        PositionChange::applyWithoutAudit($person, $target);
        $row['position_changed'] = $before !== $target;

        if ($values['level_id'] !== null) {
            LevelAssignment::assign($person, Level::find($values['level_id']), Calendar::todayYmd(), [
                'actor' => $actorId,
                'reason' => 'roster import',
            ]);
        }
    }
}
