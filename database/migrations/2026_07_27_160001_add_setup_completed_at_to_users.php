<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record that an account has been through first-login setup.
 *
 * A first login was previously indistinguishable from the hundredth. `last_login_at` looked
 * like the signal but cannot be one: it is stamped inside `Login::complete()`, during the
 * login POST itself, so by the time any middleware sees a request it is already non-null
 * and looks exactly like every subsequent visit.
 *
 * So the state is recorded explicitly. NULL means "has not finished setup", which is the
 * correct reading for every account that exists today: none of them has been asked to
 * choose a second factor or a reminder preference, and both are things this system should
 * not be guessing on a clinician's behalf.
 *
 * Additive and nullable, per the project rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('setup_completed_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('setup_completed_at');
        });
    }
};
