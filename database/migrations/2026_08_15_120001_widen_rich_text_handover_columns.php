<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SPC-RPT-058 (docs/SECURITY-AUDIT-2026-07-26.md) — `disease`, `details`, `plan`, `nevent`
 * were left as `TEXT` when `2026_07_25_150001_widen_handover_columns_for_encryption` widened
 * `mrn`/`patient_name`/`dob`. `TEXT` caps a MySQL row at 65,535 STORED BYTES. These four
 * columns hold `SanitizedHtml`-cast ciphertext, and `Crypt::encryptString()`'s base64-of-
 * base64 envelope grows plaintext by a MEASURED 1.783x (not the ~1.4x SPC-RPT-058 originally
 * estimated for a single-value column — that figure described a different growth curve; see
 * `App\Casts\EncryptedJson::MAX_BYTES`'s docblock, which already carries the corrected
 * constant for `extra_fields`). Bisection against real MySQL 8.4 found the true ceiling at
 * 36,751 post-sanitize PLAINTEXT bytes — reachable at 20,000 characters (the validator's old
 * bound) for any script wider than ASCII: 18,375 Arabic characters (2 bytes each), 12,250
 * CJK/Arabic-presentation characters (3 bytes), 9,187 emoji (4 bytes), or as few as 7,350
 * plain-ASCII `&` characters once the sanitizer expands each to `&amp;`. MySQL enforced this
 * with `SQLSTATE[22001] 1406 Data too long for column`; SQLite accepted the identical payload
 * intact, so nothing in this suite could see the defect.
 *
 * WIDENING ONLY, NOT A RETYPE: `TEXT` and `MEDIUMTEXT` are the same logical MySQL type family
 * (variable-length string stored off-row behind an in-row length pointer) — MEDIUMTEXT simply
 * uses a 3-byte length prefix instead of TEXT's 2-byte one, raising the ceiling from 65,535
 * bytes to 16,777,215 bytes. No value that fits in a `TEXT` column is reinterpreted,
 * truncated, or reshaped by widening to `MEDIUMTEXT` — every existing row's ciphertext round-
 * trips unchanged. This is the same "safe widening on a column that may hold real data"
 * exception `2026_07_25_150001`'s own docblock claims for `mrn`/`patient_name` (that one
 * additionally changed logical type, string -> text, which is why it flagged itself as a
 * deliberate one-off; this migration changes capacity only, same logical type both sides, so
 * it needs no such carve-out to be additive-and-safe).
 *
 * `down()` deliberately does NOT blindly shrink back to `TEXT`: SPC-RPT-058's own prescribed
 * fix says to check `MAX(LENGTH())` and abort rather than silently truncate a clinical field
 * on rollback. `LENGTH()` returns bytes (not `CHAR_LENGTH()`'s characters) for a text column,
 * which is exactly what TEXT's 65,535-byte ceiling is measured in, and these columns store
 * base64 ciphertext (all-ASCII), so byte length and character length agree here regardless.
 */
return new class extends Migration
{
    /** TEXT's own byte ceiling — see the class docblock. */
    private const TEXT_BYTE_LIMIT = 65_535;

    private const COLUMNS = ['disease', 'details', 'plan', 'nevent'];

    public function up(): void
    {
        Schema::table('handovers', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->mediumText($column)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        // Refuse to shrink a column back to TEXT if doing so would truncate a row that has
        // grown past TEXT's 65,535-byte ceiling since this migration ran — the exact clinical-
        // data-loss failure mode SPC-RPT-058 exists to prevent. One query per column, each a
        // static literal (no interpolation), never user input.
        $overLimit = [
            'disease' => DB::table('handovers')->max(DB::raw('LENGTH(`disease`)')),
            'details' => DB::table('handovers')->max(DB::raw('LENGTH(`details`)')),
            'plan' => DB::table('handovers')->max(DB::raw('LENGTH(`plan`)')),
            'nevent' => DB::table('handovers')->max(DB::raw('LENGTH(`nevent`)')),
        ];

        foreach ($overLimit as $column => $maxBytes) {
            if ($maxBytes !== null && $maxBytes > self::TEXT_BYTE_LIMIT) {
                throw new \RuntimeException(
                    "Refusing to roll back: `handovers`.`{$column}` holds a value {$maxBytes} "
                    .'bytes long, over TEXT\'s 65,535-byte limit. Rolling back would silently '
                    .'truncate a clinical field. Shrink or move the offending row(s) first, or '
                    .'do not roll back this migration.'
                );
            }
        }

        Schema::table('handovers', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->text($column)->nullable()->change();
            }
        });
    }
};
