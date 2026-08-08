<?php

namespace Tests\Unit\Casts;

use App\Casts\EncryptedJson;
use App\Models\Handover;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * `handovers.extra_fields` is the value-store half of P0b's bounded custom fields (design
 * §6.2): one encrypted TEXT column holding a `{key: value}` map for whatever
 * `unit_field_definitions` a unit has defined. It sits directly beside `mrn`/`patient_name`/
 * `dob`/`details` in the same encrypted-at-rest boundary, so it inherits the same threat this
 * cast directory keeps re-proving: one row that fails to decrypt — a restore, a hand-edit, a
 * rotated APP_KEY — must degrade to "this row's custom fields are unreadable", never to a
 * 500 that hides every other child on the unit.
 *
 * A whole-column JSON map adds a threat the single-value casts do not have: because it is
 * ONE column, an autosave that PATCHes one field and assigns the result naively would wipe
 * every sibling key (see the plan's finding 1 — that merge lives in the controller, not
 * here, but this cast still has to make the merge possible by never throwing away data the
 * caller did not touch). That is also why this cast refuses a nested value on write instead
 * of silently dropping it: the caller handed it a value that was supposed to be stored, and
 * losing it silently is worse than a loud, PHI-free exception.
 *
 * This class extends `Tests\TestCase` (not bare PHPUnit) because it needs the real Crypt
 * facade, backed by the test suite's `APP_KEY`, for the wrong-key cases — `ExtraRowFieldsTest`
 * can get away with bare PHPUnit only because it never encrypts anything.
 */
class EncryptedJsonTest extends TestCase
{
    private function cast(): EncryptedJson
    {
        return new EncryptedJson();
    }

    /** A payload produced under a key this application no longer has. */
    private function foreignCiphertext(string $plaintext = '{"weight":"3.2"}'): string
    {
        $foreign = new \Illuminate\Encryption\Encrypter(random_bytes(32), 'aes-256-cbc');

        return $foreign->encryptString($plaintext);
    }

    public function test_a_map_round_trips(): void
    {
        $cast = $this->cast();
        $model = new Handover();

        $stored = $cast->set($model, 'extra_fields', ['weight' => '3.2', 'allergy' => 'penicillin'], []);

        $this->assertSame(
            ['allergy' => 'penicillin', 'weight' => '3.2'],
            $cast->get($model, 'extra_fields', $stored, [])
        );
    }

    public function test_null_and_empty_string_yield_an_empty_array(): void
    {
        $cast = $this->cast();
        $model = new Handover();

        $this->assertSame([], $cast->get($model, 'extra_fields', null, []));
        $this->assertSame([], $cast->get($model, 'extra_fields', '', []));
    }

    public function test_an_empty_map_stores_as_null(): void
    {
        $cast = $this->cast();
        $model = new Handover();

        $this->assertNull($cast->set($model, 'extra_fields', [], []));
    }

    public function test_legacy_plaintext_json_is_still_readable(): void
    {
        $cast = $this->cast();
        $model = new Handover();

        $result = $cast->get($model, 'extra_fields', '{"weight":"3.2"}', []);

        $this->assertSame(['weight' => '3.2'], $result);
    }

    public function test_legacy_plaintext_that_is_not_json_yields_an_empty_array(): void
    {
        $cast = $this->cast();
        $model = new Handover();

        $this->assertSame([], $cast->get($model, 'extra_fields', 'not json at all', []));
    }

    public function test_a_json_array_yields_an_empty_array(): void
    {
        $cast = $this->cast();
        $model = new Handover();

        $this->assertSame([], $cast->get($model, 'extra_fields', '["weight","allergy"]', []));
    }

    public function test_a_json_scalar_yields_an_empty_array(): void
    {
        $cast = $this->cast();
        $model = new Handover();

        $this->assertSame([], $cast->get($model, 'extra_fields', '"weight"', []));
    }

    public function test_a_nested_value_is_dropped_on_read_a_sibling_scalar_survives(): void
    {
        $cast = $this->cast();
        $model = new Handover();

        $result = $cast->get($model, 'extra_fields', '{"a":{"nested":"x"},"b":"ok"}', []);

        $this->assertSame(['b' => 'ok'], $result);
    }

    public function test_a_nested_value_is_rejected_on_write(): void
    {
        $cast = $this->cast();
        $model = new Handover();

        try {
            $cast->set($model, 'extra_fields', ['weight' => ['unit' => 'Ciara Nolan, MRN 88213']], []);
            $this->fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString('Ciara Nolan', $e->getMessage());
            $this->assertStringNotContainsString('88213', $e->getMessage());
            $this->assertStringNotContainsString('unit', $e->getMessage());
        }
    }

    public function test_the_reserved_unreadable_key_cannot_be_stored(): void
    {
        $cast = $this->cast();
        $model = new Handover();

        $stored = $cast->set($model, 'extra_fields', [
            EncryptedJson::UNREADABLE_KEY => 'attacker-supplied',
            'weight' => '3.2',
        ], []);

        $result = $cast->get($model, 'extra_fields', $stored, []);

        $this->assertSame(['weight' => '3.2'], $result);
    }

    public function test_the_reserved_key_is_stripped_when_already_in_the_database(): void
    {
        $cast = $this->cast();
        $model = new Handover();

        // Legacy plaintext row that somehow already carries the sentinel key.
        $result = $cast->get($model, 'extra_fields', '{"__unreadable":"x","weight":"3.2"}', []);

        $this->assertSame(['weight' => '3.2'], $result);
    }

    public function test_ciphertext_from_another_key_reads_as_the_unreadable_marker(): void
    {
        $cast = $this->cast();
        $model = new Handover();
        $foreign = $this->foreignCiphertext();

        $result = $cast->get($model, 'extra_fields', $foreign, []);

        $this->assertArrayHasKey(EncryptedJson::UNREADABLE_KEY, $result);
        $this->assertStringContainsString('unreadable', strtolower((string) $result[EncryptedJson::UNREADABLE_KEY]));

        foreach ($result as $value) {
            $this->assertStringNotContainsString($foreign, (string) $value);
        }
    }

    public function test_overwriting_a_value_encrypted_under_another_key_is_refused(): void
    {
        $cast = $this->cast();
        $model = new Handover();
        $attributes = ['extra_fields' => $this->foreignCiphertext()];

        $this->expectException(\RuntimeException::class);

        $cast->set($model, 'extra_fields', ['weight' => '3.2'], $attributes);
    }

    public function test_clearing_a_value_encrypted_under_another_key_is_also_refused(): void
    {
        // Pins guard-before-short-circuit ordering: assertNotOverwritingForeignCiphertext()
        // must run before the null/empty short-circuit, or clearing the field silently
        // destroys ciphertext that is still recoverable once the correct key is restored.
        $cast = $this->cast();
        $model = new Handover();
        $attributes = ['extra_fields' => $this->foreignCiphertext()];

        $this->expectException(\RuntimeException::class);

        $cast->set($model, 'extra_fields', [], $attributes);
    }

    public function test_a_map_over_the_byte_ceiling_is_refused(): void
    {
        $cast = $this->cast();
        $model = new Handover();

        // A single value comfortably over the 32,000-byte ceiling once JSON-encoded.
        $huge = ['note' => str_repeat('x', EncryptedJson::MAX_BYTES + 1)];

        $this->expectException(\InvalidArgumentException::class);

        $cast->set($model, 'extra_fields', $huge, []);
    }

    public function test_values_normalize_to_strings(): void
    {
        $cast = $this->cast();
        $model = new Handover();

        $stored = $cast->set($model, 'extra_fields', ['flag' => true, 'off' => false, 'count' => 42], []);

        $this->assertSame(
            ['count' => '42', 'flag' => '1', 'off' => '0'],
            $cast->get($model, 'extra_fields', $stored, [])
        );
    }

    /**
     * PHP coerces an all-digit ("canonical decimal") string array key to int on assignment —
     * so a definition key like `'2024'` arrives at normalize() as int(2024), not string
     * '2024'. Before this fix, `! is_string($mapKey)` silently dropped it: a numeric key
     * alongside a non-numeric sibling lost only the numeric entry (proven by the reviewer).
     */
    public function test_a_numeric_key_round_trips(): void
    {
        $cast = $this->cast();
        $model = new Handover();

        $stored = $cast->set($model, 'extra_fields', ['2024' => 'x', 'weight' => '3.2'], []);

        $this->assertSame(
            ['2024' => 'x', 'weight' => '3.2'],
            $cast->get($model, 'extra_fields', $stored, [])
        );
    }

    /**
     * The more severe half of the same bug: a map with ONLY a numeric key had nothing left
     * after the drop, so normalize() returned [], set() treated that as "clear the field",
     * and the whole write silently became NULL — total, silent loss of the one value entered.
     */
    public function test_a_numeric_key_alone_does_not_store_null(): void
    {
        $cast = $this->cast();
        $model = new Handover();

        $stored = $cast->set($model, 'extra_fields', ['2024' => 'x'], []);

        $this->assertNotNull($stored);
        $this->assertSame(['2024' => 'x'], $cast->get($model, 'extra_fields', $stored, []));
    }

    public function test_keys_store_in_canonical_order(): void
    {
        $cast = $this->cast();
        $model = new Handover();

        $stored = $cast->set($model, 'extra_fields', ['zebra' => '1', 'alpha' => '2'], []);

        $json = Crypt::decryptString((string) $stored);

        $this->assertSame(['alpha', 'zebra'], array_keys(json_decode($json, true)));
    }
}
