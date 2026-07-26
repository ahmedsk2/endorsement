<?php

namespace Tests\Feature\Build;

use Tests\TestCase;

/**
 * WCAG 2.1 AA contrast for the text tokens, enforced in CI.
 *
 * `--color-muted` shipped at 3.68:1 on white against a 4.5:1 requirement, driving
 * `.channel-tag` (the 10px label mechanism, ~92 uses) and `text-muted` (~63). Login.vue had
 * already diagnosed the exact problem and fixed one instance; six others were missed —
 * which is what "no accessibility tooling" costs, and why this is a test rather than a
 * one-time correction.
 *
 * It matters here specifically: clinicians read a census at speed, on shared ward screens
 * of varying quality, often at the end of a night shift.
 */
class TextContrastMeetsAaTest extends TestCase
{
    private const AA_NORMAL_TEXT = 4.5;

    /** Every surface a token is actually rendered on. */
    private const SURFACES = [
        'card/white' => '#ffffff',
        'page ground' => '#edf4f3',
        'sidebar/inset' => '#e2eeec',
    ];

    public function test_muted_text_meets_aa_on_every_surface_it_appears_on(): void
    {
        $muted = $this->token('--color-muted');

        foreach (self::SURFACES as $label => $background) {
            $this->assertGreaterThanOrEqual(
                self::AA_NORMAL_TEXT,
                $ratio = $this->contrast($muted, $background),
                "--color-muted on {$label} is {$ratio}:1, below AA for normal text"
            );
        }
    }

    public function test_status_colours_meet_aa_on_their_soft_tints(): void
    {
        $pairs = [
            '--color-ok' => '--color-ok-soft',
            '--color-caution' => '--color-caution-soft',
        ];

        foreach ($pairs as $fg => $bg) {
            $ratio = $this->contrast($this->token($fg), $this->token($bg));

            $this->assertGreaterThanOrEqual(
                self::AA_NORMAL_TEXT,
                $ratio,
                "{$fg} on {$bg} is {$ratio}:1, below AA for normal text"
            );
        }
    }

    private function token(string $name): string
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/'.preg_quote($name, '/').':\s*(#[0-9a-fA-F]{6})/',
            $css,
            "design token {$name} not found",
        );

        preg_match('/'.preg_quote($name, '/').':\s*(#[0-9a-fA-F]{6})/', $css, $m);

        return $m[1];
    }

    /** WCAG relative-luminance contrast ratio. */
    private function contrast(string $a, string $b): float
    {
        $l1 = $this->luminance($a);
        $l2 = $this->luminance($b);

        [$hi, $lo] = $l1 >= $l2 ? [$l1, $l2] : [$l2, $l1];

        return round(($hi + 0.05) / ($lo + 0.05), 2);
    }

    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');

        $channel = function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel((int) hexdec(substr($hex, 0, 2)))
            + 0.7152 * $channel((int) hexdec(substr($hex, 2, 2)))
            + 0.0722 * $channel((int) hexdec(substr($hex, 4, 2)));
    }
}
