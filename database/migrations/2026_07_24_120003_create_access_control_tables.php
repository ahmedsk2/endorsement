<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Data-driven permissions: a `capabilities` catalog x `role_capabilities`
     * (role-default grants) x `user_capabilities` (per-user overrides;
     * deny wins over role default, grant adds). Rows are seeded in a later task.
     */
    public function up(): void
    {
        Schema::create('capabilities', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // dot.notation, e.g. patients.view
            $table->string('label');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_capabilities', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('position')->index(); // role
            $table->foreignId('capability_id')->constrained('capabilities')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['position', 'capability_id']);
        });

        Schema::create('user_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('capability_id')->constrained('capabilities')->cascadeOnDelete();
            $table->string('effect')->default('grant'); // 'grant' | 'deny' — deny wins over role default; grant adds
            $table->timestamps();

            $table->unique(['user_id', 'capability_id']);
        });
    }

    /**
     * Reverse the migrations (FK-safe order).
     */
    public function down(): void
    {
        Schema::dropIfExists('user_capabilities');
        Schema::dropIfExists('role_capabilities');
        Schema::dropIfExists('capabilities');
    }
};
