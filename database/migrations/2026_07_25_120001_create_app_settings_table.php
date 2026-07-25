<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-editable runtime settings (SMTP, push/VAPID, reminder times). Key/value on
 * purpose: the KNOWN key list, validation, and secret handling live in
 * App\Support\AppSettings — the table is dumb storage. Secret values are stored
 * ENCRYPTED (Crypt) and are never echoed back to any page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
