<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests render full Inertia pages (the Blade root uses @vite). Without built
        // assets the @vite directive throws "Vite manifest not found", so decouple the tests
        // from the frontend build — they assert server behaviour, not compiled assets. (This is
        // why the suite must not depend on `npm run build` having run first, e.g. in CI.)
        $this->withoutVite();

        // Don't let assertInertia() verify the .vue file exists on disk — that on-disk lookup is
        // environment-fragile (passes on Windows, fails under the CI checkout). The component
        // NAME assertion still runs, which is what these tests actually care about.
        config(['inertia.testing.ensure_pages_exist' => false]);
    }
}
