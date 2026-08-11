<?php

namespace Tests\Feature\Build;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * P1c-2 Decision F: `App\Support\CapabilityGrant` is the ONLY thing that reads or writes a
 * per-account capability override. Same species as `RotaWritersAreSingularTest`,
 * `InvitationWritersAreSingularTest`, `PersonActiveHasOneWriterTest` and
 * `AccountLinkHasOneWriterTest` — a deliberately coarse substring scan whose job is to be a
 * stop-sign a future change trips over, not a static analyser.
 *
 * WHY THIS TABLE NEEDS ONE. AC-04 gives the same act TWO surfaces — Admin -> Access Control
 * addresses an account, Admin -> People addresses a person — and a second screen is exactly how
 * a second, subtly different writer gets born. Setting overrides is four steps that must travel
 * together, and a copy gets three of them right:
 *
 *  - The submitted capability ids arrive as array KEYS, which no wildcard validation rule
 *    reaches, so they are checked against the catalog explicitly. A surface that forgot writes a
 *    row for a capability that does not exist.
 *  - The diff is computed INSIDE the transaction. Read before it, and two administrators saving
 *    at once each audit a change the other made.
 *  - `AccessControl::flush()` runs for that account, or the resolved set is served from cache
 *    for up to CACHE_TTL and the screen disagrees with the database about who may do what.
 *  - The audit rows are written AFTER the commit, because `AuditLog::record()` locks the
 *    hash-chain tail in its own transaction.
 *
 * A second writer that grants correctly and forgets the flush looks completely correct in
 * review, and the failure surfaces ten minutes later on somebody else's screen.
 *
 * `tests/` IS NOT SCANNED; `database/` IS, factories AND seeders included (review F9) — see
 * `InvitationWritersAreSingularTest`'s docblock for why the code was right and this prose was
 * wrong. It matters more here than in either sibling: `AccessControlSeeder` runs in PRODUCTION,
 * on every deploy, so "database/ is test scaffolding" was not merely inaccurate about this guard,
 * it was inaccurate about this table.
 */
class CapabilityWritersAreSingularTest extends TestCase
{
    /** Every file allowed to touch a per-account capability override, with why. */
    private const ALLOW_LIST = [
        // The one writer, and the one projection: catalog, per-account overrides, per-person
        // overrides for the roster screen, and `applyForUser()` itself (Decision F).
        'app/Support/CapabilityGrant.php',
        // The RESOLVER. `capabilitiesFor()`/`resolve()` and `holdersOf()` read the override rows
        // through a raw builder; neither writes one, and owner decision 2 keeps them untouched by
        // AC-04 on purpose. The needle set below is coarse enough that it cannot tell a read from
        // a write over a raw builder, and that is the trade this guard deliberately takes: naming
        // one reader in an allow-list is cheaper than a needle narrow enough to miss a writer.
        'app/Support/AccessControl.php',
    ];

    /**
     * WRITE-SHAPED where the model is named, because `UserCapability::` on its own is also how
     * `User::userCapabilities()` and `Capability::userCapabilities()` DECLARE their relations
     * (`hasMany(UserCapability::class)`), and naming both models here would allow-list two files
     * that have no business writing this table.
     *
     * `UserCapability::where(` and `UserCapability::query(` are included even though they are
     * usually reads: a delete is `::where(...)->delete()` and an update is `::where(...)->update()`,
     * neither of which any narrower needle can see across the line breaks a fluent chain puts in.
     * The cost is that a plain READ elsewhere would also be named — which is the intended answer,
     * since the projection belongs in the writer too (`overridesFor()`/`overridesByPerson()`).
     *
     * `'effect' =>` is the payload shape: it catches a write assembled somewhere this list did
     * not anticipate, including through a relation or a raw upsert.
     *
     * OF THE THREE SHAPES REVIEW F8 FOUND MISSING, this set already had one and a half:
     *
     *  - RELATION WRITES were covered, and deliberately at the widest possible point.
     *    `->userCapabilities()->` matches every call on that relation — `updateOrCreate`,
     *    `firstOrCreate`, `save`, `delete`, `sync` — rather than enumerating five spellings and
     *    missing the sixth. Nothing to add, and the wideness is the reason.
     *  - `find()` THEN `destroy()`/`delete()` was HALF covered: `UserCapability::where(` and
     *    `::query(` see `->delete()` at the end of a fluent chain, but neither sees
     *    `UserCapability::destroy($id)` or `UserCapability::find($id)->delete()`, which reach the
     *    same rows without a `where` anywhere. Revoking an override by deleting its row and
     *    forgetting `AccessControl::flush()` leaves the account holding a capability the database
     *    says it lost, for up to CACHE_TTL — the failure this guard is entirely about.
     *  - PROPERTY ASSIGNMENT was missed completely. `$row->effect = 'grant';` then `->save()`
     *    matches no array-key needle, and `effect` is the one column whose VALUE is the decision.
     *
     * Each new needle was proved by planting a writer of exactly its shape and watching this test
     * name the file, then reverting.
     *
     * `->effect = ` keeps its trailing space for `AccountLinkHasOneWriterTest`'s reason: the bare
     * form also matches `->effect === 'deny'`, which is a read.
     */
    private const NEEDLES = [
        'UserCapability::create(',
        'UserCapability::updateOrCreate(',
        'UserCapability::firstOrCreate(',
        'UserCapability::insert(',
        'UserCapability::upsert(',
        'UserCapability::query(',
        'UserCapability::where(',
        'UserCapability::find(',
        'UserCapability::destroy(',
        'UserCapability::truncate(',
        'new UserCapability(',
        '->userCapabilities()->',
        // A raw builder over the overrides table can write any column of it without matching any
        // of the shapes above.
        "DB::table('user_capabilities')",
        'DB::table("user_capabilities")',
        "'effect' =>",
        '"effect" =>',
        '->effect = ',
    ];

    public function test_only_the_capability_writer_touches_per_account_overrides(): void
    {
        $offenders = [];

        foreach ([app_path(), base_path('database'), base_path('routes')] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()));

                if (in_array($relative, self::ALLOW_LIST, true)) {
                    continue;
                }

                $contents = (string) File::get($file->getPathname());

                foreach (self::NEEDLES as $needle) {
                    if (str_contains($contents, $needle)) {
                        $offenders[] = $relative.' contains '.$needle;
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            'Per-account capability overrides must be written only by App\\Support\\CapabilityGrant '.
            "(P1c-2 Decision F). AC-04 gave this act a second SURFACE, not a second writer.\n"
            .implode("\n", $offenders));
    }

    /** A stale allow-list is a silently disabled guard. */
    public function test_every_allow_listed_file_still_exists(): void
    {
        foreach (self::ALLOW_LIST as $relative) {
            $this->assertFileExists(base_path($relative), "Allow-listed file {$relative} is gone — prune the list.");
        }
    }
}
