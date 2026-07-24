<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Role ids the seeder knows about (0=Administrator .. 4=Resident). */
    private const POSITIONS = [0, 1, 2, 3, 4];

    /**
     * A "default" must mean *the value when nothing has been decided*, never *the value we
     * re-impose over your decision*.
     *
     * `applied_role_defaults` records that AccessControlSeeder has already applied the role
     * default for one (position, capability) pair. The seeder consults it before writing, so:
     *
     *   - a pair NEVER seen  -> apply the default (first sight, including a capability added
     *                           to the catalog long after the first deploy);
     *   - a pair ALREADY applied -> leave role_capabilities alone, because whatever it says now
     *                           is the administrator's decision — including a deliberate
     *                           revocation, which must survive every future deploy.
     *
     * ADDITIVE ONLY: no existing role_capabilities / user_capabilities row is written, moved or
     * deleted here.
     */
    public function up(): void
    {
        Schema::create('applied_role_defaults', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('position');
            $table->foreignId('capability_id')->constrained('capabilities')->cascadeOnDelete();
            $table->timestamp('applied_at')->nullable();

            $table->unique(['position', 'capability_id']);
        });

        // Backfill for databases where the seeder has ALREADY run at least once. Every capability
        // present in the catalog right now has had its defaults applied, so mark every
        // (position, capability) pair as applied — otherwise the first post-deploy seeder run
        // would resurrect exactly the revocations this migration exists to protect.
        //
        // Consequence, stated plainly: for a capability that already exists, later WIDENING
        // ROLE_DEFAULTS in code will not take effect via the seeder (the seeder cannot tell a
        // never-applied default from a revoked one after the fact). Make that change in
        // Admin → Access Control, or in its own data migration. New capabilities are unaffected.
        $now = now();
        $rows = [];
        foreach (DB::table('capabilities')->pluck('id') as $capabilityId) {
            foreach (self::POSITIONS as $position) {
                $rows[] = [
                    'position' => $position,
                    'capability_id' => $capabilityId,
                    'applied_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('applied_role_defaults')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('applied_role_defaults');
    }
};
