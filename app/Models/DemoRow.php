<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row the demo department created (Munawib ST-05, P1e Decision F): which table, which id,
 * which batch. Provenance as a join rather than as a `demo` boolean on nine tables — a column does
 * not enumerate, and "what did the demo create?" is exactly the question removal has to answer.
 *
 * WRITTEN ONLY BY `App\Support\Demo\DemoLedger`, proved at source level by
 * `DemoRowsAreLedgeredTest`. The stake is unusually direct for a single-writer rule: a demo row
 * created without a ledger entry is not a slightly-wrong row, it is a row that survives removal
 * permanently, in a live instance, indistinguishable from a real one forever.
 *
 * NO `institution_id` (D11 — and `units` has no such column to group by even if it were allowed),
 * no foreign key on `row_id` (it points at nine different tables), no soft delete, and no
 * `updated_at`: a ledger row is written once and deleted once and is never edited. See the
 * migration's docblock for each of those in full.
 *
 * THIS MODEL HAS NO FACTORY, deliberately. A ledger entry is meaningful only beside the row it
 * names, so a fixture minting one on its own would describe a state the writer cannot produce.
 */
class DemoRow extends Model
{
    /**
     * Written once, deleted once, never edited — so there is no `updated_at` column and Eloquent
     * must be told, or every insert names one the table does not have.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'batch_id',
        'table_name',
        'row_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'row_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
