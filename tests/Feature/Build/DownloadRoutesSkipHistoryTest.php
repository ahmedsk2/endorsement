<?php

namespace Tests\Feature\Build;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * `->defaults('no_history', true)` HAD NO TEST AT ALL, which is how two routes shipped without it.
 *
 * THE MECHANISM. Laravel records `_previous.url` for any non-AJAX GET that resolves to a route, and
 * every later `back()` redirects there. That is right for a page and wrong for anything the browser
 * fetches on a page's behalf — `App\Http\Middleware\StartSession` skips a route flagged
 * `no_history`, and its docblock records the bug that produced the flag: a signature `<img>` became
 * "the previous page", so saving a signature redirected into a PNG and Inertia rendered the raw
 * bytes full-screen.
 *
 * A DOWNLOAD IS THE SAME SHAPE AND WORSE. `Admin\MasterRotaController`'s two rota exports are GETs
 * that stream a CSV, and they shipped without the flag: clicking Export stored the download URL, so
 * the NEXT `back()` — the fill preview, the import preview, any of them — redirected into a CSV
 * download instead of a screen. It destroyed the operator's preview AND wrote a phantom
 * `rota_export` audit row recording a disclosure nobody asked for.
 *
 * SO THE RULE IS ASSERTED OVER THE WHOLE ROUTER, not over a list somebody remembers to extend. Any
 * GET route whose controller method is TYPED to return a streamed or binary-file response is a
 * download, and a download is never somewhere a person can navigate back to.
 *
 * The signature routes are the OTHER shape — an `<img>` sub-resource returning a plain
 * `Illuminate\Http\Response` carrying PNG bytes — so a return type cannot find them, and they are
 * named explicitly below. Between the two assertions, every `no_history` route in the tree is
 * covered and the reason each one has the flag is written down.
 */
class DownloadRoutesSkipHistoryTest extends TestCase
{
    /** Return types that mean "this response is a file, not a page". */
    private const DOWNLOAD_RETURNS = [StreamedResponse::class, BinaryFileResponse::class];

    public function test_every_get_route_that_returns_a_download_skips_the_session_history(): void
    {
        $offenders = [];
        $found = 0;

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || ! $this->returnsADownload($route)) {
                continue;
            }

            $found++;

            if (($route->defaults['no_history'] ?? false) !== true) {
                $offenders[] = $route->uri().' ('.$route->getActionName().')';
            }
        }

        // NON-VACUITY. A guard that finds no route to check passes forever, and this one is written
        // over reflection — a renamed base class or a dropped return type would silently empty it.
        $this->assertGreaterThanOrEqual(2, $found,
            'this guard found no download route at all, so it is asserting nothing');

        $this->assertSame([], $offenders,
            "A GET route returns a download but does not carry ->defaults('no_history', true). "
            .'The session will store the download URL as the previous page and the next back() '
            .'will redirect into the file. See App\Http\Middleware\StartSession.');
    }

    /**
     * The other half of the same mechanism, and the bug it was created for. Asserted by NAME
     * because no return type distinguishes a PNG-carrying `Response` from any other one.
     */
    public function test_the_signature_sub_resources_still_skip_the_session_history(): void
    {
        foreach (['signatures.file', 'signatures.mine', 'signatures.show'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "the route {$name} no longer exists");
            $this->assertTrue(($route->defaults['no_history'] ?? false) === true,
                "{$name} is fetched by an <img>; without no_history every later back() redirects to it");
        }
    }

    private function returnsADownload(RoutingRoute $route): bool
    {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return false;
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return false;
        }

        $type = (new ReflectionMethod($class, $method))->getReturnType();

        // A union type is how a controller says "a redirect OR a file" — `PersonController::bulk()`
        // is exactly that, and it counts, because the file branch is a real download.
        $names = match (true) {
            $type instanceof ReflectionNamedType => [$type->getName()],
            $type instanceof ReflectionUnionType => array_map(
                static fn ($member): string => $member instanceof ReflectionNamedType ? $member->getName() : '',
                $type->getTypes(),
            ),
            default => [],
        };

        foreach ($names as $name) {
            foreach (self::DOWNLOAD_RETURNS as $download) {
                if ($name !== '' && (is_a($name, $download, true) || $name === $download)) {
                    return true;
                }
            }
        }

        return false;
    }
}
