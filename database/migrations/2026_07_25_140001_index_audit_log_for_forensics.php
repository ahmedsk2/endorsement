<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An audit trail nobody can query in time is not an audit trail. The forensic questions
 * are always "what happened between these two timestamps" and "what did this account do",
 * and both were full table scans once the log grows past a few hundred thousand rows.
 *
 * Additive indexes only — no column or row is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->index('created_at', 'audit_log_created_at_index');
            $table->index(['user_id', 'created_at'], 'audit_log_user_created_index');
            $table->index(['action', 'created_at'], 'audit_log_action_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropIndex('audit_log_created_at_index');
            $table->dropIndex('audit_log_user_created_index');
            $table->dropIndex('audit_log_action_created_index');
        });
    }
};
