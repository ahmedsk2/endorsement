<?php

namespace App\Support\Rota;

/**
 * WHAT A PREVIEW-THEN-CONFIRM COMMIT IS PINNED TO, defined ONCE for the two bulk writers on
 * `master_rota_assignments` (P1d-2 Tasks 8 and 11, and the slice review that found the second one
 * missing it). `RotaFill::digest()` and `RotaImport::stateDigest()` are the two consumers; neither
 * re-types the rule, in the same way `PeriodGenerator::assertMonthAligned()` and
 * `AuditChain::canonical()` are single definitions with two callers each.
 *
 * THE PROBLEM IT SOLVES. Both writers re-derive their whole analysis inside their own transaction
 * and trust nothing the client sent — which is necessary and NOT sufficient. Re-deriving means the
 * commit computes a FRESH answer, so a rota that moved between the preview and the confirm is
 * applied as whatever it now says rather than as what the operator approved. For the fill that
 * shows up as a colleague's new assignment being overwritten; for the import it showed up as a
 * cell previewed `UNCHANGED` (or skipped entirely, with `spans: []` on screen) committing as a
 * `REPLACE` that deleted both halves of a deliberate split. The bytes of an uploaded file cannot
 * see any of that, by construction: they did not change.
 *
 * SO THE PIN IS OVER THE STATE PROJECTION — for every cell the operation touches, its identity,
 * what it CURRENTLY holds, and what would be WRITTEN over it. Any change to the rota that would
 * change what the operation does changes one of those three, and so changes this hash.
 *
 * TWO THINGS ARE DELIBERATELY OUT OF IT, and the reasoning is the point.
 *
 *  - **A cell's `outcome` and its `reason`**, because they are a pure function of that state AND
 *    of the operator's own answers (a fill's per-cell confirmations). Including them would mean
 *    every tick of a confirm box invalidated the pin, forcing a re-preview round trip per tick and
 *    making a master "overwrite all splits" control impossible to build. The pin exists to catch
 *    the WORLD moving, not the operator deciding.
 *  - **The confirmations themselves**, for the same reason. They are already explicit, per cell, in
 *    the request body the preview was rendered from.
 *
 * A change invisible to this hash is therefore a change that alters no cell's current or proposed
 * content — one skip reason becoming another, say — and by definition writes nothing different.
 *
 * IT IS NOT A SUBSTITUTE FOR THE BYTE PIN AND DOES NOT REPLACE IT. `RotaImport` carries both: the
 * bytes answer "is this the file you looked at" and this answers "is this the rota you looked at".
 * They are two different operator actions with two different fixes — re-export the file, or
 * re-preview it — so they are two refusals of two types, never one message covering both.
 */
final class StatePin
{
    /**
     * @param  string  $scope  what this pin belongs to — the operation or the file kind. Two
     *                         different operations over identical cells are two different pins.
     * @param  array<string, mixed>  $identity  the operation's own inputs, if it has any beyond the
     *                                          cells (a fill's source cell; an import has none —
     *                                          its input is the file, pinned separately by bytes)
     * @param  list<string>  $errors  the whole-operation refusals, which are part of what the
     *                                operator was shown
     * @param  list<array{0: int|null, 1: int|null, 2: list<array<string, mixed>>, 3: list<array<string, mixed>>}>  $cells
     *                                person id, period id (null where the concept does not apply),
     *                                what the cell holds now, what would be written
     */
    public static function of(string $scope, array $identity, array $errors, array $cells): string
    {
        return hash('sha256', (string) json_encode([
            'scope' => $scope,
            'identity' => $identity,
            'errors' => $errors,
            'cells' => $cells,
        ]));
    }
}
