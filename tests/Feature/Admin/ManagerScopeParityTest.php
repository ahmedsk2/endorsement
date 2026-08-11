<?php

namespace Tests\Feature\Admin;

use App\Models\Capability;
use App\Models\User;
use App\Models\UserCapability;
use App\Support\AccessControl;
use App\Support\ManagerScope;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * `ManagerScope::mayTarget()` and `ManagerScope::assertMayTarget()` are ONE rule written twice,
 * and this is the price of writing it twice.
 *
 * P1c-2 Decision B needs the two-tier rule as a non-throwing predicate: a READ (the claim-status
 * projection) must decide, per person, whether this viewer may know anything at all — and a
 * throwing, auditing, 403-ing assertion is the wrong shape for a projection that walks sixty rows.
 * Collapsing `assertMayTarget()` onto the boolean was rejected because it has TWO DISTINCT refusal
 * audits (`access_denied` for the missing capability, `user_scope_denied` for the out-of-scope
 * target) and the distinction is what makes an audit trail readable.
 *
 * So: two functions, one rule, and a MATRIX — every (capability set × position) pair, not a list
 * of examples somebody thought of. This is `PickerParityTest`'s discipline (D9) applied to an
 * authorization read. `ManagerScope`'s own docblock already records what duplication cost this
 * codebase once: "a duplicated authorization rule that drifts is how a Chief Resident ends up able
 * to mint an Administrator through the newer of two doors."
 */
class ManagerScopeParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    /**
     * The four capability sets that matter, expressed as EXPLICIT per-user overrides on an
     * account whose ROLE grants neither — position 2 (Charge Nurse) holds no `users.*` capability
     * by default, so every cell below is the override doing the work, never a role default
     * leaking in. `AccessControl::flush()` after each grant, because the resolver memoises for
     * ten minutes.
     *
     * @return array<string, list<string>>
     */
    private function capabilitySets(): array
    {
        return [
            'none' => [],
            'residents only' => ['users.manage_residents'],
            'all' => ['users.manage'],
            'both' => ['users.manage', 'users.manage_residents'],
        ];
    }

    private function userHolding(array $capabilities): User
    {
        $user = User::factory()->create(['position' => 2]);

        foreach ($capabilities as $key) {
            UserCapability::create([
                'user_id' => $user->getKey(),
                'capability_id' => Capability::where('key', $key)->firstOrFail()->getKey(),
                'effect' => 'grant',
            ]);
        }

        AccessControl::flush($user->getKey());

        return $user;
    }

    public function test_may_target_agrees_with_assert_may_target_for_every_capability_set_and_position(): void
    {
        $disagreements = [];

        foreach ($this->capabilitySets() as $name => $capabilities) {
            $user = $this->userHolding($capabilities);

            foreach ([0, 2, 3, 4, 5] as $position) {
                $request = Request::create('/admin/users', 'GET');
                $request->setUserResolver(fn () => $user);

                $asserted = true;

                try {
                    ManagerScope::assertMayTarget($request, $position);
                } catch (HttpException) {
                    $asserted = false;
                }

                $predicated = ManagerScope::mayTarget($user, $position);

                if ($asserted !== $predicated) {
                    $disagreements[] = sprintf(
                        '%s × position %d: assertMayTarget()=%s but mayTarget()=%s',
                        $name, $position,
                        $asserted ? 'allowed' : 'refused',
                        $predicated ? 'allowed' : 'refused',
                    );
                }
            }
        }

        $this->assertSame([], $disagreements,
            "The two-tier rule is written twice and the copies have drifted.\n".implode("\n", $disagreements));
    }

    /**
     * A null viewer is not a special case anywhere else in this codebase — `canManageAll(null)`
     * is already false — and the projection calls this for a request whose user resolver can
     * legitimately return null.
     */
    public function test_a_null_viewer_may_target_nobody(): void
    {
        foreach ([0, 2, 3, 4, 5] as $position) {
            $this->assertFalse(ManagerScope::mayTarget(null, $position),
                "a null viewer was allowed to target position {$position}");
        }
    }

    /**
     * The matrix above would still pass if BOTH functions were the constant `false`. This is the
     * case that proves the fixture actually exercises the allowing branches too.
     */
    public function test_the_matrix_is_not_vacuously_refusing_everything(): void
    {
        $this->assertTrue(ManagerScope::mayTarget($this->userHolding(['users.manage']), 3));
        $this->assertTrue(ManagerScope::mayTarget($this->userHolding(['users.manage_residents']), 4));
        $this->assertFalse(ManagerScope::mayTarget($this->userHolding(['users.manage_residents']), 3));
    }
}
