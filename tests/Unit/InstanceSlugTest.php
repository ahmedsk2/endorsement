<?php

namespace Tests\Unit;

use App\Support\Instance;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The instance slug is the one token that tells two customer deployments apart (D11,
 * P0d Task 1) — it names the backup archive, scopes that archive's own retention sweep, and
 * (Task 6/7) the host scripts' config/log/state paths and the off-host destination. It is
 * deliberately narrow and deliberately NOT auto-normalised: a silently-normalised slug would
 * disagree with the `/etc/endorsement/<slug>.conf` filename and the bucket prefix an operator
 * created by hand, and that disagreement is invisible until a restore.
 */
class InstanceSlugTest extends TestCase
{
    public function test_a_configured_slug_is_used_verbatim(): void
    {
        config(['endorsement.instance.slug' => 'qch']);

        $this->assertSame('qch', Instance::slug());
    }

    public function test_an_unset_slug_falls_back_to_the_app_name(): void
    {
        config([
            'endorsement.instance.slug' => null,
            'app.name' => 'Paediatric Endorsement',
        ]);

        $this->assertSame('paediatric-endorsement', Instance::slug());
    }

    public function test_an_app_name_that_slugs_to_nothing_falls_back_to_instance_not_the_empty_string(): void
    {
        // An empty slug would produce `endorsement--<stamp>` and a glob that matches nothing.
        config([
            'endorsement.instance.slug' => null,
            'app.name' => '***',
        ]);

        $this->assertSame('instance', Instance::slug());
    }

    public function test_a_configured_slug_is_truncated_to_the_derived_fallback_length_but_not_normalised(): void
    {
        // The derived fallback is capped at 32 chars; a CONFIGURED slug outside the pattern
        // must throw rather than be silently truncated or normalised — see the malformed
        // cases below. This test only pins that a legal 32-char slug is accepted as-is.
        $slug = str_repeat('a', 32);
        config(['endorsement.instance.slug' => $slug]);

        $this->assertSame($slug, Instance::slug());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function malformedSlugs(): iterable
    {
        yield 'contains a space' => ['QCH Central'];
        yield 'path traversal' => ['../etc'];
        yield 'disallowed glob character' => ['a*b'];
        yield 'too long (33 chars)' => [str_repeat('a', 33)];
        yield 'uppercase' => ['QCH'];
        yield 'starts with a hyphen' => ['-qch'];
    }

    #[DataProvider('malformedSlugs')]
    public function test_a_malformed_configured_slug_throws_rather_than_being_normalised(string $bad): void
    {
        config(['endorsement.instance.slug' => $bad]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('INSTANCE_SLUG');

        Instance::slug();
    }

    public function test_the_key_fingerprint_is_stable_for_the_same_key_and_differs_for_another(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        $first = Instance::keyFingerprint();
        $again = Instance::keyFingerprint();

        $this->assertSame($first, $again);

        config(['app.key' => 'base64:'.base64_encode(str_repeat('b', 32))]);
        $this->assertNotSame($first, Instance::keyFingerprint());
    }
}
