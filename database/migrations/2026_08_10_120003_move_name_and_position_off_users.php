<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `full_name` and `position` now live on `people` (2026_08_10_120001) and nothing reads them here
 * any more — `User` resolves both through the person relation. Two copies of one fact is the
 * duplication CLAUDE.md blames for the audit-chain false alarm, so the dead copies go.
 *
 * REVERSIBLE ONLY WHILE `people` EXISTS. `down()` re-adds both columns and copies the values back
 * through `users.person_id`. Rolling back 2026_08_10_120001 first would drop `people` and leave
 * nothing to copy from — the runbook states the order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // `position` carries an index (0001_01_01_000000_create_users_table.php). SQLite
            // rebuilds the whole table to drop a column and does not discover on its own that an
            // index on that column must go first — dropColumn() alone throws ("error in index
            // users_position_index after drop column"). MySQL/Postgres do not need this, but
            // dropping first is harmless there too.
            $table->dropIndex(['position']);
            $table->dropColumn(['full_name', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('full_name')->nullable()->after('member_name');
            $table->unsignedTinyInteger('position')->default(1)->index()->after('full_name');
        });

        DB::statement(
            'update users set full_name = (select people.full_name from people where people.id = users.person_id) '.
            'where person_id is not null'
        );
        DB::statement(
            'update users set position = (select people.position from people where people.id = users.person_id) '.
            'where person_id is not null'
        );
    }
};
