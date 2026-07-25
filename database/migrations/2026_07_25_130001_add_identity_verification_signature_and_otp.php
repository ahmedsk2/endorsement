<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account-assurance additions (owner request, 2026-07-25):
 *
 *  - EMAIL VERIFICATION. `pending_registrations.email_verified_at` gates approval (an
 *    administrator should not activate an address nobody proved they own);
 *    `users.email_verified_at` covers accounts that already exist.
 *  - SECOND FACTOR BY CHOICE. `users.two_factor_method` is null | 'totp' | 'email'.
 *    The existing encrypted TOTP columns stay exactly as they are; 'email' issues a
 *    one-time code instead (login_otps).
 *  - HANDWRITTEN SIGNATURE. `users.signature_path` points at a content-addressed PNG in
 *    private storage (never public). Files are immutable: changing a signature writes a
 *    NEW file, so a sheet signed last week keeps the signature that was actually used.
 *  - The sign-off SNAPSHOTS the signature path alongside the frozen names, for the same
 *    medico-legal reason: a later signature change must not rewrite a signed sheet.
 *
 * Additive and nullable throughout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('member_email');
            // null = no second factor chosen yet; 'totp' = authenticator app; 'email' = OTP by mail.
            $table->string('two_factor_method')->nullable()->after('two_factor_confirmed_at');
            $table->string('signature_path')->nullable()->after('two_factor_method');
            $table->timestamp('signature_updated_at')->nullable()->after('signature_path');
        });

        Schema::table('pending_registrations', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('member_email');
        });

        /*
         * One live e-mail OTP per user. The code is stored HASHED — a database read must not
         * yield a usable second factor, exactly as the TOTP secret is encrypted. Rows are
         * consumed on use and replaced on re-request; `attempts` caps guessing.
         */
        Schema::create('login_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::table('handover_signoffs', function (Blueprint $table) {
            // Frozen at sign-off next to *_name, for the same reason.
            $table->string('endorsed_by_signature_path')->nullable()->after('endorsed_by_name');
            $table->string('endorsed_to_signature_path')->nullable()->after('endorsed_to_name');
        });
    }

    public function down(): void
    {
        Schema::table('handover_signoffs', function (Blueprint $table) {
            $table->dropColumn(['endorsed_by_signature_path', 'endorsed_to_signature_path']);
        });

        Schema::dropIfExists('login_otps');

        Schema::table('pending_registrations', function (Blueprint $table) {
            $table->dropColumn('email_verified_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_verified_at', 'two_factor_method', 'signature_path', 'signature_updated_at']);
        });
    }
};
