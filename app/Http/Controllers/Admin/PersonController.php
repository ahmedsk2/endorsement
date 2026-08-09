<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\Position;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → People (cap:people.manage). Munawib PE-01…03, LV-02…04, ST-04.
 *
 * PERSON-scoped, where Admin → Users is ACCOUNT-scoped. The difference is the point: a
 * roster-only person has no `users` row, so Admin → Users (whose list is
 * `User::query()->join('people', ...)`) cannot show them at all — and that person is frequently
 * the on-call consultant whose name is frozen onto a signed handover (D9).
 *
 * NOTHING HERE CREATES AN ACCOUNT. The invitation flow is the only path from a roster entry to a
 * credential (design §5.1), and `tests/Feature/Build/RosterNeverMintsCredentialsTest.php`
 * asserts at source level that this class never writes to `users`.
 *
 * `withTrashed()` is deliberate: people are deactivated and never deleted (owner ruling), and an
 * administrator who cannot SEE a retired person cannot bring them back. It is also why the four
 * named roles on `handover_signoffs` stay resolvable.
 */
class PersonController extends Controller
{
    public function index(): Response
    {
        $people = Person::withTrashed()
            ->withExists(['user as has_account'])
            ->orderBy('people.full_name')
            ->get();

        return Inertia::render('Admin/People', [
            // Task 2 replaces this map with App\Support\PersonPresenter, which is where the
            // contact-visibility policy is enforced. Until then this screen carries NO contact
            // field at all — `phone` and `notes` are absent, not null.
            'people' => $people->map(fn (Person $p): array => [
                'id' => (int) $p->getKey(),
                'full_name' => (string) $p->full_name,
                'short_name' => $p->short_name,
                'position' => (int) $p->position,
                'external' => (bool) $p->external,
                'active' => (bool) $p->active,
                'has_account' => (bool) $p->has_account,
                'retired' => $p->trashed(),
            ])->values()->all(),
            'positions' => Position::orderBy('id')->get(['id', 'name']),
        ]);
    }
}
