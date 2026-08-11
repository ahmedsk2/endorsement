<?php

namespace App\Support\Demo;

/**
 * Every way a row elsewhere in the schema can point AT a row the demo department created
 * (Munawib ST-05, P1e Decision F's third question).
 *
 * WHAT IT IS FOR. Removal is refused WHOLE the moment a real record leans on a demo one, and this
 * map is the list of places such a lean can happen. It is the `PeriodController::destroy()` shape
 * — "this academic year has N master rota assignments against it; clear the rota first" — applied
 * to eight tables instead of one, and for the same reason: a hard delete with no soft delete behind
 * it must never quietly take somebody else's work with it.
 *
 * The cases are real, not hypothetical. A training session runs on the demo unit and a genuine
 * handover is written on it. An invitation reaches a demo address and an account is claimed against
 * a demo person. A real clinic names a demo resident while the demo is still up. And the one that
 * decided the design: a `handover_signoffs` row naming a demo person in one of its four
 * `*_person_id` columns is MEDICO-LEGAL EVIDENCE, and must not be reachable from a cleanup button.
 *
 * ---------------------------------------------------------------------------------------------
 * IT IS HAND-WRITTEN AND IT IS NOT TRUSTED
 * ---------------------------------------------------------------------------------------------
 * A hand-written list of foreign keys is a list that goes stale the next time somebody writes a
 * migration, and a stale entry here is not a broken test — it is a real row silently deleted.
 * `DemoRemoveTest` therefore derives the inbound foreign-key set from the LIVE SCHEMA by
 * introspection and compares it against this constant in BOTH directions: a key missing from the
 * map fails, and a map entry naming a key that no longer exists fails too. The precedent is
 * `ReservedUnitCodesTest::test_the_reserved_list_covers_every_literal_route_segment`, which derives
 * its expectation from the registered routes rather than from the constant it guards.
 *
 * ---------------------------------------------------------------------------------------------
 * THE KEYS ARE THE TABLES THE DEMO WRITES — ALL OF THEM, AND ONLY THEM
 * ---------------------------------------------------------------------------------------------
 * Four of the eight are referenced by nothing at all today, and they are listed with empty arrays
 * rather than omitted. That is deliberate: the key set doubles as the enumeration of what a demo
 * department consists of, `DemoRemoveTest` asserts it against what the creator actually ledgers, and
 * `DemoRoundTripTest` uses it to refuse an exclusion list that would excuse one of these tables from
 * its proof. An omitted key would quietly opt a table out of all three.
 *
 * `institutions` is NOT here, and never can be: the demo creates no institution row. D11 makes one
 * database one customer, and a second row would collide on the institution-blind UNIQUE indexes the
 * schema is one-way committed to (`units.code`, `people.email`, `users.member_name`).
 *
 * `users` is not here either, for a stronger reason: the demo mints no account at all, so there is
 * no demo `users` row for anything to reference. `users` appears only as a REFERENCING table, twice
 * — an account claimed against a demo person, and an account whose preferred unit is the demo one.
 */
final class DemoReferences
{
    /**
     * `referenced table => list of [referencing table, column]`.
     *
     * Read only by `DemoDepartment::preflight()`, which counts the rows on the right-hand side that
     * point at a ledgered row on the left AND are not themselves ledgered. That last clause is what
     * stops the demo's own clinic, on the demo's own unit, from blocking the demo's own removal
     * forever — a whole department nobody could ever delete, with every refusal test still green.
     *
     * @var array<string, list<array{table: string, column: string}>>
     */
    public const MAP = [
        // A unit is retired, never deleted (UN-04), so almost everything in the schema that names a
        // place points here. The demo's own clinic and rota rows are on this list too and are
        // excused by being ledgered, not by being absent.
        'units' => [
            ['table' => 'clinics', 'column' => 'unit_id'],
            ['table' => 'handover_signoffs', 'column' => 'unit_id'],
            ['table' => 'handovers', 'column' => 'unit_id'],
            ['table' => 'master_rota_assignments', 'column' => 'unit_id'],
            ['table' => 'reminder_preferences', 'column' => 'unit_id'],
            ['table' => 'unit_field_definitions', 'column' => 'unit_id'],
            ['table' => 'users', 'column' => 'preferred_unit_id'],
        ],

        // The four `*_person_id` columns on `handover_signoffs` are the medico-legal case above:
        // each is a NAME OF RECORD frozen onto a day's attestation, distinct from the `*_user_id`
        // columns beside them, which record the actor. `users.person_id` is the claimed-account
        // case; `invitations.person_id` is the link that would have produced it.
        'people' => [
            ['table' => 'clinic_attendees', 'column' => 'person_id'],
            ['table' => 'handover_signoffs', 'column' => 'consultant_by_person_id'],
            ['table' => 'handover_signoffs', 'column' => 'consultant_to_person_id'],
            ['table' => 'handover_signoffs', 'column' => 'endorsed_by_person_id'],
            ['table' => 'handover_signoffs', 'column' => 'endorsed_to_person_id'],
            ['table' => 'invitations', 'column' => 'person_id'],
            ['table' => 'master_rota_assignments', 'column' => 'person_id'],
            ['table' => 'person_levels', 'column' => 'person_id'],
            ['table' => 'users', 'column' => 'person_id'],
            ['table' => 'vacations', 'column' => 'person_id'],
        ],

        // The rota is the only thing that points at a period, and the key is restrict-on-delete —
        // the same constraint `PeriodController::destroy()` turns into its own refusal message.
        'periods' => [
            ['table' => 'master_rota_assignments', 'column' => 'period_id'],
        ],

        'clinics' => [
            ['table' => 'clinic_attendees', 'column' => 'clinic_id'],
        ],

        // The four leaves. Nothing in the schema points at any of them today, and the empty arrays
        // are the record of that having been checked rather than assumed — the introspection test
        // fails the moment one of them acquires a child.
        'clinic_attendees' => [],
        'person_levels' => [],
        'master_rota_assignments' => [],
        'vacations' => [],
    ];

    /**
     * The `(referencing table, column)` pairs removal DELETES instead of refusing over.
     *
     * A subset of {@see MAP}, never a replacement for it: the map's job is to be the complete
     * inventory of inbound foreign keys, which is what `DemoRemoveTest` asserts against the live
     * schema in both directions. A pair listed here is still in the map, still introspected, and
     * still fails the build if the key it names is dropped — it simply gets a different treatment.
     *
     * ---------------------------------------------------------------------------------------------
     * WHY `invitations.person_id` IS HERE (P1e-2 review, finding 2)
     * ---------------------------------------------------------------------------------------------
     * A blocker is only honest if its remedy exists. `invitations` is NEVER DELETED anywhere in this
     * product: `InvitationController::revoke()` stamps `revoked_at`, `InvitationIssue` supersedes and
     * deliberately KEEPS the superseded row, and design §14 item 7 records that invitations have no
     * retention rule at all. So practising the invitation flow on a demo person — which
     * `/admin/setup`'s own invitations step pushes an administrator towards — made the demo
     * permanently unremovable through every screen and both console commands, behind a refusal
     * naming a remedy nobody could perform.
     *
     * MERELY UN-BLOCKING IT WOULD HAVE BEEN WORSE THAN THE BUG. The key is `nullOnDelete`, and
     * `InvitationAcceptController` reads a null `person_id` as *"create the person at redemption
     * time"* — so a link left behind by a removal would not dangle harmlessly, it would mint a
     * brand-new `people` row and a working account for whoever still held it. The row has to go.
     *
     * It carries no independent value to lose: its address is on `demo.invalid`, which the DNS root
     * guarantees cannot resolve, and the only thing it can produce is an account against a demo
     * person — which `users.person_id` refuses separately and on its own terms. `audit_log` keeps
     * the trail either way, and `remove()` reports the swept count on the screen, in its return
     * value and in its audit row rather than deleting quietly.
     *
     * @var list<array{table: string, column: string}>
     */
    public const SWEPT = [
        ['table' => 'invitations', 'column' => 'person_id'],
    ];

    /**
     * WHAT AN OPERATOR ACTUALLY DOES ABOUT A BLOCKER, per referencing table.
     *
     * The refusal used to end *"deal with those rows first (delete or re-point them)"* for every
     * table alike, which is wrong wherever this product offers neither. Accounts are deactivated and
     * never deleted; sign-offs are medico-legal evidence and are never deleted at all. A refusal
     * that names a control the product does not have sends its reader to a database console.
     *
     * `DemoRemoveTest::test_every_referencing_table_names_a_remedy_the_product_actually_offers`
     * asserts this map covers every table in {@see MAP}, so a future entry cannot ship without one.
     *
     * TABLES AND CONTROLS ONLY — never a name (invariant 9): these sentences are rendered to an
     * operator AND folded into the audit trail through the same exception.
     *
     * @var array<string, string>
     */
    public const REMEDIES = [
        'clinic_attendees' => 'clinic_attendees: edit that clinic\'s "Who attends" list '
            .'(Structure → Clinics).',
        'clinics' => 'clinics: move the clinic to another unit, or retire it and re-create it there '
            .'(Structure → Clinics). A clinic is never deleted.',
        'handovers' => 'handovers: merge the demo unit into a real one (Structure → Units → Merge), '
            .'which re-points them. A handover is never hard-deleted.',
        // Two different columns with two different answers, and the person half genuinely has no
        // remedy. Saying so is the point: this is the case DemoReferences exists for.
        'handover_signoffs' => 'handover_signoffs: a sign-off on the demo UNIT moves with a unit '
            .'merge (Structure → Units → Merge). A sign-off NAMING a demo person is medico-legal '
            .'evidence, is never deleted or re-pointed, and the demo stays for as long as it does.',
        'invitations' => 'invitations: none needed — removal deletes an invitation addressed to a '
            .'demo person along with it.',
        'master_rota_assignments' => 'master_rota_assignments: clear those cells on the master rota '
            .'(Rota).',
        'person_levels' => 'person_levels: a level span is closed, never deleted, and there is no '
            .'control that removes one — a demo person carrying a span written outside the demo '
            .'holds the removal.',
        // One of the three tables a unit merge is recorded as STRANDING (design §14 item 23), so
        // pointing at the merge here would be the same lie in a new place.
        'reminder_preferences' => 'reminder_preferences: a per-account reminder for the demo unit. '
            .'There is no screen for these and a unit merge does not move them.',
        'unit_field_definitions' => 'unit_field_definitions: merge the demo unit into a real one '
            .'(Structure → Units → Merge), which moves the definitions with it.',
        'users' => 'users: an account has been claimed against a demo person, or has the demo unit '
            .'as its preferred one. Accounts are deactivated and never deleted here — UNBIND it '
            .'(Administration → Accounts), which clears the link and deactivates the account in one '
            .'act; a preferred unit is changed by its own account holder.',
        'vacations' => 'vacations: cancel that leave on the rota screen (Rota).',
    ];
}
