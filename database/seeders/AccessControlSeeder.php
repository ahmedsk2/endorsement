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

        // Departmental structure (Munawib UN-*, LV-01, ST-02, ST-06).
        'structure.manage' => 'Manage units, training levels, the calendar, periods and holidays',
    ];

    /**
     * Role-default grants: position => list of capability keys.
     *
     * @var array<int, array<int, string>>
     */
    private const ROLE_DEFAULTS = [
        // Administrator (0): every capability.
        0 => [
            'profile.manage',
            'endorsement.view', 'endorsement.edit', 'endorsement.reopen', 'endorsement.compliance',
            'users.manage', 'users.manage_residents', 'access.manage', 'settings.manage',
            'structure.manage',
        ],
        // Position 1 (Nurse) is RETIRED — no defaults exist for it.
        // Charge Nurse (2): endorsement.
        2 => [
            'profile.manage',
            'endorsement.view', 'endorsement.edit',
        ],
        // Consultant (3): endorsement.
        3 => [
            'profile.manage',
            'endorsement.view', 'endorsement.edit',
        ],
        // Resident (4): endorsement.
        4 => [
            'profile.manage',
            'endorsement.view', 'endorsement.edit',
        ],
        // Chief Resident (5): a Resident clinically, plus the ONE scoped admin power.
        5 => [
            'profile.manage',
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
