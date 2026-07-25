<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make room for ENCRYPTED patient identifiers (docs/COMPLIANCE.md, layer 4).
 *
 * `mrn` and `patient_name` were varchar(255); Laravel's encrypter emits a base64 envelope
 * roughly 1.4x the plaintext plus IV/MAC/JSON overhead, which overflows that. `dob` was a
 * DATETIME, and ciphertext is not a date — it becomes TEXT, with the value handled by
 * App\Casts\EncryptedDateTime so every `->format()` call keeps working.
 *
 * RETYPING `dob` is normally forbidden by this project's rules ("never retype a column
 * holding real data"). It is done here deliberately and only because the system has not
 * been deployed yet and the table holds no production rows: the legacy import runs AFTER
 * cutover, through the model, so every imported value is written already encrypted. If
 * this migration ever needs to run against populated data, do it the other way — add
 * *_enc columns, backfill, then swap.
 *
 * Widening only; nothing is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('handovers', function (Blueprint $table) {
            $table->text('mrn')->nullable()->change();
            $table->text('patient_name')->nullable()->change();
            $table->text('dob')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('handovers', function (Blueprint $table) {
            $table->string('mrn')->nullable()->change();
            $table->string('patient_name')->nullable()->change();
            $table->dateTime('dob')->nullable()->change();
        });
    }
};
