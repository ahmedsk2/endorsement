<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The four NAMED ROLES on a signed sheet move from `users` to `people` (D3 reversed, D9).
 *
 * They are names of record, not actors: the on-call consultant is frequently someone who never
 * logs in, and under the new shape that person has no `users` row at all. `signed_off_by_user_id`
 * and `reopened_by_user_id` stay on `users` — those ARE actors, and an actor is by definition
 * someone who authenticated.
 *
 * The old `*_user_id` columns are KEPT, frozen. They stop being written by 2026_08_10 (P0c Task
 * 6) and are the only independent cross-check that this backfill was right; on a medico-legal
 * table that is worth four dead columns. `ClinicalSchemaTest` pins both sets.
 *
 * THE BACKFILL IS A JOIN, NOT A COPY. `people.id` and `users.id` are independent autoincrement
 * sequences: `SET endorsed_by_person_id = endorsed_by_user_id` would silently rename clinicians
 * on historical sheets with no error and no FK violation, because both integers are valid keys in
 * their own table.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const FIELDS = ['endorsed_by', 'endorsed_to', 'consultant_by', 'consultant_to'];

    public function up(): void
    {
        Schema::table('handover_signoffs', function (Blueprint $table) {
            foreach (self::FIELDS as $field) {
                $table->foreignId($field.'_person_id')->nullable()
                    ->after($field.'_user_id')
                    ->constrained('people')->nullOnDelete();
            }
        });

        foreach (self::FIELDS as $field) {
            DB::statement(
                "update handover_signoffs set {$field}_person_id = ".
                "(select users.person_id from users where users.id = handover_signoffs.{$field}_user_id) ".
                "where {$field}_user_id is not null"
            );
        }
    }

    public function down(): void
    {
        Schema::table('handover_signoffs', function (Blueprint $table) {
            foreach (self::FIELDS as $field) {
                $table->dropConstrainedForeignId($field.'_person_id');
            }
        });
    }
};
