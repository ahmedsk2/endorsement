<?php

namespace App\Support;

/**
 * Which hops are allowed to tell us who the client is.
 *
 * Two failures live here, and they pull in opposite directions.
 *
 * TRUST TOO MUCH and the trail is fiction. With `*`, Symfony believes the LEFTMOST
 * X-Forwarded-For entry — which the client writes. Audit IPs become whatever the actor
 * types, and a per-IP lockout is escaped by changing a header. `bootstrap/app.php` used to
 * fall back to exactly that if the environment variable went missing, so a single absent
 * value separated this deployment from a forgeable audit trail.
 *
 * TRUST TOO LITTLE and the trail is merely wrong. Cloudflare sets X-Forwarded-For to the
 * client and Traefik appends the edge's PUBLIC address as the last hop. With only RFC1918
 * trusted, the walk stops at that edge and records a CDN datacentre as the actor — which
 * is what this deployment did until 2026-07-27, verified against the live container.
 *
 * So the edges are named explicitly. A value may contain the literal token `cloudflare`,
 * which expands to the published ranges below; anything else is passed through as a CIDR.
 *
 * Residual risk, stated rather than hidden: trusting Cloudflare's ranges means an attacker
 * operating FROM Cloudflare's IP space and reaching the origin directly could forge a
 * client address. That is inherent to trusting a CDN, and it is one more reason to
 * restrict the origin's ingress to these same ranges.
 */
final class TrustedProxies
{
    /**
     * Private ranges (Traefik and the container network) plus the Cloudflare edge.
     *
     * Cloudflare is in the DEFAULT, not only in docker-compose, deliberately: if this value
     * is ever lost the app keeps resolving clinicians correctly instead of silently filing
     * every audited action against a CDN — a failure with no error and no symptom.
     */
    public const DEFAULT = '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,cloudflare';

    /**
     * Cloudflare's published edge ranges — https://www.cloudflare.com/ips-v4 and ips-v6,
     * retrieved 2026-07-27.
     *
     * These change rarely. If Cloudflare adds a range and this list is stale, the effect is
     * degradation, not exposure: requests through the new range resolve to the edge address
     * again, exactly as they did before this class existed. Re-check when renewing the
     * annual review in docs/COMPLIANCE.md.
     *
     * @var list<string>
     */
    public const CLOUDFLARE = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * @param  string|null  $configured  Comma-separated CIDRs and/or the token `cloudflare`.
     *                                   Null reads TRUSTED_PROXIES, then DEFAULT.
     * @return list<string>
     */
    public static function list(?string $configured = null): array
    {
        $configured ??= (string) env('TRUSTED_PROXIES', self::DEFAULT);

        $proxies = [];

        foreach (explode(',', $configured) as $entry) {
            $entry = trim($entry);

            // A wildcard is never honoured, from any source. It is not a configuration
            // choice with a trade-off; it means "believe whatever the client claims".
            if ($entry === '' || $entry === '*' || $entry === '**') {
                continue;
            }

            if (strcasecmp($entry, 'cloudflare') === 0) {
                $proxies = array_merge($proxies, self::CLOUDFLARE);

                continue;
            }

            $proxies[] = $entry;
        }

        return array_values(array_unique($proxies));
    }
}
