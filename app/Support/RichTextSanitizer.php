<?php

namespace App\Support;

use App\Casts\SanitizedHtml;
use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Stored-XSS defense for the rich-text handover fields, ported from the legacy
 * `sanitize.php`. Strips `<script>`, event handlers, and unsafe URIs down to a small
 * formatting allow-list. Applied on write via the {@see SanitizedHtml} cast.
 */
class RichTextSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    public static function clean(string $html): string
    {
        return self::purifier()->purify($html);
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier === null) {
            $config = HTMLPurifier_Config::createDefault();

            /*
             * Ported from legacy `sanitize.php`, with ONE deliberate change (G3): `[style]` is now
             * permitted on the formatting tags that were already allow-listed, not on `span` alone.
             *
             * Why: legacy allowed style only on `span`, but browsers do not always wrap a colour in
             * a span. Verified in Chrome against the restored toolbar — colouring text that is
             * already bold emits `<b style="color:…">`, and already underlined emits
             * `<u style="color:…">`. Under the span-only list the style attribute was dropped and
             * the colour silently vanished on save. "Bold it, then flag it red" is a routine way to
             * mark an escalation on a handover sheet, so this was real data loss, not an edge case.
             *
             * This does NOT weaken sanitization. No new TAG is allowed and — critically — no new CSS
             * PROPERTY: `CSS.AllowedProperties` below is unchanged, so the same four inert
             * presentational properties are all that can appear on any of them, and HTMLPurifier
             * still validates every value. Confirmed still stripped: `<script>`, event handlers,
             * `expression()`, `behavior:url()`, `javascript:` URIs, and layout properties such as
             * `position`. `font-style` / `text-decoration-line` also remain stripped, which is why
             * RichTextEditor emits `<i>` / `<u>` tags rather than their CSS forms.
             */
            $config->set(
                'HTML.Allowed',
                'p,br,b[style],strong[style],i[style],em[style],u[style],ul,ol,li[style],span[style],div[style],h1,h2,h3,font[color]',
            );
            $config->set('CSS.AllowedProperties', 'color,background-color,font-weight,text-decoration');
            $config->set('Cache.SerializerPath', sys_get_temp_dir());

            self::$purifier = new HTMLPurifier($config);
        }

        return self::$purifier;
    }
}
