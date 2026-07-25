<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHICH chain algorithm each audit row was written with.
 *
 * The chain moved from an unkeyed sha256 to a keyed HMAC, because every input to the old
 * one lived in the table itself — so database write access was enough to rewrite history
 * and still pass `audit:verify`.
 *
 * Additive and nullable, per the project's migration rule. NULL means "written before the
 * keyed chain existed" and those rows keep verifying under the original algorithm: a
 * security improvement must not retroactively declare correctly-recorded history invalid,
 * or the hourly verification starts crying wolf and stops being read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->unsignedTinyInteger('hash_version')->nullable()->after('hash');
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropColumn('hash_version');
        });
    }
};
