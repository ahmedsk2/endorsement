<?php

namespace Tests\Feature;

use App\Casts\SanitizedHtml;
use App\Models\Handover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The four rich-text handover fields (disease/details/plan/nevent) are sanitized on write
 * via an HTMLPurifier allow-list — the stored-XSS defense ported from the legacy
 * `sanitize.php`. This locks that behavior at the model layer.
 */
class HandoverSanitizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_rich_text_fields_are_sanitized_on_write(): void
    {
        $handover = Handover::create([
            'disease' => '<p>Sepsis</p><script>alert(1)</script>',
            'details' => '<b>stable</b><img src=x onerror=alert(1)>',
            'plan' => '<div onclick="steal()">continue abx</div>',
            'nevent' => '<a href="javascript:evil()">event</a>',
        ]);

        $handover->refresh();

        // Scripts / event handlers / javascript: URIs stripped.
        $this->assertStringNotContainsString('<script', $handover->disease);
        $this->assertStringNotContainsString('onerror', $handover->details);
        $this->assertStringNotContainsString('onclick', $handover->plan);
        $this->assertStringNotContainsString('javascript:', $handover->nevent);

        // Allow-listed markup preserved.
        $this->assertStringContainsString('Sepsis', $handover->disease);
        $this->assertStringContainsString('<b>stable</b>', $handover->details);
        $this->assertStringContainsString('continue abx', $handover->plan);
    }

    /**
     * SPC-RPT-058 — defense in depth, mirroring `EncryptedJsonTest::
     * test_a_map_over_the_byte_ceiling_is_refused`. `App\Rules\MaxSanitizedBytes` is what
     * turns this into a friendly validation error for a browser client
     * (`EndorsementTest::test_arabic_rich_text_over_the_byte_ceiling_is_refused_by_validation`);
     * this test proves the cast ITSELF refuses too, so no other write path — a factory, a
     * console command, a future API — can reach the database with a value the HTTP layer
     * would have rejected.
     */
    public function test_a_value_over_the_sanitized_byte_ceiling_is_refused_by_the_cast(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Handover::create([
            // Plain text, not entity-expanded by the sanitizer, so sanitized bytes ==
            // raw bytes here: comfortably over the ceiling either way.
            'disease' => str_repeat('A', SanitizedHtml::MAX_PLAINTEXT_BYTES + 1),
        ]);
    }

    /** The boundary case: exactly at the ceiling must NOT throw. */
    public function test_a_value_at_the_sanitized_byte_ceiling_is_accepted_by_the_cast(): void
    {
        $atCeiling = str_repeat('A', SanitizedHtml::MAX_PLAINTEXT_BYTES);

        $handover = Handover::create(['disease' => $atCeiling]);

        $this->assertSame($atCeiling, $handover->fresh()->disease);
    }
}
