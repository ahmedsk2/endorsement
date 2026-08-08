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
        // The customer this deployment belongs to (D11: one database per customer). Provenance
        // and in-instance grouping — NOT a security boundary. There is exactly one active row
        // per deployment; never filter a clinical query on institution_id, and never build
        // row-level tenancy on top of this table. See
        // docs/superpowers/plans/2026-08-08-p0d-tenancy-provisioning.md.
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Now that `institutions` exists, wire up the provenance FK on `users` — grouping, not
        // a scope. See the comment above.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('institution_id')->references('id')->on('institutions')->nullOnDelete();
        });

        // Role catalog. Explicit ids 0..4 (0=Administrator), so NOT auto-incrementing.
        Schema::create('positions', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        // Clinical units. Four first-class units in this system: PICU, NICU, SCBU, WARD.
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations (FK-safe order).
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['institution_id']);
        });

        Schema::dropIfExists('units');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('institutions');
    }
};
