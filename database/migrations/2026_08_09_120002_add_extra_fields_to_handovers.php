<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The value-store half of P0b's bounded custom fields (design §6.2, "Ceiling 2") — one
 * encrypted `{key: value}` map per row, driven by `unit_field_definitions`
 * (`2026_08_09_120001_create_unit_field_definitions_table`). Coexists with the named,
 * individually-encrypted identity columns (`dob`, `age`, `ward_unit`); this column is only
 * for fields a unit defined for itself.
 *
 * Additive and nullable, per the project rule. The four paediatric units get zero field
 * definitions (see the migration above), so this column stays NULL for every existing row —
 * nothing about them changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('handovers', function (Blueprint $table) {
            if (! Schema::hasColumn('handovers', 'extra_fields')) {
                // TEXT, not json: the stored value is base64 ciphertext (App\Casts\EncryptedJson),
                // which MySQL's JSON column type rejects outright as "Invalid JSON text" — that
                // failure only happens in production. SQLite maps a json() column to TEXT under
                // the hood, so a json() column here would pass every test in this suite and then
                // fail the instant it hit a real MySQL database. See EncryptedJson's docblock and
                // ExtraRowFields' equivalent note for `units.extra_row_fields`.
                $table->text('extra_fields')->nullable()->after('nevent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('handovers', function (Blueprint $table) {
            if (Schema::hasColumn('handovers', 'extra_fields')) {
                $table->dropColumn('extra_fields');
            }
        });
    }
};
