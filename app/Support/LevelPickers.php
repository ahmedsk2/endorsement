<?php

namespace App\Support;

use App\Models\Level;
use Illuminate\Database\Eloquent\Builder;

/**
 * WHICH levels LV-02's bulk "set level" picker may offer, and validate against — one predicate,
 * the 2026-07-26 audit's "offer/validate from the same query" discipline (already applied to
 * sign-off pickers by `App\Support\SignoffPickers` and to the promotion picker by
 * `PromotionController::offerableLevels()`), restated here because review finding 5 found the
 * bulk picker had drifted from it: `PersonBulkRequest` validated with `Rule::exists('levels',
 * 'id')` outright — which accepts a RETIRED level — while `PersonController::rosterProps()`
 * offered only active ones.
 *
 * Deliberately NOT the same predicate as `PromotionController::offerableLevels()`
 * (`internal()->active()->ordered()`, EXT excluded — Decision D point 2, the training ladder
 * specifically). This tool is a general correction, so EXT stays offerable: a person may
 * legitimately be marked at the external level through it.
 */
final class LevelPickers
{
    /** @return Builder<Level> */
    public static function bulkAssignable(): Builder
    {
        return Level::query()->active()->ordered();
    }
}
