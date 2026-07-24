<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Handover;
use App\Models\HandoverSignoff;
use App\Models\Unit;
use App\Models\User;
use App\Support\AccessControl;
use App\Support\UnitProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shift-endorsement / handover module. The legacy four drifted `*_patintsendorcement`
 * tables collapsed into the single `handovers` table discriminated by `unit_id`; the four
 * units — PICU, NICU, SCBU, WARD — are each first-class (spec §3), with their per-unit
 * variation (identity columns, consultant shape, labels, hue) defined ONCE in
 * `App\Support\UnitProfile` rather than drifting per code path.
 *
 * Gates (routes/web.php owns the `auth`+`cap:` wiring): reads are `endorsement.view`, every write
 * is `endorsement.edit` (legacy server gate [0,2,3,4] — excludes Nurse). The four rich-text fields
 * (disease/details/plan/nevent) are sanitized on write by the `App\Casts\SanitizedHtml` model cast
 * (stored-XSS defense); every write records a PHI-free audit row (ids only).
 */
class EndorsementController extends Controller
{
    /**
     * The four-unit chooser: one card per unit with today's census count and sign-off state,
     * so a missing or unsigned day is visible the moment the app opens (the compliance
     * problem this system exists to fix is FORGETTING).
     */
    public function root(): Response
    {
        $today = now()->format('Y-m-d');

        $units = Unit::whereIn('code', UnitProfile::codes())
            ->get()
            ->sortBy(fn (Unit $u): int => (int) array_search($u->code, UnitProfile::codes(), true))
            ->values()
            ->map(function (Unit $u) use ($today): array {
                $rows = Handover::where('unit_id', $u->id)->whereDate('handover_date', $today)->count();
                $signed = HandoverSignoff::where('unit_id', $u->id)
                    ->whereDate('handover_date', $today)
                    ->whereNotNull('signed_off_at')
                    ->exists();

                return [
                    'code' => $u->code,
                    'name' => $u->name,
                    'bar_class' => UnitProfile::for($u->code)->barClass,
                    'today' => [
                        'date' => $today,
                        'has_sheet' => $rows > 0,
                        'rows' => $rows,
                        'signed_off' => $signed,
                    ],
                ];
            })
            ->values();

        return Inertia::render('Endorsement/Chooser', ['units' => $units]);
    }

    /**
     * The day index for a unit: one entry per handover_date, newest first.
     *
     * G3 — restores the legacy start/end date filter (`PICU-Endorsement.php:120-131`). Both bounds
     * are optional and independent, so one-sided ranges work; the values are validated to `Y-m-d`
     * and bound as query parameters. It is a GET (legacy used a form POST) so a narrowed index is
     * linkable and survives a refresh.
     */
    public function index(Request $request, string $unit): Response
    {
        $u = $this->resolveUnit($unit);

        $filters = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $dates = Handover::query()
            ->where('unit_id', $u->id)
            ->whereNotNull('handover_date')
            ->when($from !== null, fn ($q) => $q->whereDate('handover_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('handover_date', '<=', $to))
            ->selectRaw('handover_date, COUNT(*) as row_count')
            ->groupBy('handover_date')
            ->orderByDesc('handover_date')
            ->get();

        // H/GAP-5 — a signed day must be visibly distinguishable from an unsigned one, so the index
        // carries the attestation state (and who signed) alongside the census count. One query for
        // the whole page, keyed by date.
        $signoffs = HandoverSignoff::query()
            ->where('unit_id', $u->id)
            ->whereNotNull('signed_off_at')
            ->get()
            ->keyBy(fn (HandoverSignoff $s): string => Carbon::parse($s->handover_date)->format('Y-m-d'));

        $dates = $dates->map(function ($r) use ($signoffs): array {
            $date = Carbon::parse($r->handover_date)->format('Y-m-d');
            $s = $signoffs->get($date);

            return [
                'date' => $date,
                'count' => (int) $r->row_count,
                'signed_off' => $s !== null,
                // The frozen snapshot, never a live user lookup (see HandoverSignoff).
                'endorsed_by_name' => $s?->endorsed_by_name,
                'endorsed_to_name' => $s?->endorsed_to_name,
                'endorsement_time' => $s?->endorsement_time,
            ];
        });

        return Inertia::render('Endorsement/Index', [
            'unit' => $this->unitPayload($u),
            'dates' => $dates,
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    /** The editable handover sheet for a unit + date. */
    public function show(Request $request, string $unit, string $date): Response
    {
        $u = $this->resolveUnit($unit);
        $date = $this->normalizeDate($date);

        return Inertia::render('Endorsement/Sheet', [
            'unit' => $this->unitPayload($u),
            'date' => $date,
            'rows' => $this->rowsFor($u, $date),
            // The viewer is passed so the sheet can state up front whether THIS user may reopen a
            // signed day, and who to ask if not.
            'signoff' => $this->signoffPayload($u, $date, $request->user()),
            'staff' => $this->staffPickers(),
            'timeOptions' => HandoverSignoff::TIME_OPTIONS,
        ]);
    }

    /** The printable A4 sheet for a unit + date (its own minimal layout, no app chrome). */
    public function print(string $unit, string $date): Response
    {
        $u = $this->resolveUnit($unit);
        $date = $this->normalizeDate($date);

        return Inertia::render('Endorsement/Print', [
            'unit' => $this->unitPayload($u),
            'date' => $date,
            'rows' => $this->rowsFor($u, $date),
            'signoff' => $this->signoffPayload($u, $date),
        ]);
    }

    /**
     * H / GAP-5 — capture (and optionally SIGN OFF) the day's attestation. Legacy equivalent:
     * `validate-endorsement.php:8-16`, which UPDATEd the `endorsement` day-header row with
     * endorsedby / endorsedto / consultantby / consultantto / time.
     *
     * Two deliberate departures from legacy, both because this is a medico-legal record:
     *  1. `sign_off` is an explicit state transition, not "the fields are filled in". Saving the
     *     pickers without `sign_off` leaves the day UNSIGNED and freely editable; signing requires
     *     at least an endorsing clinician, because an attestation naming nobody attests to nothing.
     *  2. A day that is already signed is LOCKED — a further write is rejected rather than silently
     *     overwriting an existing attestation (legacy re-ran the same UPDATE with no guard, so a
     *     later shift could rewrite a previous shift's sign-off without trace). Corrections go
     *     through `reopenSignoff()`.
     *
     * The staff NAMES are snapshotted here, at write time, so the record is stable if a user is
     * later renamed or deactivated.
     */
    public function updateSignoff(Request $request, string $unit, string $date): RedirectResponse
    {
        $u = $this->resolveUnit($unit);
        $date = $this->normalizeDate($date);

        $data = $request->validate([
            'endorsed_by_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'endorsed_to_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'consultant_by_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'consultant_to_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'endorsement_time' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sign_off' => ['sometimes', 'boolean'],
        ]);

        // `whereDate`, not a plain equality on the attribute: the `date` cast persists
        // 'Y-m-d 00:00:00', so firstOrNew(['handover_date' => 'Y-m-d']) never matches the existing
        // row and inserts a duplicate — which the (unit_id, handover_date) unique index then
        // rejects with a raw 500 instead of the "already signed" guard below.
        $signoff = HandoverSignoff::where('unit_id', $u->id)
            ->whereDate('handover_date', $date)
            ->first()
            ?? new HandoverSignoff(['unit_id' => $u->id, 'handover_date' => $date]);

        if ($signoff->exists && $signoff->isSignedOff()) {
            throw ValidationException::withMessages([
                'sign_off' => 'This handover day is already signed off. Reopen it with a reason before editing the attestation.',
            ]);
        }

        $signoff->institution_id ??= $request->user()?->institution_id;

        foreach (['endorsed_by', 'endorsed_to', 'consultant_by', 'consultant_to'] as $field) {
            if (! array_key_exists($field.'_user_id', $data)) {
                continue;
            }

            $userId = $data[$field.'_user_id'];
            $signoff->{$field.'_user_id'} = $userId;
            // Freeze the name at write time; a later rename must not rewrite a signed sheet.
            $signoff->{$field.'_name'} = $userId === null
                ? null
                : User::whereKey($userId)->value('full_name');
        }

        if (array_key_exists('endorsement_time', $data)) {
            $submitted = $data['endorsement_time'];

            if ($submitted === null || trim((string) $submitted) === '') {
                // Explicitly cleared.
                $signoff->endorsement_time = null;
                $signoff->endorsement_time_minutes = null;
            } else {
                $label = HandoverSignoff::normalizeTimeLabel((string) $submitted);

                // Legacy accepted ANY free text here, which is how a medico-legal record ends up
                // saying "after rounds". A time that names no clock time is now refused at the
                // controller (the model/import path is unchanged, so no historical row is affected).
                if ($label === null) {
                    throw ValidationException::withMessages([
                        'endorsement_time' => 'Enter the handover time as a clock time (for example 02:40), or pick one of the shift times.',
                    ]);
                }

                // Display string = what the sheet says (legacy quick-picks kept verbatim);
                // minutes = what it means. See the 2026_07_19_140000 migration for the rationale.
                $signoff->endorsement_time = $label;
                $signoff->endorsement_time_minutes = HandoverSignoff::parseTimeToMinutes($label);
            }
        }

        $signing = (bool) ($data['sign_off'] ?? false);

        if ($signing && $signoff->endorsed_by_user_id === null) {
            throw ValidationException::withMessages([
                'endorsed_by_user_id' => 'Select the endorsing clinician before signing off.',
            ]);
        }

        if ($signing) {
            $signoff->signed_off_at = now();
            $signoff->signed_off_by_user_id = $request->user()?->getKey();
            // A fresh signature supersedes any earlier reopen; the reason stays for the trail.
            $signoff->reopened_at = null;
        }

        $signoff->save();

        AuditLog::record(
            $signing ? 'endorsement_signoff' : 'endorsement_signoff_draft',
            'unit='.$u->id.' date='.$date.' signoff='.$signoff->id,
            $request->user()?->getKey(),
            $request->ip(),
        );

        return redirect()->route('endorsement.show', ['unit' => $u->code, 'date' => $date])
            ->with('status', $signing ? 'Handover signed off.' : 'Sign-off details saved.');
    }

    /**
     * H / GAP-5 — the audited CORRECTION path. Sign-off must be reversible (a wrong receiving
     * resident selected at 07:30 has to be fixable) but never silently: a reason is mandatory, the
     * reversal is stamped on the row and written to `audit_log`, and NOTHING is erased — the
     * endorser name snapshots stay exactly as they were signed. This is the only way a signed day
     * becomes editable again.
     *
     * GATED ON ITS OWN CAPABILITY, `endorsement.reopen`. Reopening un-signs a record a named
     * clinician put their name to, which is a medico-legal act rather than an editing one.
     * `endorsement.edit` is the right gate for writing a sheet and the wrong one for reversing
     * someone else's signature, so the route stays on `endorsement.edit` (so the refusal is ours to
     * word) and the capability check below is the real gate.
     *
     * TASK 3(a) — it used to be `control.admin`. Same day-one holders (Administrator only, seeded as
     * a role default), but the power is now separable: the owner can grant `endorsement.reopen` to a
     * role or to a NAMED senior clinician without handing over user management and access control.
     * That closes the operational hole this comment previously recorded as an accepted cost — a ward
     * that signed with the wrong receiving clinician at 03:00 no longer has to wait for admin hours,
     * PROVIDED the owner has authorised somebody. Nothing about the reversal itself is relaxed: the
     * written reason stays mandatory, the stamp stays, no endorser name is erased, and both the
     * reopen and every refused attempt are audited PHI-free.
     *
     * The sheet tells a non-holder BEFORE they try (`signoff.can_reopen` + `signoff.reopen_contacts`),
     * and the refusal below names the people who ACTUALLY hold the capability — resolved from the
     * grant tables, never from a hard-coded role — instead of returning a bare 403.
     */
    public function reopenSignoff(Request $request, string $unit, string $date): RedirectResponse
    {
        $u = $this->resolveUnit($unit);
        $date = $this->normalizeDate($date);

        if (! $this->canReopen($request->user())) {
            // PHI-free: unit + date + actor only. The submitted reason is NEVER logged — it is free
            // text and could name a patient.
            AuditLog::record(
                'endorsement_signoff_reopen_denied',
                'unit='.$u->id.' date='.$date,
                $request->user()?->getKey(),
                $request->ip(),
            );

            abort(403, $this->reopenDeniedMessage());
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $signoff = HandoverSignoff::where('unit_id', $u->id)
            ->whereDate('handover_date', $date)
            ->firstOrFail();

        if (! $signoff->isSignedOff()) {
            throw ValidationException::withMessages([
                'reason' => 'This handover day is not signed off.',
            ]);
        }

        $signedAt = $signoff->signed_off_at?->toDateTimeString();

        $signoff->forceFill([
            'signed_off_at' => null,
            'reopened_at' => now(),
            'reopened_by_user_id' => $request->user()?->getKey(),
            'reopen_reason' => $data['reason'],
        ])->save();

        // PHI-free: unit/date/ids and the superseded stamp only — never the reason text, which is
        // free-form and could name a patient.
        AuditLog::record(
            'endorsement_signoff_reopen',
            'unit='.$u->id.' date='.$date.' signoff='.$signoff->id.' was_signed_at='.$signedAt
                .' was_signed_by='.$signoff->signed_off_by_user_id,
            $request->user()?->getKey(),
            $request->ip(),
        );

        return redirect()->route('endorsement.show', ['unit' => $u->code, 'date' => $date])
            ->with('status', 'Handover reopened for correction. The reason has been recorded.');
    }

    /**
     * Carry a prior day's census FORWARD into a new handover_date (the legacy "new day").
     * Each still-listed patient row is copied to the target date preserving the census (bed, MRN,
     * name, dob/age/ward_unit, disease, details, plan) AND `nevent` — the "To be followed" notes
     * carry verbatim, exactly as the deployed legacy system does (spec ruling 1; legacy
     * *-endorsement-newday.php copies every field). Idempotent per target date: if the target
     * already has rows the carry-forward is skipped (no duplication).
     *
     * THE CARRY DIALOG (spec ruling 2). Legacy copied strictly from target-minus-one-day, so a
     * gap yielded a silently empty sheet. Here:
     *   - the most recent prior day IS yesterday  -> carry silently (the normal morning flow);
     *   - the most recent prior day is OLDER      -> nothing is created; the client is handed
     *     `carry_prompt` (the last endorsement date) and re-submits with an explicit
     *     `carry_choice` of `carry` (bring that census forward) or `blank` (start empty);
     *   - no prior day at all                     -> the target is seeded with one blank row so
     *     the day actually exists, appears on the index, and is editable.
     */
    public function newDay(Request $request, string $unit): RedirectResponse
    {
        $u = $this->resolveUnit($unit);

        $data = $request->validate([
            'date' => ['sometimes', 'nullable', 'string'],
            'carry_choice' => ['sometimes', 'nullable', 'in:carry,blank'],
        ]);

        $target = $this->parseDateOrToday($data['date'] ?? null);
        $choice = $data['carry_choice'] ?? null;

        $alreadyExists = Handover::where('unit_id', $u->id)
            ->whereDate('handover_date', $target)
            ->exists();

        if ($alreadyExists) {
            return redirect()->route('endorsement.show', ['unit' => $u->code, 'date' => $target]);
        }

        // The most recent PRIOR day for this unit is the census source.
        $source = Handover::where('unit_id', $u->id)
            ->whereDate('handover_date', '<', $target)
            ->max('handover_date');
        $sourceDate = $source !== null ? Carbon::parse($source)->format('Y-m-d') : null;

        $isConsecutive = $sourceDate !== null
            && $sourceDate === Carbon::parse($target)->subDay()->format('Y-m-d');

        // A gap needs an explicit human choice — surface the dialog instead of guessing.
        if ($sourceDate !== null && ! $isConsecutive && $choice === null) {
            return back()->with('carry_prompt', [
                'unit' => $u->code,
                'date' => $target,
                'last_date' => $sourceDate,
            ]);
        }

        $carried = 0;

        if ($sourceDate !== null && $choice !== 'blank') {
            $carried = DB::transaction(function () use ($u, $sourceDate, $target): int {
                $rows = Handover::where('unit_id', $u->id)
                    ->whereDate('handover_date', $sourceDate)
                    ->orderBy('id')
                    ->get();

                foreach ($rows as $row) {
                    Handover::create([
                        'institution_id' => $row->institution_id,
                        'unit_id' => $u->id,
                        'handover_date' => $target,
                        'bed' => $row->bed,
                        'mrn' => $row->mrn,
                        'patient_name' => $row->patient_name,
                        'dob' => $row->dob,
                        'age' => $row->age,
                        'ward_unit' => $row->ward_unit,
                        'disease' => $row->disease,
                        'details' => $row->details,
                        'plan' => $row->plan,
                        // Ruling 1 — "To be followed" carries verbatim (legacy parity).
                        'nevent' => $row->nevent,
                    ]);
                }

                return $rows->count();
            });
        }

        // Nothing carried (no prior day, or an explicit blank start) — seed the day so it
        // exists and is editable rather than silently doing nothing (a day is only "real"
        // here if it has at least one row).
        if ($carried === 0) {
            Handover::create([
                'institution_id' => $request->user()?->institution_id,
                'unit_id' => $u->id,
                'handover_date' => $target,
                'author_user_id' => $request->user()?->getKey(),
            ]);
        }

        AuditLog::record('endorsement_new_day', 'unit='.$u->id.' date='.$target.' carried='.$carried, $request->user()?->getKey(), $request->ip());

        return redirect()->route('endorsement.show', ['unit' => $u->code, 'date' => $target])
            ->with('status', $carried > 0
                ? 'New day started — the census carried forward, including the "To be followed" notes.'
                : 'New day started with a blank sheet.');
    }

    /** Create a new handover row for a unit + date. */
    public function storeRow(Request $request, string $unit, string $date): RedirectResponse
    {
        $u = $this->resolveUnit($unit);
        $date = $this->normalizeDate($date);

        $data = $this->validateRow($request, UnitProfile::for($u->code));

        $row = Handover::create(array_merge($data, [
            'unit_id' => $u->id,
            'institution_id' => $request->user()?->institution_id,
            'handover_date' => $date,
            'author_user_id' => $request->user()?->getKey(),
        ]));

        AuditLog::record('endorsement_row_create', 'row='.$row->id.' unit='.$u->id, $request->user()?->getKey(), $request->ip());

        return redirect()->route('endorsement.show', ['unit' => $u->code, 'date' => $date])
            ->with('status', 'Row added.');
    }

    /** Edit an existing handover row (rich-text fields sanitized by the model cast). */
    public function updateRow(Request $request, Handover $handover): RedirectResponse
    {
        $this->assertEnabledUnitRow($handover);

        $data = $this->validateRow($request, UnitProfile::for((string) $handover->unit?->code));

        $handover->update($data);

        AuditLog::record('endorsement_row_update', 'row='.$handover->id, $request->user()?->getKey(), $request->ip());

        return back()->with('status', 'Saved.');
    }

    /** Remove a handover row (soft delete). */
    public function deleteRow(Request $request, Handover $handover): RedirectResponse
    {
        $this->assertEnabledUnitRow($handover);

        $id = $handover->id;
        $handover->delete();

        AuditLog::record('endorsement_row_delete', 'row='.$id, $request->user()?->getKey(), $request->ip());

        return back()->with('status', 'Row removed.');
    }

    /**
     * The row-write verbs bind `{handover}` by BARE ID, so they need the same unit scoping
     * every read path gets from resolveUnit(). A row belonging to a unit outside the
     * four-profile surface 404s, matching what a read of the same row would do.
     */
    private function assertEnabledUnitRow(Handover $handover): void
    {
        if (! in_array(strtoupper((string) $handover->unit?->code), UnitProfile::codes(), true)) {
            abort(404);
        }
    }

    /**
     * Resolve + validate the `{unit}` route param against the four first-class units.
     * Lowercase URLs keep resolving (legacy links and the nav both use them).
     */
    private function resolveUnit(string $unit): Unit
    {
        $code = strtoupper($unit);

        if (! in_array($code, UnitProfile::codes(), true)) {
            abort(404);
        }

        return Unit::where('code', $code)->firstOrFail();
    }

    /**
     * Validate an incoming row against ITS UNIT's profile. Rich-text fields are length-bounded
     * here and sanitized on write by the model cast. Identity columns another unit owns (dob is
     * NICU/SCBU; age + ward_unit are WARD) are simply not validated for this unit, so a client
     * that submits them has them dropped rather than persisted.
     *
     * @return array<string, mixed>
     */
    private function validateRow(Request $request, UnitProfile $profile): array
    {
        $rules = [
            'bed' => ['sometimes', 'nullable', 'string', 'max:100'],
            'mrn' => ['sometimes', 'nullable', 'string', 'max:100'],
            'patient_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'disease' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'details' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'plan' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'nevent' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ];

        foreach ($profile->extraRowFields as $field) {
            $rules[$field] = $field === 'dob'
                ? ['sometimes', 'nullable', 'date']
                : ['sometimes', 'nullable', 'string', 'max:100'];
        }

        return $request->validate($rules);
    }

    /**
     * The rows for a unit + date, shaped for the sheet / print pages. Rich-text is pre-sanitized
     * (on write) so it is safe to render.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rowsFor(Unit $unit, string $date): Collection
    {
        return Handover::query()
            ->where('unit_id', $unit->id)
            ->whereDate('handover_date', $date)
            ->orderBy('id')
            ->get()
            ->sort($this->bedComparator(...))
            ->values()
            ->map(fn (Handover $h): array => [
                'id' => $h->id,
                // Normalized so a NULL bed and an empty bed are one thing to the client (both are
                // simply "no bed assigned yet") and the census input always binds to a string.
                'bed' => (string) $h->bed,
                'mrn' => $h->mrn,
                'patient_name' => $h->patient_name,
                // Per-unit identity columns; the UnitProfile decides which of these render.
                'dob' => $h->dob?->format('Y-m-d H:i'),
                'age' => $h->age,
                'ward_unit' => $h->ward_unit,
                'disease' => $h->disease,
                'details' => $h->details,
                'plan' => $h->plan,
                'nevent' => $h->nevent,
            ]);
    }

    /**
     * Legacy row ordering (`picu-endorsement-patients.php:240-255`): beds ascending, with BLANK beds
     * forced LAST. Plain `ORDER BY bed` broke both halves of that — a blank/NULL bed sorted FIRST,
     * so a freshly-added row jumped to the head of the sheet, and beds compared as strings put bed
     * 10 before bed 2. `strnatcasecmp` restores the numeric-aware ordering while still handling the
     * alphanumeric bed labels ("A1", "ISO-2") that a pure numeric cast would collapse to zero.
     * Sorting happens in PHP because natural ordering is not portable across SQLite and MySQL.
     */
    private function bedComparator(Handover $a, Handover $b): int
    {
        $left = trim((string) $a->bed);
        $right = trim((string) $b->bed);

        if ($left === '' || $right === '') {
            // Two blanks tie (the stable sort then falls back to the id ordering applied in SQL).
            return $left === $right ? 0 : ($left === '' ? 1 : -1);
        }

        return strnatcasecmp($left, $right);
    }

    /**
     * H / GAP-5 — the day's attestation, shaped for the sheet and the printed A4. Always an array
     * (never null) so both pages can render an explicit "not signed" state, which is what the legacy
     * print sheet did with its "Not Selected" placeholders.
     *
     * The `*_name` values are the FROZEN snapshots taken at sign-off, deliberately NOT re-resolved
     * through the FK: what a signed sheet says must not change when a user is renamed.
     *
     * @return array<string, mixed>
     */
    private function signoffPayload(Unit $unit, string $date, ?User $viewer = null): array
    {
        $s = HandoverSignoff::where('unit_id', $unit->id)
            ->whereDate('handover_date', $date)
            ->first();

        $canReopen = $this->canReopen($viewer);

        return [
            // Reopening needs `endorsement.reopen`. The sheet says up front whether THIS viewer has
            // it, and if not, names the people who do — so a ward is never left guessing at the
            // point it discovers it cannot self-correct.
            'can_reopen' => $canReopen,
            'reopen_contacts' => $canReopen ? [] : $this->reopenContacts(),
            'signed_off' => $s?->isSignedOff() ?? false,
            'signed_off_at' => $s?->signed_off_at?->format('Y-m-d H:i'),
            'signed_off_by_name' => $s?->signedOffBy?->full_name,
            'endorsed_by_user_id' => $s?->endorsed_by_user_id,
            'endorsed_by_name' => $s?->endorsed_by_name,
            'endorsed_to_user_id' => $s?->endorsed_to_user_id,
            'endorsed_to_name' => $s?->endorsed_to_name,
            'consultant_by_user_id' => $s?->consultant_by_user_id,
            'consultant_by_name' => $s?->consultant_by_name,
            'consultant_to_user_id' => $s?->consultant_to_user_id,
            'consultant_to_name' => $s?->consultant_to_name,
            'endorsement_time' => $s?->endorsement_time,
            'reopened_at' => $s?->reopened_at?->format('Y-m-d H:i'),
            'reopen_reason' => $s?->reopen_reason,
        ];
    }

    /**
     * The roles that may be named as ENDORSED BY / ENDORSED TO — the clinician who personally
     * handed over, and the one who personally received.
     *
     * Legacy sourced both from `members WHERE position='4'` — RESIDENTS ALONE
     * (`picu-endorsement-patients.php:397`). A consultant or charge nurse who actually did the
     * handover therefore could not be recorded at all, so the sheet named the wrong person or
     * nobody. Widened to Charge Nurse (2), Consultant (3) and Resident (4).
     *
     * NURSE (1) is deliberately EXCLUDED: the legacy server gate for every endorsement write is
     * [0,2,3,4], which excludes Nurse, and it is carried forward as `endorsement.edit`. A role that
     * may not write or sign this sheet must not be nameable as the clinician attesting to it.
     * Nothing in the legacy behaviour offers a reason to include them.
     *
     * ADMINISTRATOR (0) is also excluded: it is an account-management role, not a bedside one.
     *
     * @var list<int>
     */
    private const ENDORSER_POSITIONS = [2, 3, 4];

    /**
     * The roles offered for the two CONSULTANT fields. These name the COVERING / RECEIVING
     * consultant — a different question from who personally handed over — so this list stays
     * position 3 alone.
     *
     * @var list<int>
     */
    private const CONSULTANT_POSITIONS = [3];

    /**
     * The staff pickers behind the four sign-off selects. Legacy left the two consultant fields as
     * FREE TEXT — which is how a handover sheet ends up attesting to a misspelled name. Every list
     * here is real, ACTIVE user accounts. The chosen NAME is frozen into a `*_name` snapshot at
     * write time (updateSignoff), so a later rename or deactivation cannot rewrite a signed sheet;
     * an inactive account is merely no longer OFFERED.
     *
     * @return array{endorsers: list<array{id: int, name: string}>, consultants: list<array{id: int, name: string}>}
     */
    private function staffPickers(): array
    {
        /** @param list<int> $positions */
        $byPositions = function (array $positions): array {
            return User::query()
                ->whereIn('position', $positions)
                ->where('active', true)
                ->orderBy('full_name')
                ->get(['id', 'full_name'])
                ->map(fn (User $u): array => ['id' => $u->id, 'name' => (string) $u->full_name])
                ->all();
        };

        return [
            'endorsers' => $byPositions(self::ENDORSER_POSITIONS),
            'consultants' => $byPositions(self::CONSULTANT_POSITIONS),
        ];
    }

    /**
     * The capability that permits reopening a signed handover day.
     *
     * Task 3(a) — this was `control.admin`, which conflated "may reverse an attestation" with "may
     * administer the system". The consequence was operational: the only way to let a senior
     * clinician correct a wrong sign-off outside admin hours was to grant them user management and
     * access control too. It is now its OWN capability, default-granted to Administrator alone
     * (Database\Seeders\AccessControlSeeder), which the owner widens per role or per named user.
     */
    private const REOPEN_CAPABILITY = 'endorsement.reopen';

    /**
     * The ACTIVE accounts that actually hold the reopen capability, by name — so a ward that cannot
     * reopen a signed handover is told exactly WHO to ask instead of being left guessing.
     *
     * Resolved from the capability itself rather than from a hard-coded position. Once the power is
     * grantable, "ask an Administrator" becomes wrong in both directions: it sends the ward to an
     * administrator who was explicitly DENIED the capability, and it hides the consultant who was
     * granted it. Staff names only; no PHI.
     *
     * @return list<string>
     */
    private function reopenContacts(): array
    {
        return array_values(array_map(
            fn (User $u): string => (string) $u->full_name,
            AccessControl::holdersOf(self::REOPEN_CAPABILITY),
        ));
    }

    /**
     * Whether this user may REOPEN a signed handover. See reopenSignoff() for the reasoning.
     */
    private function canReopen(?User $user): bool
    {
        return $user !== null && AccessControl::allows($user, self::REOPEN_CAPABILITY);
    }

    /**
     * The refusal text for a reopen attempt by someone without the capability. It states the rule
     * AND names the people who can act, because a bare 403 leaves a ward at 03:00 with a wrong sheet
     * and no idea what to do next.
     */
    private function reopenDeniedMessage(): string
    {
        $contacts = $this->reopenContacts();

        $who = $contacts === []
            // No holder is a real, reportable state (every holder deactivated or denied). Say so
            // plainly rather than naming a role that may not hold the capability either.
            ? 'whoever your unit has authorised to reopen handovers'
            : implode(', ', $contacts);

        return 'Reopening a signed handover needs the “reopen handover” permission, because it '
            .'reverses another clinician\'s attestation. The sheet stays signed as it is — ask '
            .$who.' to reopen it, and give them the reason for the correction.';
    }

    /**
     * @return array{code: string, name: string, profile: array<string, mixed>}
     */
    private function unitPayload(Unit $unit): array
    {
        return [
            'code' => $unit->code,
            'name' => $unit->name,
            'profile' => UnitProfile::for($unit->code)->toArray(),
        ];
    }

    /** Normalize a `{date}` route param to `Y-m-d`, rejecting anything unparseable with a 404. */
    private function normalizeDate(string $date): string
    {
        $ts = strtotime($date);

        if ($ts === false) {
            abort(404);
        }

        return date('Y-m-d', $ts);
    }

    /** Parse a submitted date to `Y-m-d`, defaulting to today's date when absent/empty. */
    private function parseDateOrToday(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '' && ($ts = strtotime($value)) !== false) {
            return date('Y-m-d', $ts);
        }

        return now()->format('Y-m-d');
    }
}
