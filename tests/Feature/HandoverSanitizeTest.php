<?php

namespace Tests\Feature;

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
}
