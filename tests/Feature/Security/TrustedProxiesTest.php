<?php

namespace Tests\Feature\Security;

use App\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Who the audit trail thinks did it.
 *
 * Verified against the live container on 2026-07-27: `$request->ip()` returned the
 * Cloudflare edge address, not the clinician. Every `audit_log.ip` was recording a CDN
 * datacentre, which under PDPL and NCA ECC-1 makes the actor IP in medico-legal evidence
 * worthless — and it keyed the per-IP login throttle on an address shared by everyone
 * behind the same edge, so one abusive client could throttle unrelated clinicians.
 *
 * The cause: Cloudflare sets X-Forwarded-For to the client and Traefik appends the edge's
 * PUBLIC address as the last hop. With only RFC1918 trusted, Symfony walks the chain from
 * the right, stops at the first untrusted entry — the Cloudflare edge — and calls that the
 * client.
 */
class TrustedProxiesTest extends TestCase
{
    /** Build the request as it actually arrives, and resolve it through the real middleware. */
    private function resolveIp(string $forwardedFor, string $remoteAddr = '10.0.1.50'): string
    {
        $request = Request::create('https://endorse.towardpcc.com/login', 'GET', [], [], [], [
            'REMOTE_ADDR' => $remoteAddr,
            'HTTP_X_FORWARDED_FOR' => $forwardedFor,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        app(TrustProxies::class)->handle($request, fn () => response(''));

        return (string) $request->ip();
    }

    public function test_a_request_through_cloudflare_records_the_clinician_not_the_edge(): void
    {
        // Cloudflare sets the client; Traefik appends the edge (172.71.x is in 172.64.0.0/13).
        $this->assertSame('203.0.113.5', $this->resolveIp('203.0.113.5, 172.71.100.20'));
    }

    public function test_a_direct_hit_on_the_origin_cannot_forge_its_own_address(): void
    {
        // The origin answers the public internet, so this path is reachable: an attacker
        // supplies their own X-Forwarded-For and Traefik appends their real address. The
        // rightmost untrusted entry is still the truth.
        $this->assertSame('198.51.100.77', $this->resolveIp('1.2.3.4, 198.51.100.77'));
    }

    public function test_a_forged_cloudflare_hop_does_not_launder_a_spoofed_address(): void
    {
        // Naming a trusted range inside the forged part of the chain must not promote the
        // entry to its left. Traefik's appended address is untrusted and terminates the walk.
        $this->assertSame('198.51.100.77', $this->resolveIp('1.2.3.4, 172.71.100.20, 198.51.100.77'));
    }

    public function test_the_proxy_list_is_never_a_wildcard(): void
    {
        // CLAUDE.md invariant: with '*', Symfony takes the client-supplied LEFTMOST entry —
        // forgeable audit IPs and a bypassable lockout. bootstrap/app.php defaulted to '*'
        // if the environment variable ever went missing, so only the compose file stood
        // between this deployment and a forgeable trail.
        $this->assertNotContains('*', TrustedProxies::list('*'));
        $this->assertNotContains('*', TrustedProxies::list('10.0.0.0/8,*'));
        $this->assertNotContains('*', TrustedProxies::list(null));
    }

    public function test_the_cloudflare_edge_is_trusted_even_when_the_environment_omits_it(): void
    {
        // The value the deployment platform actually sets, with no mention of Cloudflare.
        // The first version of this fix put the edge ranges in the docker-compose default
        // instead — which never ran, because Coolify sets TRUSTED_PROXIES explicitly and
        // `${TRUSTED_PROXIES:-...}` therefore always took the Coolify value. It deployed
        // green and the app still recorded the CDN. The guarantee belongs in code.
        $list = TrustedProxies::list('10.0.0.0/8,172.16.0.0/12,192.168.0.0/16');

        $this->assertContains('10.0.0.0/8', $list);
        $this->assertContains('172.64.0.0/13', $list);   // v4
        $this->assertContains('2400:cb00::/32', $list);  // v6
    }

    public function test_a_deployment_not_behind_cloudflare_can_refuse_the_edge(): void
    {
        $list = TrustedProxies::list('10.0.0.0/8,no-cloudflare');

        $this->assertSame(['10.0.0.0/8'], $list);
    }
}
