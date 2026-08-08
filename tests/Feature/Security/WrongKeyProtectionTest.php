<?php

namespace Tests\Feature\Security;

use App\Casts\EncryptedDateTime;
use App\Casts\EncryptedString;
use App\Casts\SanitizedHtml;
use App\Models\Handover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SPC-TM-011. The encrypted casts fall back to returning the stored value when decryption
 * fails, so that one unreadable row cannot 500 a whole census. That is right for a row
 * written before encryption existed — and catastrophic for a row encrypted under a
 * DIFFERENT APP_KEY:
 *
 *   read  → the ciphertext is rendered as if it were clinical text
 *   write → save-on-blur re-encrypts THAT CIPHERTEXT under the new key
 *
 * after which the original plaintext is gone for good, and the backup does not help
 * because it is encrypted with the key that was replaced. An accidental rotation — a
 * redeploy with a regenerated key, an env restored from the wrong place — is an ordinary
 * operational mistake, not an exotic one.
 *
 * So the casts must tell "never encrypted" apart from "encrypted, wrong key", and refuse
 * to destroy the second.
 */
class WrongKeyProtectionTest extends TestCase
{
    use RefreshDatabase;

    /** A payload produced under a key this application no longer has. */
    private function foreignCiphertext(): string
    {
        $foreign = new \Illuminate\Encryption\Encrypter(random_bytes(32), 'aes-256-cbc');

        return $foreign->encryptString('Ciara Nolan, MRN 88213');
    }

    public function test_a_legacy_plaintext_value_is_still_returned(): void
    {
        // The reason the fallback exists: a pre-encryption row stays readable.
        $cast = new EncryptedString();

        $this->assertSame('MRN-0042', $cast->get(new Handover(), 'mrn', 'MRN-0042', []));
    }

    public function test_ciphertext_from_another_key_is_not_rendered_as_clinical_text(): void
    {
        $cast = new EncryptedString();
        $foreign = $this->foreignCiphertext();

        $out = (string) $cast->get(new Handover(), 'mrn', $foreign, []);

        $this->assertStringNotContainsString($foreign, $out, 'raw ciphertext must never be shown as content');
        $this->assertStringContainsString('unreadable', strtolower($out));
    }

    public function test_overwriting_a_value_encrypted_under_another_key_is_refused(): void
    {
        // The destructive half. Refusing the write is what makes the damage recoverable:
        // the original row is still there, still decryptable once the right key is restored.
        $cast = new EncryptedString();
        $attributes = ['mrn' => $this->foreignCiphertext()];

        $this->expectException(\RuntimeException::class);

        $cast->set(new Handover(), 'mrn', 'MRN-9999', $attributes);
    }

    public function test_the_rich_text_cast_refuses_the_same_overwrite(): void
    {
        $cast = new SanitizedHtml();
        $attributes = ['details' => $this->foreignCiphertext()];

        $this->expectException(\RuntimeException::class);

        $cast->set(new Handover(), 'details', '<p>New note</p>', $attributes);
    }

    public function test_the_date_cast_shows_the_unreadable_marker_instead_of_a_blank_date(): void
    {
        // dob is a DIRECT IDENTIFIER by EncryptedDateTime's own docblock. Before this cast
        // gained DetectsForeignCiphertext, foreign ciphertext failed Carbon::parse() and
        // get() returned null — a blank date, indistinguishable from "never recorded", and
        // silently overwritable by the very next save.
        $cast = new EncryptedDateTime();
        $foreign = $this->foreignCiphertext();

        $out = $cast->get(new Handover(), 'dob', $foreign, []);

        $this->assertNotNull($out, 'a foreign-key row must surface as unreadable, not as a blank date');
        $this->assertStringContainsString('unreadable', strtolower((string) $out));
    }

    public function test_overwriting_a_date_encrypted_under_another_key_is_refused(): void
    {
        $cast = new EncryptedDateTime();
        $attributes = ['dob' => $this->foreignCiphertext()];

        $this->expectException(\RuntimeException::class);

        $cast->set(new Handover(), 'dob', '2020-01-01', $attributes);
    }

    public function test_clearing_a_date_encrypted_under_another_key_is_also_refused(): void
    {
        // Same guard-before-short-circuit ordering as the string casts: the null/empty
        // short-circuit must not run before the foreign-ciphertext check, or clearing the
        // field silently destroys a birth date that is still recoverable.
        $cast = new EncryptedDateTime();
        $attributes = ['dob' => $this->foreignCiphertext()];

        $this->expectException(\RuntimeException::class);

        $cast->set(new Handover(), 'dob', null, $attributes);
    }

    public function test_the_date_cast_normal_writes_are_unaffected(): void
    {
        $cast = new EncryptedDateTime();

        // No existing value.
        $this->assertNotNull($cast->set(new Handover(), 'dob', '2020-01-01', []));

        // An existing value written under the CURRENT key.
        $current = $cast->set(new Handover(), 'dob', '2020-01-01', []);
        $this->assertNotNull($cast->set(new Handover(), 'dob', '2020-06-01', ['dob' => $current]));

        // An existing legacy plaintext value.
        $this->assertNotNull($cast->set(new Handover(), 'dob', '2020-06-01', ['dob' => '2019-01-01 00:00:00']));
    }

    public function test_normal_writes_are_unaffected(): void
    {
        $cast = new EncryptedString();

        // No existing value.
        $this->assertNotNull($cast->set(new Handover(), 'mrn', 'MRN-1', []));

        // An existing value written under the CURRENT key.
        $current = $cast->set(new Handover(), 'mrn', 'MRN-1', []);
        $this->assertNotNull($cast->set(new Handover(), 'mrn', 'MRN-2', ['mrn' => $current]));

        // An existing legacy plaintext value.
        $this->assertNotNull($cast->set(new Handover(), 'mrn', 'MRN-3', ['mrn' => 'MRN-legacy']));
    }
}
