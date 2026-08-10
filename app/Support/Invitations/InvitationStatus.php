<?php

namespace App\Support\Invitations;

use App\Http\Controllers\Admin\InvitationController;
use App\Models\Invitation;
use App\Models\Person;
use App\Models\User;
use App\Support\Calendar;
use App\Support\ManagerScope;

/**
 * AC-02's *"claim status visible"* — the ONE projection of "where has this person's invitation
 * got to" (P1c-2 Decision B).
 *
 * FIVE STATES, DERIVED, NEVER STORED. `claimed`, `revoked`, `open`, `expired` and `none` are read
 * off the three columns that already exist — `accepted_at`, `revoked_at`, `expires_at` — in that
 * precedence order, plus `hidden` for a person this viewer may not know about. There is no
 * `invitations.status` column and there must never be one: a stored lifecycle value is the
 * `person_status` enum D3 removed, wearing a different name. "Claimed" is a join
 * (`Person::hasAccount()`); "invited" is a row; neither is a column, and this plan adds no
 * migration at all.
 *
 * ONE QUERY FOR A WHOLE PAGE. The People screen lists the entire roster, so a per-person "latest
 * invitation" lookup is the N+1 P1c-1's finding 5 exists to prevent — and it is invisible on a
 * small fixture. `forPeople()` runs a single `whereIn('person_id', …)` ordered by id descending
 * and folds to the first row per person in PHP. `InvitationStatusTest::test_the_whole_page_costs
 * _one_query` pins it at thirty people with an explicit bound of one.
 *
 * IT TAKES PERSON MODELS, NOT IDS, and that is load-bearing rather than convenient: the scoping
 * decision below needs each person's POSITION, and the only caller
 * (`PersonController::rosterProps()`) already has the whole collection loaded. Taking bare ids
 * would mean a second query to fetch the positions back — one query per page becomes two, for
 * nothing. `has_account` is read the same way and for the same reason (see `mayInvite()`).
 *
 * IT SCOPES ITSELF. `InvitationController::openInvitations()` declared a `?User $viewer` and never
 * used it; the scoping lived in one caller's body, where a second caller could not see it. Here
 * the rule is applied per person through `ManagerScope::mayTarget()` — the non-throwing sibling of
 * the assertion that governs acting on an account, proven equivalent to it by
 * `ManagerScopeParityTest` over the whole matrix. A viewer outside a person's tier gets
 * `'hidden'` with no expiry and no invitation id: nothing to correlate, not merely nothing
 * rendered.
 *
 * IT IS `$extra`, NEVER A `PersonPresenter` BASE KEY. The base map reaches every `rota.view`
 * holder through `contactFree()`, and `rota.view` is seeded for every position — so a base key
 * publishes "who has not claimed their account yet" to the whole department.
 * `RotaReadViewTest::test_no_invitation_or_claim_state_reaches_the_rota_read_view_for_any_viewer`
 * is what stops that shortcut being taken.
 *
 * IT READS NO CONTACT FIELD. Deliberately: this is an account fact, and a status panel that also
 * projected an address would be the second projection `ContactFieldsAreProjectedOnceTest` exists
 * to refuse. Whether a person has an address at all is the screen's question to ask of the props
 * `PersonPresenter` already gated.
 */
final class InvitationStatus
{
    public const NONE = 'none';

    public const OPEN = 'open';

    public const EXPIRED = 'expired';

    public const CLAIMED = 'claimed';

    public const REVOKED = 'revoked';

    /** Not a state of an invitation — a state of the VIEWER's knowledge of one. */
    public const HIDDEN = 'hidden';

    /**
     * The latest invitation per person, projected for this viewer.
     *
     * @param  iterable<Person>  $people  already-loaded roster rows (see the class docblock)
     * @return array<int, array{state: string, label: string, at: array<string, mixed>|null, id: int|null, may_invite: bool}>
     */
    public static function forPeople(iterable $people, ?User $viewer): array
    {
        $rows = [];
        $ids = [];

        foreach ($people as $person) {
            $rows[] = $person;
            $ids[] = (int) $person->getKey();
        }

        $latest = self::latestPerPerson($ids);

        $out = [];

        foreach ($rows as $person) {
            $id = (int) $person->getKey();

            if (! ManagerScope::mayTarget($viewer, (int) $person->position)) {
                $out[$id] = [
                    'state' => self::HIDDEN,
                    'label' => 'Not shown',
                    'at' => null,
                    'id' => null,
                    'may_invite' => false,
                ];

                continue;
            }

            $out[$id] = self::project($latest[$id] ?? null) + ['may_invite' => self::mayInvite($person, $viewer)];
        }

        return $out;
    }

    /**
     * The state of ONE invitation row, in precedence order.
     *
     * Accepted beats revoked (superseding an already-claimed invitation is not a thing the
     * supersede loop does, but a hand-edited row must still read as claimed rather than lost),
     * revoked beats expiry (a revoked link that also happens to have aged out was still revoked
     * by a person, and that is the fact worth showing), and expiry is decided last against the
     * clock rather than against a flag.
     */
    public static function stateOf(Invitation $invitation): string
    {
        if ($invitation->accepted_at !== null) {
            return self::CLAIMED;
        }

        if ($invitation->revoked_at !== null) {
            return self::REVOKED;
        }

        return $invitation->expires_at !== null && $invitation->expires_at->isFuture()
            ? self::OPEN
            : self::EXPIRED;
    }

    /**
     * The label a screen renders, composed HERE and never in the browser (ST-06).
     *
     * Every one of the five strings is server-supplied: `resources/js` interpolates the value and
     * formats nothing, because `CalendarIsTheOnlyConverterTest`'s needle list has no allow-list
     * and a client that builds "expires in 3 days" is building it in the browser's own timezone.
     */
    public static function labelFor(string $state, ?array $at): string
    {
        $on = $at === null ? '' : ' '.$at['date'];

        return match ($state) {
            self::CLAIMED => 'Claimed'.$on,
            self::OPEN => 'Invited, expires'.$on,
            self::EXPIRED => 'Invitation expired'.$on,
            self::REVOKED => 'Invitation revoked'.$on,
            self::HIDDEN => 'Not shown',
            default => 'No invitation',
        };
    }

    /**
     * A `Calendar::label()` shape with the time of day appended.
     *
     * The DATE goes through `Calendar` because that is the one converter (AR-08/ST-06) and
     * because this department reads Hijri alongside Gregorian everywhere else. The TIME is taken
     * off the same already-typed Carbon attribute — an invitation expires at an instant, not on a
     * day, and "expires 2026-08-17" without the hour is a support call on the seventeenth.
     *
     * @return array<string, mixed>
     */
    public static function at(\DateTimeInterface $moment): array
    {
        return Calendar::label($moment) + ['time' => $moment->format('H:i')];
    }

    /**
     * May this viewer issue an invitation to a person in this ROLE (Task 2's convenience)?
     *
     * Both conditions are the SERVER's, so the affordance is offered exactly where the endpoint
     * would accept it (D9's offer-matches-write rule applied to a button): the role must be one an
     * invitation may be issued into at all (`InvitationController::OFFERABLE` — 0, 1 and 5 are
     * absent on purpose, and *"widening this list would make an invitation a route to privilege"*),
     * and the viewer must be inside that person's tier.
     *
     * DELIBERATELY NOT ASKED HERE: whether the person already holds an account, and whether they
     * have an address. Both are facts the screen already holds — `has_account` is a
     * `PersonPresenter` base key and the address is a gated one — and answering the first here
     * would mean an `EXISTS` query per row, which is precisely the N+1 this class's
     * one-query-per-page contract forbids. A caller that forgot its `withExists()` alias would
     * silently pay thirty queries and no test would say so.
     */
    private static function mayInvite(Person $person, ?User $viewer): bool
    {
        $position = (int) $person->position;

        return array_key_exists($position, InvitationController::OFFERABLE)
            && ManagerScope::mayTarget($viewer, $position);
    }

    /**
     * ONE query, folded in PHP. `orderByDesc('id')` plus "first row wins" is the whole precedence
     * rule (Decision B) — and it is the normal path rather than a tie-break, because a resend
     * rotates the token and leaves a superseded row behind every single time.
     *
     * PUBLIC since Task 4, with a second consumer: `BulkResend::analyse()` decides each selected
     * person's outcome from the same "latest invitation" this screen renders. Sharing the fold
     * rather than writing a second one is the point — a bulk path that disagreed with the People
     * screen about which row is current would offer a Resend on one state and act on another (D9),
     * and the disagreement would only ever show up on a person with several superseded rows, which
     * is every person a resend has already touched.
     *
     * @param  list<int>  $ids
     * @return array<int, Invitation>
     */
    public static function latestPerPerson(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $latest = [];

        foreach (Invitation::query()->whereIn('person_id', $ids)->orderByDesc('id')->get() as $invitation) {
            $personId = (int) $invitation->person_id;

            if (! array_key_exists($personId, $latest)) {
                $latest[$personId] = $invitation;
            }
        }

        return $latest;
    }

    /** @return array{state: string, label: string, at: array<string, mixed>|null, id: int|null} */
    private static function project(?Invitation $invitation): array
    {
        if ($invitation === null) {
            return [
                'state' => self::NONE,
                'label' => self::labelFor(self::NONE, null),
                'at' => null,
                'id' => null,
            ];
        }

        $state = self::stateOf($invitation);

        $moment = match ($state) {
            self::CLAIMED => $invitation->accepted_at,
            self::REVOKED => $invitation->revoked_at,
            default => $invitation->expires_at,
        };

        $at = $moment === null ? null : self::at($moment);

        return [
            'state' => $state,
            'label' => self::labelFor($state, $at),
            'at' => $at,
            'id' => (int) $invitation->getKey(),
        ];
    }
}
