<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations — the replacement for self-registration open to the internet.
 *
 * `/register` accepted anyone. The compensating control was that an administrator had to
 * approve the resulting pending row, which is real, but it left an unauthenticated endpoint
 * on the public internet that wrote to the database and sent mail on a stranger's say-so,
 * and it made the queue itself a nuisance surface. It is also the control the unit-scoping
 * deviation in docs/COMPLIANCE.md leans on, so strengthening it strengthens that too.
 *
 * The design decisions worth stating:
 *
 *  - THE TOKEN IS STORED HASHED. It is a bearer credential that creates an account, so a
 *    database read must not yield a working invitation — the same reason password reset
 *    tokens are hashed. It is shown exactly once, to the inviter.
 *  - THE ROLE TRAVELS WITH THE INVITATION, not with the form. The invitee chooses their
 *    name and password; they never choose their position, so there is no request in which
 *    a self-declared role could be trusted.
 *  - THE ADDRESS IS FIXED. An invitation is for one person at one address, so accepting it
 *    proves control of that inbox — which is precisely what email verification proved, and
 *    is why an accepted invitation needs no second confirmation step.
 *  - IT EXPIRES AND IS SINGLE USE, and both are enforced at redemption rather than by a
 *    sweep, so a stalled cleanup job can never leave a live credential behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();

            // Who it is for, and what they will be. Both decided by the inviter.
            $table->string('member_email');
            $table->unsignedTinyInteger('position');

            // sha256 of the token. Never the token itself.
            $table->string('token_hash', 64)->unique();

            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');

            // Redemption and revocation are recorded rather than deleted: who was invited by
            // whom, and what became of it, is exactly the question an auditor asks.
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The open-invitations lookup, and the uniqueness check on invite.
            $table->index(['member_email', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
