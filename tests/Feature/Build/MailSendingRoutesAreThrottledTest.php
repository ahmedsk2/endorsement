<?php

namespace Tests\Feature\Build;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * EVERY ENDPOINT THAT SENDS ON A BUTTON PRESS IS RATE-LIMITED (adversarial review F6).
 *
 * An unthrottled endpoint that talks to an SMTP relay is a small open relay: one authenticated
 * account, one held-down key, and the department's mail domain is the thing that gets
 * reputation-blocked. On the invitation endpoints it is worse than volume — each send mints a
 * fresh bearer credential and kills the previous one, so a repeated press also churns links that
 * may already be in somebody's inbox.
 *
 * WHY THIS IS A SCAN AND NOT A LIST. `admin.invitations.bulk-resend` shipped with
 * `throttle:6,1` and a comment claiming six a minute matched "the only other endpoint in this
 * application that sends on a button press". That was false when it was written:
 * `admin.invitations.store` and `admin.invitations.resend` both send, both sat in an
 * `auth`-only group with no `throttle` of any kind — not even the `throttle:clinical` the
 * access-control group above them carries — and a hand-written list is exactly what fails to
 * notice the third one. The property is derived from the ROUTER and the handler's own body, so a
 * new mailing endpoint is covered the day it is registered.
 *
 * THE SCAN FOLLOWS ONE LEVEL OF SAME-CLASS CALL, deliberately. `InvitationController::store()`
 * contains no `Mail::` at all — it ends in `$this->deliver(...)`, and `bulkResend()` in
 * `$this->mailAll(...)`. A scan of the handler body alone would report both as non-sending and be
 * green on the exact defect it exists to catch; a scan of the whole controller FILE would instead
 * report `bulkPreview()` and `revoke()`, which send nothing, and buy an allow-list to say so. One
 * level of indirection is where this codebase actually puts its mailers.
 */
class MailSendingRoutesAreThrottledTest extends TestCase
{
    /** Anything that hands a message to the framework's outbound path. */
    private const MAIL_NEEDLES = [
        'Mail::to(',
        'Mail::send(',
        'Mail::queue(',
        'Notification::send(',
        'Notification::route(',
        '->notify(',
    ];

    public function test_every_endpoint_that_sends_mail_is_throttled(): void
    {
        $senders = $this->mailSendingRoutes();

        // A scan that found nothing is a scan that proves nothing — this application has mailing
        // endpoints, and if the reflection below ever stops resolving them it must fail here
        // rather than pass silently.
        $this->assertNotSame([], $senders,
            'The scan found no mail-sending route at all. It has stopped working, not stopped mattering.');

        $unthrottled = [];

        foreach ($senders as $name => $route) {
            $throttles = array_values(array_filter(
                $route->gatherMiddleware(),
                static fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle:'),
            ));

            if ($throttles === []) {
                $unthrottled[] = $route->methods()[0].' '.$route->uri().' ('.$name.')';
            }
        }

        $this->assertSame([], $unthrottled,
            "An endpoint that sends on a button press must carry a `throttle:` middleware.\n"
            .implode("\n", $unthrottled));
    }

    /**
     * The scan's own calibration: it must be seeing the endpoints we know send, by name. Without
     * this, a reflection change that quietly stopped resolving `$this->deliver(...)` would leave
     * the case above green with an empty-but-not-empty set.
     */
    public function test_the_scan_sees_the_senders_this_codebase_actually_has(): void
    {
        $names = array_keys($this->mailSendingRoutes());

        foreach ([
            'admin.invitations.store',
            'admin.invitations.resend',
            'admin.invitations.bulk-resend',
            'admin.settings.test-email',
            'profile.email.send',
        ] as $expected) {
            $this->assertContains($expected, $names, "The scan no longer sees {$expected} as a sender.");
        }

        // ... and it must NOT see the two neighbours in the same controller that send nothing,
        // or the property degenerates into "every invitation route is throttled" by accident.
        $this->assertNotContains('admin.invitations.bulk-preview', $names);
        $this->assertNotContains('admin.invitations.revoke', $names);
    }

    /**
     * Route name => route, for every route whose handler sends mail directly or through one
     * same-class call.
     *
     * @return array<string, RoutingRoute>
     */
    private function mailSendingRoutes(): array
    {
        $senders = [];

        foreach (Route::getRoutes() as $route) {
            $action = (string) $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            if (! class_exists($class) || ! method_exists($class, $method)) {
                continue;
            }

            if ($this->sendsMail($class, $method)) {
                $senders[(string) $route->getName()] = $route;
            }
        }

        return $senders;
    }

    /** Does this handler, or one same-class method it calls, hand a message to the mailer? */
    private function sendsMail(string $class, string $method): bool
    {
        $body = $this->bodyOf($class, $method);

        if ($this->containsMailNeedle($body)) {
            return true;
        }

        // One level, same class: `$this->deliver(...)`, `$this->mailAll(...)`, `self::send…()`.
        preg_match_all('/(?:\$this->|self::|static::)([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $body, $matches);

        foreach (array_unique($matches[1]) as $called) {
            if ($called !== $method && method_exists($class, $called)
                && $this->containsMailNeedle($this->bodyOf($class, $called))) {
                return true;
            }
        }

        return false;
    }

    private function containsMailNeedle(string $body): bool
    {
        foreach (self::MAIL_NEEDLES as $needle) {
            if (str_contains($body, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function bodyOf(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $file = (string) $reflection->getFileName();

        if ($file === '' || ! is_file($file)) {
            return '';
        }

        $lines = file($file) ?: [];

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
