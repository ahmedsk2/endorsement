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
}
