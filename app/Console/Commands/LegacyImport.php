<?php

namespace App\Console\Commands;

use App\Models\Handover;
use App\Models\HandoverSignoff;
use App\Models\Person;
use App\Support\Plausibility;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One-way, read-only-against-the-source, idempotent import of the legacy endorsement data
 * (spec §12) — ALL FOUR unit table pairs:
 *
 *   handover rows     patintsendorcement            → handovers (unit PICU)
 *                     nicu_patintsendorcement (+dob) → handovers (unit NICU)
 *                     scbu_patintsendorcement (+dob) → handovers (unit SCBU)
 *                     ward_patintsendorcement (+age, unit→ward_unit) → handovers (unit WARD)
 *   day headers       endorsement / nicu_ / scbu_ (consultant by/to)
 *                     ward_endorsement (consultantoncall → consultant_by_name, ruling 5)
 *                                                    → handover_signoffs
 *   accounts          members                        → users (bcrypt hashes VERBATIM)
 *
 * LOSSLESS (ruling 7): every row is keyed on (legacy_source_table, legacy_id) provenance,
 * so the ~2.5k legacy rows that collide on the (date, mrn, bed) natural key import as
 * distinct rows, re-runs upsert instead of duplicating, and rows born in THIS app (null
 * provenance) are never touched.
 *
 * Data rules: '0000-00-00' and '1970-01-01' dates → null (the row itself is kept);
 * zero-date dobs → null; missing-value tokens ('', '-', 'nill', …) → null; rich text is
 * sanitized by the model cast exactly as a screen write would be. Day headers with no
 * parseable date are SKIPPED (nothing to attach them to) and counted as an expected
 * divergence. Output is counts only — never PHI, never hashes.
 *
 * This command deliberately BYPASSES `App\Support\SignoffPickers` (D9). A historical sign-off
 * legitimately names a person who no longer qualifies as an endorser today — retired, no longer
 * an account holder, moved off the roster — and "fixing" that on import would rewrite the
 * medico-legal record of who actually endorsed the day. D9's offer/validation parity governs
 * NEW writes only.
 */
class LegacyImport extends Command
{
    protected $signature = 'legacy:import {--only= : Comma-separated sections (users,endorsement)}';

    protected $description = 'Import the legacy endorsement data (four units) from the read-only legacy connection';

    /** Canonical section order. */
    private const SECTIONS = ['users', 'endorsement'];

    /**
     * Legacy row-table → [unit code, extra column map (legacy → handovers)].
     *
     * @var array<string, array{0: string, 1: array<string, string>}>
     */
    private const ROW_SOURCES = [
        'patintsendorcement' => ['PICU', []],
        'nicu_patintsendorcement' => ['NICU', ['dob' => 'dob']],
        'scbu_patintsendorcement' => ['SCBU', ['dob' => 'dob']],
        'ward_patintsendorcement' => ['WARD', ['age' => 'age', 'unit' => 'ward_unit']],
    ];

    /**
     * Legacy day-header table → [unit code, consultant shape ('pair' | 'oncall')].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const HEADER_SOURCES = [
        'endorsement' => ['PICU', 'pair'],
        'nicu_endorsement' => ['NICU', 'pair'],
        'scbu_endorsement' => ['SCBU', 'pair'],
        'ward_endorsement' => ['WARD', 'oncall'],
    ];

    /** @var list<array{string, int, int, bool}> [table, legacy, imported, match] */
    private array $reconciliation = [];

    public function handle(): int
    {
        $sections = $this->resolveSections();

        if ($sections === null) {
            return self::FAILURE;
        }

        $legacy = DB::connection(config('legacy.connection', 'legacy'));

        $failed = false;

        foreach ($sections as $section) {
            try {
                match ($section) {
                    'users' => $this->importUsers($legacy),
                    'endorsement' => $this->importEndorsement($legacy),
                };
            } catch (Throwable $e) {
                // The section's transaction has already rolled back; report and continue.
                $failed = true;
                $this->error("Section [{$section}] failed and was rolled back: {$e->getMessage()}");
            }
        }

        $this->renderReconciliation();
        $this->writeReconciliationDoc();

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<string>|null */
    private function resolveSections(): ?array
    {
        $only = $this->option('only');

        if ($only === null || trim((string) $only) === '') {
            return self::SECTIONS;
        }

        $requested = array_values(array_filter(array_map('trim', explode(',', (string) $only))));
        $unknown = array_diff($requested, self::SECTIONS);

        if ($unknown !== []) {
            $this->error('Unknown section(s): '.implode(', ', $unknown).'. Valid: '.implode(', ', self::SECTIONS).'.');

            return null;
        }

        return array_values(array_filter(self::SECTIONS, fn ($s) => in_array($s, $requested, true)));
    }

    /** Legacy `members` → `users`. Bcrypt hashes are copied VERBATIM — never rehashed. */
    private function importUsers(ConnectionInterface $legacy): void
    {
        $institutionId = DB::table('institutions')->min('id');

        if ($institutionId === null) {
            throw new \RuntimeException('No institution seeded; run the ReferenceSeeder before importing users.');
        }

        $now = Carbon::now()->toDateTimeString();

        DB::transaction(function () use ($legacy, $institutionId, $now) {
            $legacy->table('members')->orderBy('member_id')->chunk(500, function ($rows) use ($institutionId, $now) {
                foreach ($rows as $r) {
                    // Position 1 (Nurse) is RETIRED in this system (owner ruling,
                    // 2026-07-25): nurses have no endorsement surface and are never
                    // referenced by sign-offs (endorsers are residents), so their
                    // accounts are not imported at all.
                    if ((int) ($r->position ?? 1) === 1) {
                        continue;
                    }

                    $email = Person::normalizeEmail(Plausibility::cleanMissing($r->member_email ?? null));

                    // The account, if this member has been imported before (idempotent re-run).
                    $existingUser = DB::table('users')->where('member_name', (string) $r->member_name)->first();

                    // Match onto an existing PERSON before creating one: an imported roster and a
                    // legacy members table describe the same humans, and `people.email` is unique,
                    // so a blind insert would either 23000-crash mid-import or duplicate a person.
                    // P0c pulled this forward from Task 7 — Task 2's read-through accessors need
                    // every `users` row to carry a `person_id`, and this command minted rows
                    // without one.
                    $personId = $existingUser->person_id
                        ?? (Person::matchByEmail($email)?->getKey());

                    $personAttributes = [
                        'institution_id' => $institutionId,
                        'full_name' => (string) ($r->full_name ?? $r->member_name),
                        'position' => $r->position === null ? 1 : (int) $r->position,
                        'email' => $email,
                        'active' => (bool) $r->active,
                        'updated_at' => $now,
                    ];

                    if ($personId === null) {
                        $personId = DB::table('people')->insertGetId(
                            $personAttributes + ['external' => false, 'created_at' => $now]
                        );
                    } else {
                        DB::table('people')->where('id', $personId)->update($personAttributes);
                    }

                    // Query-builder upsert on purpose: the model's 'hashed' cast would
                    // re-hash the already-bcrypt legacy hash and lock everyone out.
                    DB::table('users')->upsert([[
                        'person_id' => $personId,
                        'member_name' => (string) $r->member_name,
                        'member_email' => Plausibility::cleanMissing($r->member_email ?? null),
                        'active' => (bool) $r->active,
                        'pass_exp_date' => $this->cleanDate($r->pass_exp_date ?? null),
                        'password' => (string) $r->member_password,
                        'institution_id' => $institutionId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]], ['member_name'], [
                        'person_id', 'member_email', 'active', 'pass_exp_date',
                        'password', 'institution_id', 'updated_at',
                    ]);
                }
            });
        });

        // Nurses are excluded on both sides of the comparison (retired role, not imported).
        $legacyNonNurseCount = (int) $legacy->table('members')->where('position', '!=', 1)->count();
        $memberNames = $legacy->table('members')->where('position', '!=', 1)->pluck('member_name');

        $this->recordCount(
            'users',
            $legacyNonNurseCount,
            (int) DB::table('users')->whereIn('member_name', $memberNames)->count(),
        );

        // A member can match onto an ALREADY-EXISTING person (Person::matchByEmail) instead of
        // creating one, so the distinct linked-people count can legitimately be lower than the
        // legacy member count — that is a real merge, not a bug, and shows as an expected
        // divergence in the reconciliation table rather than silently disappearing.
        $this->recordCount(
            'people',
            $legacyNonNurseCount,
            DB::table('users')->whereIn('member_name', $memberNames)->pluck('person_id')->unique()->count(),
        );
    }

    /** All four unit table pairs → handovers + handover_signoffs. */
    private function importEndorsement(ConnectionInterface $legacy): void
    {
        $institutionId = DB::table('institutions')->min('id');

        if ($institutionId === null) {
            throw new \RuntimeException('No institution seeded; run the ReferenceSeeder before importing endorsement.');
        }

        foreach (self::ROW_SOURCES as $sourceTable => [$code, $extraMap]) {
            if (! Schema::connection($legacy->getName())->hasTable($sourceTable)) {
                continue;
            }

            $unitId = DB::table('units')->where('code', $code)->value('id');

            if ($unitId === null) {
                throw new \RuntimeException("Unit [{$code}] not seeded; run the ReferenceSeeder first.");
            }

            $this->importRowsFor($legacy, $sourceTable, (int) $unitId, $code, $extraMap, (int) $institutionId);
        }

        foreach (self::HEADER_SOURCES as $sourceTable => [$code, $shape]) {
            if (! Schema::connection($legacy->getName())->hasTable($sourceTable)) {
                continue;
            }

            $unitId = (int) DB::table('units')->where('code', $code)->value('id');
            $this->importSignoffsFor($legacy, $sourceTable, $unitId, $code, $shape, (int) $institutionId);
        }
    }

    /** @param array<string, string> $extraMap */
    private function importRowsFor(
        ConnectionInterface $legacy,
        string $sourceTable,
        int $unitId,
        string $code,
        array $extraMap,
        int $institutionId,
    ): void {
        $datesNulled = 0;

        DB::transaction(function () use ($legacy, $sourceTable, $unitId, $extraMap, $institutionId, &$datesNulled) {
            $legacy->table($sourceTable)->orderBy('ID')->chunk(500, function ($rows) use ($sourceTable, $unitId, $extraMap, $institutionId, &$datesNulled) {
                foreach ($rows as $r) {
                    $rawDate = $r->STAYDATE ?? null;
                    $date = $this->cleanDate($rawDate);

                    if ($date === null && Plausibility::cleanMissing($rawDate) !== null) {
                        $datesNulled++;
                    }

                    $values = [
                        'institution_id' => $institutionId,
                        'unit_id' => $unitId,
                        'handover_date' => $date,
                        'bed' => Plausibility::cleanMissing($r->BED ?? null),
                        'mrn' => Plausibility::cleanMissing($r->MRN ?? null),
                        'patient_name' => Plausibility::cleanMissing($r->PNAME ?? null),
                        // Rich text: sanitized by the SanitizedHtml cast on save.
                        'disease' => $r->DISEASE ?? null,
                        'details' => $r->DETAILS ?? null,
                        'plan' => $r->PLAN ?? null,
                        'nevent' => $r->nevent ?? null,
                    ];

                    foreach ($extraMap as $legacyColumn => $column) {
                        $raw = $r->{$legacyColumn} ?? null;
                        $values[$column] = $column === 'dob'
                            ? $this->cleanDateTime($raw)
                            : Plausibility::cleanMissing($raw);
                    }

                    // LOSSLESS idempotency: provenance is the key, never the natural key.
                    $existing = Handover::withTrashed()
                        ->where('legacy_source_table', $sourceTable)
                        ->where('legacy_id', (int) $r->ID)
                        ->first();

                    if ($existing !== null) {
                        $existing->fill($values)->save();
                    } else {
                        Handover::create($values + [
                            'legacy_source_table' => $sourceTable,
                            'legacy_id' => (int) $r->ID,
                        ]);
                    }
                }
            });
        });

        $this->recordCount(
            "handovers:{$code}",
            (int) $legacy->table($sourceTable)->count(),
            (int) Handover::withTrashed()->where('legacy_source_table', $sourceTable)->count(),
        );

        if ($datesNulled > 0) {
            // Zero/epoch dates nulled but rows KEPT — informational, always a match.
            $this->recordCount("handovers:{$code} dates_nulled", $datesNulled, $datesNulled);
        }
    }

    /**
     * A legacy day header → one signed handover_signoffs row. Historical sign-offs are
     * treated as SIGNED (legacy had no draft state) with a NULL signer, which marks an
     * imported attestation apart from one signed in this application. Headers with no
     * parseable date are skipped and counted.
     */
    private function importSignoffsFor(
        ConnectionInterface $legacy,
        string $sourceTable,
        int $unitId,
        string $code,
        string $shape,
        int $institutionId,
    ): void {
        // legacy member_id → [people.id, full_name]. Small table (~80 rows); resolved once.
        // Resolution goes member_name → users → users.person_id, NOT member_name → people,
        // because the legacy identity IS the login handle and only `users` carries it.
        $members = [];

        if (Schema::connection($legacy->getName())->hasTable('members')) {
            $personIdByMemberName = DB::table('users')->pluck('person_id', 'member_name');

            foreach ($legacy->table('members')->get(['member_id', 'member_name', 'full_name']) as $m) {
                $members[(string) $m->member_id] = [
                    'person_id' => $personIdByMemberName[(string) $m->member_name] ?? null,
                    'name' => $m->full_name === null ? null : (string) $m->full_name,
                ];
            }
        }

        $resolve = fn (mixed $memberId): array => $members[(string) $memberId] ?? ['person_id' => null, 'name' => null];

        $skipped = 0;

        DB::transaction(function () use ($legacy, $sourceTable, $unitId, $shape, $institutionId, $resolve, &$skipped) {
            $legacy->table($sourceTable)->orderBy('ID')->chunk(500, function ($rows) use ($sourceTable, $unitId, $shape, $institutionId, $resolve, &$skipped) {
                foreach ($rows as $r) {
                    $date = $this->cleanDate($r->Dates ?? null);

                    // A header with no date cannot be attached to a handover day.
                    if ($date === null) {
                        $skipped++;

                        continue;
                    }

                    $by = $resolve($r->endorsedby ?? null);
                    $to = $resolve($r->endorsedto ?? null);

                    $time = Plausibility::cleanMissing($r->time ?? null);

                    $values = [
                        'institution_id' => $institutionId,
                        'unit_id' => $unitId,
                        'handover_date' => $date,
                        // *_user_id is the FROZEN legacy column (D9 wire rename, finding 3) —
                        // deliberately left unset here, never written by this import.
                        'endorsed_by_person_id' => $by['person_id'],
                        'endorsed_by_name' => $by['name'],
                        'endorsed_to_person_id' => $to['person_id'],
                        'endorsed_to_name' => $to['name'],
                        // Verbatim display label + derived minutes (one parser everywhere).
                        'endorsement_time' => $time,
                        'endorsement_time_minutes' => $time === null ? null : HandoverSignoff::parseTimeToMinutes($time),
                        // Imported attestations are signed (historically final -> locked),
                        // with a NULL signer marking them as imported.
                        'signed_off_at' => $date.' 00:00:00',
                        'signed_off_by_user_id' => null,
                    ];

                    if ($shape === 'oncall') {
                        // Ruling 5 — WARD's single consultantoncall lands in consultant_by_*.
                        $values['consultant_by_name'] = Plausibility::cleanMissing($r->consultantoncall ?? null);
                        $values['consultant_to_name'] = null;
                    } else {
                        // Legacy free text — no account to resolve against, and no person
                        // either. Historical consultant fields are a typed name and stay one;
                        // D9's wider consultant scope applies to NEW writes, not to what legacy
                        // recorded.
                        $values['consultant_by_name'] = Plausibility::cleanMissing($r->consultantby ?? null);
                        $values['consultant_to_name'] = Plausibility::cleanMissing($r->consultantto ?? null);
                    }

                    $existing = HandoverSignoff::withTrashed()
                        ->where('legacy_source_table', $sourceTable)
                        ->where('legacy_id', (int) $r->ID)
                        ->first();

                    // Provenance first; the (unit, date) unique index is the fallback for a
                    // row imported before provenance existed.
                    $existing ??= HandoverSignoff::where('unit_id', $unitId)
                        ->whereDate('handover_date', $date)
                        ->whereNotNull('legacy_id')
                        ->first();

                    if ($existing !== null) {
                        $existing->fill($values)->save();
                    } else {
                        HandoverSignoff::create($values + [
                            'legacy_source_table' => $sourceTable,
                            'legacy_id' => (int) $r->ID,
                        ]);
                    }
                }
            });
        });

        $this->recordCount(
            "handover_signoffs:{$code}",
            (int) $legacy->table($sourceTable)->count() - $skipped,
            (int) HandoverSignoff::withTrashed()->where('legacy_source_table', $sourceTable)->count(),
        );

        if ($skipped > 0) {
            $this->recordCount("handover_signoffs:{$code} skipped_no_date", $skipped, $skipped);
        }
    }

    private function recordCount(string $table, int $legacy, ?int $imported = null): void
    {
        $imported ??= $legacy;
        $this->reconciliation[] = [$table, $legacy, $imported, $legacy === $imported];
    }

    /** Clean a legacy datetime: missing tokens / zero-dates / epoch garbage / unparseable → null. */
    private function cleanDateTime(mixed $value): ?string
    {
        $clean = Plausibility::cleanMissing($value);

        if ($clean === null || str_starts_with($clean, '0000-00-00') || str_starts_with($clean, '1970-01-01')) {
            return null;
        }

        $timestamp = strtotime($clean);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    /** Clean a legacy date the same way, returning `Y-m-d` (or null). */
    private function cleanDate(mixed $value): ?string
    {
        $datetime = $this->cleanDateTime($value);

        return $datetime === null ? null : date('Y-m-d', strtotime($datetime));
    }

    /** Print the accumulated reconciliation table to the console (counts only). */
    private function renderReconciliation(): void
    {
        if ($this->reconciliation === []) {
            return;
        }

        $this->newLine();
        $this->info('Reconciliation (counts only — no PHI):');
        $this->table(
            ['table', 'legacy_count', 'imported_count', 'match'],
            array_map(
                fn ($r) => [$r[0], $r[1], $r[2], $r[3] ? '✓' : '✗'],
                $this->reconciliation,
            ),
        );
    }

    /** (Re)write the reconciliation doc with a dated section for THIS run. Counts only. */
    private function writeReconciliationDoc(): void
    {
        if ($this->reconciliation === []) {
            return;
        }

        $path = config('legacy.reconciliation_path');

        if (! is_string($path) || $path === '') {
            return;
        }

        $lines = [
            '# Legacy import reconciliation',
            '',
            'This file is **generated** by `php artisan legacy:import` (overwritten on every run).',
            'It records only row **counts** — never PHI (no patient names, MRNs, staff names, or',
            'password hashes ever appear here or in the command output).',
            '',
            'Verify without importing again: `php artisan legacy:reconcile` (read-only; exits',
            'non-zero on any unexplained divergence — the cutover gate).',
            '',
            'Rows suffixed `dates_nulled` / `skipped_no_date` are EXPECTED divergences: zero-date',
            'and epoch-garbage values nulled (rows kept), and day headers with no parseable date',
            '(nothing to attach them to).',
            '',
            '## Latest run — '.Carbon::now()->toDateTimeString(),
            '',
            '| table | legacy_count | imported_count | match |',
            '| --- | ---: | ---: | :---: |',
        ];

        foreach ($this->reconciliation as $r) {
            $lines[] = '| '.$r[0].' | '.$r[1].' | '.$r[2].' | '.($r[3] ? '✓' : '✗').' |';
        }

        $lines[] = '';

        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, implode("\n", $lines));
    }
}
