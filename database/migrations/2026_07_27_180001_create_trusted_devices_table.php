<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devices allowed to skip the second factor for a while.
 *
 * A new table rather than a column, for the obvious reason — a clinician has a phone and a
 * ward workstation — and a hashed token rather than the token, for the same reason the
 * one-time codes are hashed: this is a bearer credential that skips an authentication step,
 * so a database read must not yield a working one.
 *
 * `user_id` is not decoration. The check MUST be scoped to the account signing in, or a
 * cookie minted for one clinician would wave a different clinician past their own second
 * factor on a shared ward workstation — which is precisely the machine this feature exists
 * to make tolerable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            // A short human label so "sign out other devices" is meaningful. Derived from the
            // user agent on the server; never free text from the client.
            $table->string('label')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
