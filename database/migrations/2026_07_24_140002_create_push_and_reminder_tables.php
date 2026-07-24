<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §10.2 — web-push reminders. `push_subscriptions` holds each device's VAPID
 * subscription; `reminder_preferences` is the per-user, per-unit opt-in. Both are pure
 * infrastructure: no clinical data lives in either table, and payloads composed from
 * them carry unit + date + status only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Push endpoints regularly exceed index-safe lengths on MySQL/utf8mb4, so the
            // endpoint itself is TEXT and uniqueness rides on its hash.
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->string('p256dh');
            $table->string('auth');
            $table->timestamps();
        });

        Schema::create('reminder_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_preferences');
        Schema::dropIfExists('push_subscriptions');
    }
};
