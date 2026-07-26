<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each account was last actually used.
 *
 * Deprovisioning currently depends entirely on somebody remembering. There is no dormancy
 * sweep, no rotation-end date, and no periodic access review — and paediatric residents
 * rotate on a schedule while the account roster does not. Revocation itself is excellent
 * (EnsureAccountActive kills a session on the very next request); what is missing is the
 * trigger for it.
 *
 * This is the smallest thing that makes the gap visible: an administrator can see who has
 * not signed in for months and act. Additive and nullable, per the migration rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('pass_exp_date');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
