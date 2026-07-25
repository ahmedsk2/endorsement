<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A push subscription endpoint must be an HTTPS URL belonging to a real browser push
 * service.
 *
 * It was validated as `string|max:2000`, and the reminder scheduler POSTs to whatever is
 * stored — server-side, from inside the container, on the same host as the patient
 * database. That is a blind SSRF, and not entirely blind either: the subscription row is
 * deleted when the endpoint answers 404 or 410, which turns it into a boolean oracle for
 * probing internal addresses and ports.
 *
 * An allow-list rather than a deny-list: the set of legitimate push services is small,
 * known, and changes about once a decade, whereas the set of internal addresses worth
 * protecting is open-ended (link-local metadata, container DNS names, loopback ports).
 */
class PushServiceEndpoint implements ValidationRule
{
    /** Hosts, or parent domains, operated by browser vendors as push endpoints. */
    private const ALLOWED = [
        'fcm.googleapis.com',                 // Chrome / Chromium
        'android.googleapis.com',             // older Chrome builds
        'push.services.mozilla.com',          // Firefox (subdomained per-region)
        'notify.windows.com',                 // Edge / Windows (subdomained)
        'push.apple.com',                     // Safari (subdomained)
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $url = is_string($value) ? parse_url($value) : false;

        if ($url === false || ! isset($url['scheme'], $url['host'])) {
            $fail('The :attribute is not a valid URL.');

            return;
        }

        if (strtolower($url['scheme']) !== 'https') {
            $fail('The :attribute must use https.');

            return;
        }

        $host = strtolower($url['host']);

        foreach (self::ALLOWED as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return;
            }
        }

        $fail('The :attribute is not a recognised push service.');
    }
}
