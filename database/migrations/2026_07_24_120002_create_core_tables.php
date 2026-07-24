<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Public self-registration holding area; admin approval -> creates a `users` row.
        Schema::create('pending_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable();
            $table->string('full_name')->nullable();
            $table->string('member_name');
            $table->string('member_email')->nullable();
            $table->string('password');
            $table->unsignedTinyInteger('position')->default(1);
            $table->timestamp('requested_at')->nullable();
            $table->timestamps();
        });

        // Append-only access trail. NEVER stores PHI in `detail` (ids/counts only).
        // Hash chain: hash = sha256(prev_hash + canonical(row)). Columns only here.
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('action')->index();
            $table->text('detail')->nullable();
            $table->string('ip')->nullable();
            $table->string('prev_hash')->nullable();
            $table->string('hash')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('pending_registrations');
    }
};
