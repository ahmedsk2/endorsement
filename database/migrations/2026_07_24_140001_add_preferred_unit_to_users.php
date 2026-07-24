<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.1 — one-tap access. Remembers the last unit whose sheet the user opened, so the
 * installed PWA's /endorsement/today lands straight on their unit's current sheet.
 * Additive and nullable, per the schema governance rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('preferred_unit_id')->nullable()->after('position')
                ->constrained('units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_unit_id');
        });
    }
};
