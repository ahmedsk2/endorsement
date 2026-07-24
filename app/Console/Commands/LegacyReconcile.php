<?php

namespace App\Console\Commands;

use App\Models\Handover;
use App\Models\HandoverSignoff;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The read-only cutover gate: recompute the per-unit legacy↔imported counts LIVE from
 * both databases and exit non-zero on any unexplained divergence. Writes nothing to
 * either database. Counts only — never PHI.
 *
 * Expected divergences are modelled, not excused ad hoc: day headers whose date is
 * unparseable ('0000-00-00' etc.) are subtracted from the legacy side, because the
 * importer skips them by design.
 */
class LegacyReconcile extends Command
{
    protected $signature = 'legacy:reconcile';

    protected $description = 'Verify the imported endorsement counts against the legacy source (read-only)';

    /** @var array<string, string> row table => unit code */
    private const ROW_SOURCES = [
        'patintsendorcement' => 'PICU',
        'nicu_patintsendorcement' => 'NICU',
        'scbu_patintsendorcement' => 'SCBU',
        'ward_patintsendorcement' => 'WARD',
    ];

    /** @var array<string, string> header table => unit code */
    private const HEADER_SOURCES = [
        'endorsement' => 'PICU',
        'nicu_endorsement' => 'NICU',
        'scbu_endorsement' => 'SCBU',
        'ward_endorsement' => 'WARD',
    ];

    public function handle(): int
    {
        $legacy = DB::connection(config('legacy.connection', 'legacy'));
        $rows = [];
        $clean = true;

        foreach (self::ROW_SOURCES as $table => $code) {
            if (! Schema::connection($legacy->getName())->hasTable($table)) {
                continue;
            }

            $expected = (int) $legacy->table($table)->count();
            $actual = (int) Handover::withTrashed()->where('legacy_source_table', $table)->count();

            $rows[] = ["handovers:{$code}", $expected, $actual, $expected === $actual];
            $clean = $clean && $expected === $actual;
        }

        foreach (self::HEADER_SOURCES as $table => $code) {
            if (! Schema::connection($legacy->getName())->hasTable($table)) {
                continue;
            }

            $expected = (int) $legacy->table($table)->count() - $this->unparseableDates($legacy, $table);
            $actual = (int) HandoverSignoff::withTrashed()->where('legacy_source_table', $table)->count();

            $rows[] = ["handover_signoffs:{$code}", $expected, $actual, $expected === $actual];
            $clean = $clean && $expected === $actual;
        }

        $this->table(
            ['table', 'legacy_count', 'imported_count', 'match'],
            array_map(fn ($r) => [$r[0], $r[1], $r[2], $r[3] ? '✓' : '✗'], $rows),
        );

        if (! $clean) {
            $this->error('Reconciliation FAILED — investigate before cutover.');

            return self::FAILURE;
        }

        $this->info('Reconciliation clean.');

        return self::SUCCESS;
    }

    /** Day headers the importer skips by design: no parseable date. */
    private function unparseableDates(ConnectionInterface $legacy, string $table): int
    {
        $skipped = 0;

        foreach ($legacy->table($table)->pluck('Dates') as $value) {
            $clean = \App\Support\Plausibility::cleanMissing($value);

            if ($clean === null || str_starts_with($clean, '0000-00-00') || str_starts_with($clean, '1970-01-01') || strtotime($clean) === false) {
                $skipped++;
            }
        }

        return $skipped;
    }
}
