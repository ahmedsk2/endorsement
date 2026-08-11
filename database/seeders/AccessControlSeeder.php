<?php

namespace Database\Seeders;

use App\Models\AppliedRoleDefault;
use App\Models\Capability;
use App\Models\RoleCapability;
use App\Support\AccessControl;
use Illuminate\Database\Seeder;

/**
 * Seeds the capability catalog and the role-default grants (role_capabilities) so that, on
 * day one, every role's EFFECTIVE permissions reproduce the legacy endorsement gate EXACTLY:
 * require_auth([0,2,3,4]) — Nurse (1) excluded. Per-user overrides (user_capabilities) are
 * runtime data and are intentionally NOT seeded here.
 *
 * Idempotent AND non-re-asserting. A default here means "the value when nothing has been
 * decided", never "the value we re-impose over your decision":
 *
 *   - a grant an administrator made by hand (role-level or per-user) is NEVER trimmed back;
 *   - an edited label/description is never overwritten;
 *   - a role default an administrator DELIBERATELY REVOKED in Admin → Access Control is never
 *     re-granted. Each (position, capability) default is applied exactly once, recorded in
 *     `applied_role_defaults`; after that the seeder does not touch the pair again. A capability
 *     added to the catalog LATER has no marker, so its defaults still apply on first sight
 *     without resurrecting anybody's old revocations.
 */
class AccessControlSeeder extends Seeder
{
    /**
     * Longer descriptions for the capabilities whose label alone does not say enough. These
     * strings are what an administrator READS on Admin → Access Control when deciding who to
     * grant, so they must state what the capability actually permits.
     *
     * @var array<string, string>
     */
    private const DESCRIPTIONS = [
        'endorsement.reopen' => 'Reopen a SIGNED handover day so its attestation can be corrected. '
            .'Separate from “endorsement.edit” on purpose: editing a sheet is clinical documentation, '
            .'whereas reopening reverses an attestation another named clinician put their signature to, '
            .'which is a medico-legal act. Default: Administrator only — but grantable per role or per '
            .'named user so a senior clinician can correct a wrong sign-off outside admin hours WITHOUT '
            .'being given the administrator console. Holding it does not relax anything else: a written '
            .'reason is still mandatory, the reversal is still stamped on the record with who did it and '
            .'when, no endorser name is ever erased, and every reopen (and every refused attempt) is '
            .'audited. The handover sheet names the current holders of this capability to anyone who '
            .'cannot reopen, so a ward always knows who to call.',

        'users.manage_residents' => 'The Chief Resident power: approve pending RESIDENT '
            .'registrations and activate/deactivate RESIDENT accounts — nothing else. No role '
            .'changes, no profile edits, no non-resident accounts, and the full user console '
            .'stays Administrator-only. Default: Administrator and Chief Resident; grantable '
            .'per role or per named user like any capability.',

        'endorsement.compliance' => 'View the missed-days compliance page: for each unit, how many '
            .'days in a chosen date range have no signed endorsement, expandable to the missing dates '
            .'themselves. Counts and dates only — the page carries no patient data. Default: '
            .'Administrator only, grantable per role (e.g. Consultants) or per named user.',

        'structure.manage' => 'Define the department\'s STRUCTURE: units (create, rename, colour, '
            .'order, capability flags, aliases, deactivate, merge), the training-level ladder, the '
            .'calendar (weekend days, Hijri display and its calibration), rota periods, and the '
            .'holiday list. Distinct from “settings.manage”, which covers infrastructure (mail '
            .'server, push keys, reminder times) — mistyping an SMTP host bounces a message, '
            .'whereas mistyping the Hijri offset silently redates every Hijri label and every '
            .'Hijri-ruled holiday in the system. Default: Administrator only; grantable per role '
            .'or per named user like any capability.',

        'people.manage' => 'Manage the departmental ROSTER: who is on it, their training level '
            .'and its history, their contact details and scheduling constraints, whether they are '
            .'an external rotator, and the annual promotion. Distinct from “users.manage”, which '
            .'runs the ACCOUNT console (approvals, activation, roles, invitations) — a person on '
            .'the roster may never have had an account at all, and is invisible to that screen by '
            .'construction. Holding this does NOT create accounts: the invitation flow remains '
            .'the only way one is made. It DOES govern who can read staff phone numbers and '
            .'notes, subject to the department\'s contact-visibility setting. Default: '
            .'Administrator only; grantable per role or per named user like any capability.',

        'rota.view' => 'View the master rota: which unit each person is assigned to, in each '
            .'period of the academic year. Read-only — the whole point of this capability (Munawib '
            .'MR-05) is that a resident can see which unit they rotate through next. Default: '
            .'every seeded position.',

        'rota.manage' => 'Create and edit master rota assignments and vacations: assign a person '
            .'to a unit for a period or a date-bounded split of one, and book or cancel a leave '
            .'span. Default: Administrator only (owner decision, 2026-08-10). Munawib §5 also '
            .'grants it to its Scheduler persona, which maps to no role here; Chief Resident is '
            .'the nearest fit, and a department that wants it there grants it — per role or per '
            .'named user, like any capability.',

        'clinics.view' => 'View the weekly clinic map: which unit runs which clinic, on which day '
            .'of the week, in the morning or the afternoon. Read-only, and it names nobody — the '
            .'map shows the clinics and their sessions, never a roster and never anyone\'s contact '
            .'details. Default: every role — a resident needs to know when their unit\'s clinic '
            .'runs (Munawib CL-05), which is the same reasoning “rota.view” ships on. DEFINING a '
            .'clinic is separate and stays on “structure.manage”, beside units, levels and the '
            .'calendar, because a clinic\'s whole payload is department structure.',
    ];

    /**
     * The capability catalog: dot-notation key => human label.
     *
     * @var array<string, string>
     */
    private const CATALOG = [
        // Any authenticated active member.
        'profile.manage' => 'View and edit own profile / change own password',

        // Endorsement module (legacy [0,2,3,4] — excludes Nurse).
        'endorsement.view' => 'View the shift endorsement / handover sheets',
        'endorsement.edit' => 'Edit and carry forward endorsement handover rows',
        'endorsement.reopen' => 'Reopen a signed handover day for correction (reverses an attestation)',
        'endorsement.compliance' => 'View the per-unit missed-days compliance page',

        // User & access administration.
        'users.manage' => 'Create, update and deactivate user accounts',
        'users.manage_residents' => 'Approve, activate and deactivate RESIDENT accounts only',
        'access.manage' => 'Manage the access-control catalog and per-user overrides',
        'settings.manage' => 'Edit runtime settings (mail server, push keys, reminder times)',

        // The departmental roster (Munawib PE-*, LV-02…04, ST-04).
        'people.manage' => 'Manage the roster: people, levels, promotion and roster import',

        // Departmental structure (Munawib UN-*, LV-01, ST-02, ST-06).
        'structure.manage' => 'Manage units, training levels, the calendar, periods and holidays',

        // Master rota (Munawib MR-02/MR-03/MR-05, P1d).
        'rota.view' => 'View the master rota',
        'rota.manage' => 'Create and edit master rota assignments and vacations',

        // Clinics (Munawib CL-05, P1e). ONE new key, not two: DEFINING a clinic is department
        // structure and stays on `structure.manage`; only the department-wide MAP is read by
        // everybody and needs a key of its own.
        'clinics.view' => 'View the weekly clinic map',
    ];

    /**
     * Role-default grants: position => list of capability keys.
     *
     * @var array<int, array<int, string>>
     */
    private const ROLE_DEFAULTS = [
        // Administrator (0): every capability.
        0 => [
            'profile.manage', 'rota.view', 'clinics.view',
            'endorsement.view', 'endorsement.edit', 'endorsement.reopen', 'endorsement.compliance',
            'users.manage', 'users.manage_residents', 'access.manage', 'settings.manage',
            'structure.manage', 'people.manage', 'rota.manage',
        ],
        // Position 1 (Nurse) is RETIRED — no defaults exist for it.
        // Charge Nurse (2): endorsement + read the master rota (owner decision 2, P1d) + read the
        // weekly clinic map (P1e Decision C).
        2 => [
            'profile.manage', 'rota.view', 'clinics.view',
            'endorsement.view', 'endorsement.edit',
        ],
        // Consultant (3): endorsement + read the master rota + read the clinic map.
        3 => [
            'profile.manage', 'rota.view', 'clinics.view',
            'endorsement.view', 'endorsement.edit',
        ],
        // Resident (4): endorsement + read the master rota — MR-05's point is that a resident
        // can see which unit they rotate through next — and read the clinic map, which is CL-05's
        // point for exactly the same reason: they need to know when their unit's clinic runs.
        4 => [
            'profile.manage', 'rota.view', 'clinics.view',
            'endorsement.view', 'endorsement.edit',
        ],
        // Chief Resident (5): a Resident clinically, plus the scoped admin powers. `rota.manage` is
        // NOT here (owner decision 2, 2026-08-10, reversing the 2026-08-09 decision P1d-1 shipped):
        // editing the master rota defaults Administrator-only and an administrator grants it per
        // department from Admin -> Access Control, the same shape `structure.manage` and
        // `people.manage` already ship in.
        5 => [
            'profile.manage', 'rota.view', 'clinics.view',
            'endorsement.view', 'endorsement.edit',
            'users.manage_residents',
        ],
    ];

    public function run(): void
    {
        // 1. Catalog.
        $capabilities = [];
        foreach (self::CATALOG as $key => $label) {
            // firstOrCreate, never updateOrCreate: a label/description an administrator has since
            // reworded is theirs to keep. Only a MISSING description is backfilled.
            $capability = Capability::firstOrCreate(
                ['key' => $key],
                ['label' => $label, 'description' => self::DESCRIPTIONS[$key] ?? null],
            );

            if ($capability->description === null && isset(self::DESCRIPTIONS[$key])) {
                $capability->update(['description' => self::DESCRIPTIONS[$key]]);
            }

            $capabilities[$key] = $capability;
        }

        // 2. Role defaults — applied ONCE per (position, capability), then never re-asserted.
        //    An already-marked pair is skipped outright: whatever role_capabilities says about it
        //    now is the administrator's decision, revocations included.
        $applied = false;
        foreach (self::ROLE_DEFAULTS as $position => $keys) {
            foreach ($keys as $key) {
                $capabilityId = (int) $capabilities[$key]->id;

                $alreadyApplied = AppliedRoleDefault::where('position', $position)
                    ->where('capability_id', $capabilityId)
                    ->exists();

                if ($alreadyApplied) {
                    continue;
                }

                RoleCapability::firstOrCreate([
                    'position' => $position,
                    'capability_id' => $capabilityId,
                ]);

                AppliedRoleDefault::create([
                    'position' => $position,
                    'capability_id' => $capabilityId,
                    'applied_at' => now(),
                ]);

                $applied = true;
            }
        }

        // 3. Bust cached capability sets only when a default actually landed. A no-op re-run
        //    leaves the cache (and every unrelated cache entry) alone.
        if ($applied) {
            AccessControl::flush();
        }
    }
}
