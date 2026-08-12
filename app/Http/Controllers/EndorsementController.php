<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Handover;
use App\Models\HandoverSignoff;
use App\Models\Person;
use App\Models\Unit;
use App\Models\UnitFieldDefinition;
use App\Models\User;
use App\Rules\MaxSanitizedBytes;
use App\Support\AccessControl;
use App\Support\Calendar;
use App\Support\MissedDays;
use App\Support\SignoffPickers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shift-endorsement / handover module. The legacy four drifted `*_patintsendorcement`
 * tables collapsed into the single `handovers` table discriminated by `unit_id`; the four
 * units — PICU, NICU, SCBU, WARD — are each first-class (spec §3), with their per-unit
 * variation (identity columns, consultant shape, labels, hue) defined ONCE in
 * the unit's own `profile()` rather than drifting per code path.
 *
 * Gates (routes/web.php owns the `auth`+`cap:` wiring): reads are `endorsement.view`, every write
 * is `endorsement.edit` (legacy server gate [0,2,3,4] — excludes Nurse). The four rich-text fields
 * (disease/details/plan/nevent) are sanitized on write by the `App\Casts\SanitizedHtml` model cast
 * (stored-XSS defense); every write records a PHI-free audit row (ids only).
 */
class EndorsementController extends Controller
{
    /**
     * Spec §10.4 — a run of missing days longer than this collapses into one summary listing
     * entry instead of one row per date. Moved server-side by Task 6 (Decision A): this used to
     * be a client constant (`Index.vue`'s deleted `GAP_RENDER_LIMIT`) guarding a client-side
     * `new Date()` loop.
     */
    private const GAP_RENDER_LIMIT = 7;

    /**
     * The four-unit chooser: one card per unit with today's census count and sign-off state,
     * so a missing or unsigned day is visible the moment the app opens (the compliance
     * problem this system exists to fix is FORGETTING).
     */
    public function root(Request $request): Response
    {
        $today = Calendar::todayYmd();

        $units = Unit::query()->active()->ordered()
            ->get()
            ->map(function (Unit $u) use ($today): array {
                $rows = Handover::where('unit_id', $u->id)->whereDate('handover_date', $today)->count();
                $signed = HandoverSignoff::where('unit_id', $u->id)
                    ->whereDate('handover_date', $today)
                    ->whereNotNull('signed_off_at')
                    ->exists();

                return [
                    'code' => $u->code,
                    'name' => $u->name,
                    'bar_class' => $u->profile()->barClass,
                    'today' => [
                        'date' => $today,
                        'has_sheet' => $rows > 0,
                        'rows' => $rows,
                        'signed_off' => $signed,
                    ],
                ];
            })
            ->values();

        // The account-setup checklist. Anything unfinished is surfaced HERE, on the first
        // screen after sign-in, because a signature or second factor that is "meant to be
        // set up later" never is. Booleans only — no addresses, no secrets.
        $me = $request->user();
        $setup = [
            'email_verified' => $me?->hasVerifiedEmail() ?? true,
            'has_email' => filled($me?->member_email),
            'two_factor' => $me?->activeTwoFactorMethod() !== null,
            'has_signature' => $me?->hasSignature() ?? true,
        ];
        $setup['complete'] = $setup['email_verified'] && $setup['two_factor'] && $setup['has_signature'];

        return Inertia::render('Endorsement/Chooser', [
            'units' => $units,
            'setup' => $setup,
        ]);
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
            ->keyBy(fn (HandoverSignoff $s): string => Calendar::ymd($s->handover_date));

        $dates = $dates->map(function ($r) use ($signoffs): array {
            $date = Calendar::ymd($r->handover_date);
            $s = $signoffs->get($date);

            return [
                // UX-04 dual dating — `Calendar::label()` is the one shape every screen renders
                // a date as, so `date`/`hijri`/`weekend` come from it rather than being
                // recomputed here.
                ...Calendar::label($date),
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
            // Decision A — the merged day/gap/gap-summary listing the client used to build
            // itself with `new Date()` arithmetic (localYmd/datesBetween, deleted in Task 6) is
            // now computed here, so `resources/js` performs no date construction at all.
            'listing' => $this->buildListing($dates),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    /**
     * Spec §10.4 — merge the day list (newest first) with inline "no endorsement" gap markers,
     * replacing the client-side computation Decision A removes. A run of missing calendar days
     * between two consecutive known dates is enumerated via `Calendar::datesBetween()` and
     * either listed individually (each dual-dated) or, past GAP_RENDER_LIMIT, collapsed into
     * one summary entry — exactly the split the deleted `Index.vue` computed property made,
     * just computed here instead of in the browser.
     *
     * @param  Collection<int, array<string, mixed>>  $dates  newest first
     * @return list<array<string, mixed>>
     */
    private function buildListing(Collection $dates): array
    {
        $items = $dates->values();
        $listing = [];

        foreach ($items as $i => $entry) {
            $listing[] = ['type' => 'day', ...$entry];

            $next = $items->get($i + 1);
            if ($next === null) {
                continue;
            }

            // Strictly between the older ($next) and newer ($entry) known dates — both ends
            // exclusive, so trim the inclusive result Calendar::datesBetween() returns.
            $span = Calendar::datesBetween($next['date'], $entry['date']);
            $missing = array_slice($span, 1, -1);

            if ($missing === []) {
                continue;
            }

            if (count($missing) > self::GAP_RENDER_LIMIT) {
                $listing[] = [
                    'type' => 'gap-summary',
                    'count' => count($missing),
                    'from' => $next['date'],
                    'to' => $entry['date'],
                ];

                continue;
            }

            // Newest first, matching the list.
            foreach (array_reverse($missing) as $date) {
                $listing[] = ['type' => 'gap', ...Calendar::label($date)];
            }
        }

        return $listing;
    }

    /**
     * The missed-days view (spec §10.3): per unit, how many days in the chosen range have
     * no signed endorsement, expandable to the missing dates themselves. Counts and dates
     * only — the page carries no patient data. This is the system's ONLY aggregate.
     */
    public function compliance(Request $request): Response
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $from = $filters['from'] ?? Calendar::addDays(Calendar::todayYmd(), -29)->format(Calendar::YMD);
        $to = $filters['to'] ?? Calendar::todayYmd();

        $units = Unit::query()->active()->ordered()
            ->get()
            ->map(function (Unit $u) use ($from, $to): array {
                $result = MissedDays::forRange($u->id, $from, $to);

                return [
                    'code' => $u->code,
                    'name' => $u->name,
                    'bar_class' => $u->profile()->barClass,
                    'total_days' => $result['total_days'],
                    'missed' => $result['missed'],
                ];
            });

        return Inertia::render('Endorsement/Compliance', [
            'units' => $units,
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    /**
     * One-tap access (Phase 7.1): the PWA opens here; land on the remembered unit's
     * CURRENT sheet (creating nothing), or the chooser when no unit is remembered yet.
     */
    public function today(Request $request): RedirectResponse
    {
        $unit = Unit::find($request->user()?->preferred_unit_id);

        if ($unit === null || ! $unit->active) {
            return redirect()->route('endorsement.root');
        }

        return redirect()->route('endorsement.show', [
            'unit' => $unit->code,
            'date' => Calendar::todayYmd(),
        ]);
    }

    /** The editable handover sheet for a unit + date. */
    public function show(Request $request, string $unit, string $date): Response
    {
        $u = $this->resolveUnit($unit);
        $date = $this->normalizeDate($date);

        // Remember the unit so /endorsement/today lands here next time. Quiet write; not
        // audited (a UI preference, not clinical data).
        //
        // ONLY on an in-app navigation. This is a state change performed by a GET, which is
        // exactly what a cross-site link can trigger once the session cookie is SameSite=lax
        // rather than strict — and lax is what phones need, since strict withholds the cookie
        // on anything opened from a mail app or a home-screen shortcut. Requiring the Inertia
        // header means only a navigation from inside the app can move the preference; the
        // consequence of a stranger's link is now nothing at all.
        $user = $request->user();
        if ($user !== null && $request->hasHeader('X-Inertia') && $user->preferred_unit_id !== $u->id) {
            $user->forceFill(['preferred_unit_id' => $u->id])->save();
        }

        $rows = $this->rowsFor($u, $date);

        // Fetched once and handed to both signoffPayload() and staffPickers(): the latter needs
        // the STORED person ids so a value the current picker no longer offers is still shown,
        // flagged retired, rather than silently dropped from the select (finding 9).
        $signoffRow = HandoverSignoff::where('unit_id', $u->id)
            ->whereDate('handover_date', $date)
            ->first();

        // PDPL Art. 19 / HIPAA §164.312(b): READS of patient data are auditable events,
        // not just writes — "who looked at this child's handover, and when" is the
        // question an investigation actually asks. Unit id, date and a row COUNT only;
        // never a name or an MRN.
        AuditLog::record(
            'endorsement_view',
            'unit='.$u->id.' date='.$date.' rows='.$rows->count(),
            $request->user()?->getKey(),
            $request->ip(),
        );

        return Inertia::render('Endorsement/Sheet', [
            'unit' => $this->unitPayload($u),
            'date' => $date,
            // UX-04 dual dating, and Decision A's adjacent-day navigation — Sheet.vue's deleted
            // `nextDate` computed built a `new Date()` from the route param to find "tomorrow";
            // the server now hands over both neighbours already formatted, so "Start next day"
            // and a "Previous day" link need no client-side date arithmetic at all.
            'date_hijri' => Calendar::hijriLabel($date),
            'next_date' => Calendar::addDays($date, 1)->format(Calendar::YMD),
            'previous_date' => Calendar::addDays($date, -1)->format(Calendar::YMD),
            'rows' => $rows,
            // The viewer is passed so the sheet can state up front whether THIS user may reopen a
            // signed day, and who to ask if not.
            'signoff' => $this->signoffPayload($u, $date, $request->user(), $signoffRow),
            'staff' => $this->staffPickers($signoffRow),
            'timeOptions' => HandoverSignoff::TIME_OPTIONS,
        ]);
    }

    /** The printable A4 sheet for a unit + date (its own minimal layout, no app chrome). */
    public function print(Request $request, string $unit, string $date): Response
    {
        $u = $this->resolveUnit($unit);
        $date = $this->normalizeDate($date);
        $rows = $this->rowsFor($u, $date);

        // A distinct action from a screen read: a printed sheet leaves the system and the
        // building, so "who printed this day" is its own auditable question.
        AuditLog::record(
            'endorsement_print',
            'unit='.$u->id.' date='.$date.' rows='.$rows->count(),
            $request->user()?->getKey(),
            $request->ip(),
        );

        return Inertia::render('Endorsement/Print', [
            'unit' => $this->unitPayload($u),
            'date' => $date,
            'rows' => $rows,
            'signoff' => $this->signoffPayload($u, $date),
            // Paper is the one egress channel with no technical control downstream. The
            // print is audited, but the SHEET itself carried nothing identifying who
            // produced it, so a census found on a desk or in a bin was anonymous. This
            // makes it attributable — which is also the cheapest deterrent there is.
            'printed_by' => (string) ($request->user()?->full_name ?? ''),
            'printed_at' => Calendar::now()->format('Y-m-d H:i'),
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

        // The id must be someone the picker would actually have OFFERED, per field. A bare
        // `exists:users,id` accepted any row in the table and the handler below then froze that
        // person's name AND their handwritten signature onto a medico-legal record; D9 narrows
        // it further — endorsers need a claimed, live account, consultants need only be an
        // active person — and offer and rule come from one predicate so they cannot drift.
        $endorser = SignoffPickers::rule(SignoffPickers::endorserPredicate());
        $consultant = SignoffPickers::rule(SignoffPickers::consultantPredicate());

        $data = $request->validate([
            'endorsed_by_person_id' => ['sometimes', 'nullable', 'integer', $endorser],
            'endorsed_to_person_id' => ['sometimes', 'nullable', 'integer', $endorser],
            'consultant_by_person_id' => ['sometimes', 'nullable', 'integer', $consultant],
            'consultant_to_person_id' => ['sometimes', 'nullable', 'integer', $consultant],
            'endorsement_time' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sign_off' => ['sometimes', 'boolean'],
        ]);

        // `whereDate`, not a plain equality on the attribute. THIS IS THE CANONICAL STATEMENT of
        // the hazard; `Period::booted()`, `MasterRotaAssignment::booted()`, `Vacation::booted()`
        // (whose `scopeIntersecting()` writes the same `whereDate` pair), `ClinicRoster::
        // rotatingOn()`, `RotaController::landingYear()` and `DepartmentSetup::steps()` all cite
        // it rather than restating it.
        //
        // MEASURED, in-process on this tree (SQLite 3.53): Laravel's base query grammar formats a
        // `date` cast as `Y-m-d H:i:s` — only the SQL Server grammar overrides that, so both
        // engines are SENT 'Y-m-d 00:00:00' — and SQLite has no date type, so a NUMERIC-affinity
        // column keeps that string verbatim. The stored value therefore sorts strictly AFTER the
        // bare `Y-m-d` needle for the very same day, and the whole error is confined to that
        // BOUNDARY DAY. Measured over five consecutive rows against a plain `Y-m-d` string,
        // comparing a bare operator with the `whereDate()` it should equal:
        //
        //     =    bare matches NOTHING                    (the boundary day is lost)
        //     <=   bare EXCLUDES the boundary day          (an inclusive bound silently isn't)
        //     >    bare INCLUDES the boundary day          (an exclusive bound silently isn't)
        //     <    bare agrees with whereDate()
        //     >=   bare agrees with whereDate()
        //
        // Three of five are wrong and two are right by luck, which is the reason the rule is
        // stated over the COLUMN and not over the operator: nobody should have to work out per
        // call site which of five comparisons happens to be safe. Here it meant
        // firstOrNew(['handover_date' => 'Y-m-d']) never matching the existing row and inserting
        // a duplicate — which the (unit_id, handover_date) unique index then rejects with a raw
        // 500 instead of the "already signed" guard below.
        //
        // WHICH ENGINE BEHAVES WHICH WAY IS NOT THE POINT, and six comments citing this one used
        // to get it backwards (they blamed the engine production runs). BOTH halves are now
        // measured — the MySQL half on 2026-08-12 against MySQL 8.4.10, the digest
        // docker-compose.production.yml pins (docs/REHEARSAL-MYSQL-2026-08-12.md section 8). MySQL
        // stores 'Y-m-d 00:00:00' into a DATE column as 'Y-m-d', and ALL FIVE operators agree with
        // whereDate() there; every divergence in the table above is SQLite's. A DATETIME column
        // written by the same cast agrees too, so the safety is not only DATE's truncation — MySQL
        // coerces the 'Y-m-d' LITERAL to the column's type before comparing, where SQLite compares
        // two strings. The rule never depended on settling that: use `whereDate()`, never a bare
        // comparison against a cast date column. It is correct on both engines, which is why
        // nothing here is broken.
        //
        // The rule is not free, and that is also measured now. `whereDate()` compiles to
        // `date(col) = ?` on MySQL, which cannot use an index on the wrapped column. On
        // handovers(unit_id, handover_date) the plan falls back to the index's LEADING column
        // only — 8,108 rows examined in 3.22 ms to return 4, against 4 rows in 0.043 ms for the
        // bare form. Do not "fix" it by removing whereDate(); the sargable rewrite is a half-open
        // range (>= $from AND < $toPlusOne), correct on both engines, and it is P2 work.
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

        // Ruling 5 — WARD has ONE consultant field ("Consultant Oncall"), stored in
        // consultant_by_*. A submitted receiving consultant is dropped, not persisted.
        if (! $u->profile()->consultantPair) {
            unset($data['consultant_to_person_id']);
        }

        // Needed inside the picker loop below: whose handwriting may be applied depends on
        // whether this request actually attests the day, not merely edits it.
        $signing = (bool) ($data['sign_off'] ?? false);
        $provenance = [];

        foreach (['endorsed_by', 'endorsed_to', 'consultant_by', 'consultant_to'] as $field) {
            if (! array_key_exists($field.'_person_id', $data)) {
                continue;
            }

            $personId = $data[$field.'_person_id'];
            $signoff->{$field.'_person_id'} = $personId;
            // Freeze the name at write time; a later rename must not rewrite a signed sheet.
            $chosen = $personId === null ? null : Person::find($personId);
            $signoff->{$field.'_name'} = $chosen?->full_name;

            // The endorsers' SIGNATURES are frozen the same way and for the same reason:
            // signature files are content-addressed and immutable, so storing the path
            // pins the exact image this sheet was signed with (the consultant fields
            // carry no signature — legacy printed them as a typed name).
            if (in_array($field, ['endorsed_by', 'endorsed_to'], true)) {
                [$path, $why] = $this->resolveSignature($request->user(), $chosen, $signing);

                $signoff->{$field.'_signature_path'} = $path;
                $provenance[$field === 'endorsed_by' ? 'sig_by' : 'sig_to'] = $why;
            }
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

        if ($signing && $signoff->endorsed_by_person_id === null) {
            throw ValidationException::withMessages([
                'endorsed_by_person_id' => 'Select the endorsing clinician before signing off.',
            ]);
        }

        if ($signing) {
            $signoff->signed_off_at = now();
            $signoff->signed_off_by_user_id = $request->user()?->getKey();
            // Snapshotted for the same reason as the endorser names: accounts are
            // deactivated rather than deleted, and this line is resolved through a plain
            // belongsTo, so a soft delete used to make "Signed off … by …" vanish from every
            // historical print. That line is now the whole attestation wherever a signature
            // was withheld, so it cannot be allowed to move.
            $signoff->signed_off_by_name = $request->user()?->full_name;
            // A fresh signature supersedes any earlier reopen; the reason stays for the trail.
            $signoff->reopened_at = null;
        }

        $signoff->save();

        // PHI-free, and name-free: the printed sheet cannot distinguish "signature withheld
        // because a colleague named them" from "this clinician has no signature on file", so
        // the trail records which it was — as field names and a token, never a person.
        $detail = 'unit='.$u->id.' date='.$date.' signoff='.$signoff->id;

        if ($signing && $provenance !== []) {
            foreach ($provenance as $key => $why) {
                $detail .= ' '.$key.'='.$why;
            }
        }

        AuditLog::record(
            $signing ? 'endorsement_signoff' : 'endorsement_signoff_draft',
            $detail,
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

        // date_format, not a bare string: this went through the old strtotime-based parser
        // (Task 5 absorbed it into Calendar::parse()), so "+5 years" or "last monday" created
        // real handover rows at arbitrary dates — and a backdated day makes the missed-days
        // page report a handover that never happened, corrupting the one metric this system
        // exists to improve. Every other date route is regex-pinned in routes/web.php; this
        // one was not.
        $data = $request->validate([
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'carry_choice' => ['sometimes', 'nullable', 'in:carry,blank'],
        ]);

        $target = $this->parseDateOrToday($data['date'] ?? null);
        $choice = $data['carry_choice'] ?? null;

        // The only clinical write that did not check the lock. Its $alreadyExists guard
        // below looks for handover ROWS, not for a sign-off — so signing an empty day and
        // then starting it dropped rows straight into locked evidence.
        $this->assertDayUnlocked($u->id, $target);

        $alreadyExists = Handover::where('unit_id', $u->id)
            ->whereDate('handover_date', $target)
            ->exists();

        if ($alreadyExists) {
            return redirect()->route('endorsement.show', ['unit' => $u->code, 'date' => $target]);
        }

        // The most recent PRIOR day for this unit is the census source. A real model, not
        // ->max('handover_date') — an aggregate query's scalar result skips the 'date' cast
        // entirely and returns the raw column value (e.g. "2026-07-10 00:00:00" on this
        // schema), which Calendar::ymd() then rejects: it is deliberately Y-m-d-only, the same
        // strictness that makes it reject "+5 years". Fetching the model keeps the cast in the
        // loop and matches every other handover_date read in this file.
        $source = Handover::where('unit_id', $u->id)
            ->whereDate('handover_date', '<', $target)
            ->orderByDesc('handover_date')
            ->first();
        $sourceDate = $source !== null ? Calendar::ymd($source->handover_date) : null;

        $isConsecutive = $sourceDate !== null
            && $sourceDate === Calendar::addDays($target, -1)->format(Calendar::YMD);

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
                        // $row->dob is Carbon|string|null (EncryptedDateTime's wrong-key marker
                        // is a string) — create() below is a NEW row, so a foreign-ciphertext
                        // marker string must not be handed to it as if it were a date. Dropping
                        // it to null costs nothing here: the source row's own ciphertext is
                        // untouched by this create() either way.
                        'dob' => $row->dob instanceof \DateTimeInterface ? $row->dob : null,
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
        $this->assertDayUnlocked($u->id, $date);

        $data = $this->validateRow($request, $u);

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
        $this->assertDayUnlocked($handover->unit_id, $handover->handover_date?->format('Y-m-d'));

        $data = $this->validateRow($request, $handover->unit);

        // P0b finding 1 (the highest-risk bug in the plan): autosave PATCHes ONE custom
        // field at a time, but the whole map lives in a SINGLE encrypted column
        // (`extra_fields`). Assigning the submitted subset directly would replace the map
        // and silently wipe every sibling key. Read the currently-stored map ONCE —
        // EncryptedJson::get() is not cast-cached (see its docblock), so re-reading it in a
        // loop would re-decrypt every time — and array_replace() the submitted keys into it.
        // array_replace(), not array_merge(): array_merge() renumbers integer-looking keys,
        // so a numeric-looking definition key (e.g. "2024") would be silently renamed
        // instead of having its value overwritten.
        if (array_key_exists('extra_fields', $data)) {
            $existingExtraFields = $handover->extra_fields;
            $submittedExtraFields = $data['extra_fields'];

            try {
                // Direct property assignment — not left in $data for update() below — is
                // what actually invokes EncryptedJson::set(), whose FIRST statement is the
                // wrong-key guard (assertNotOverwritingForeignCiphertext). Assigning here
                // lets the guard's RuntimeException be caught below instead of propagating
                // out of update()/save() as an uncaught 500.
                $handover->extra_fields = array_replace($existingExtraFields, $submittedExtraFields);
            } catch (\RuntimeException $e) {
                // Step 7 — the project rule is that autosave reflects the server response.
                // A bare 500 with the reason only in the log is not that; surface the same
                // message as a normal validation failure instead.
                throw ValidationException::withMessages(['extra_fields' => $e->getMessage()]);
            }

            $this->recordExtraFieldRevisions(
                $handover,
                $existingExtraFields,
                $submittedExtraFields,
                $request->user()?->getKey(),
            );

            unset($data['extra_fields']);
        }

        // Keep what the record SAID before this edit. The audit row proves that a change
        // happened; without this, nothing anywhere retains what was originally attested,
        // so a reopen-rewrite-resign after an adverse event is unreconstructable.
        $this->recordRevisions($handover, $data, $request->user()?->getKey());

        $handover->update($data);

        AuditLog::record('endorsement_row_update', 'row='.$handover->id, $request->user()?->getKey(), $request->ip());

        return back()->with('status', 'Saved.');
    }

    /** Remove a handover row (soft delete). */
    public function deleteRow(Request $request, Handover $handover): RedirectResponse
    {
        $this->assertEnabledUnitRow($handover);
        $this->assertDayUnlocked($handover->unit_id, $handover->handover_date?->format('Y-m-d'));

        $id = $handover->id;
        $handover->delete();

        AuditLog::record('endorsement_row_delete', 'row='.$id, $request->user()?->getKey(), $request->ip());

        return back()->with('status', 'Row removed.');
    }

    /**
     * Spec §6 — a SIGNED day is locked: every row write on it is refused until the day is
     * reopened through the audited, reason-bearing path. Without this, the sign-off would
     * attest to a sheet that can silently change afterwards.
     */
    private function assertDayUnlocked(?int $unitId, ?string $date): void
    {
        if ($unitId === null || $date === null) {
            return;
        }

        $signed = HandoverSignoff::where('unit_id', $unitId)
            ->whereDate('handover_date', $date)
            ->whereNotNull('signed_off_at')
            ->exists();

        if ($signed) {
            throw ValidationException::withMessages([
                'sheet' => 'This handover day is signed off and locked. Reopen it (with a reason) before editing.',
            ]);
        }
    }

    /**
     * The row-write verbs bind `{handover}` by BARE ID, so they need the same unit scoping
     * every read path gets from resolveUnit(). A row belonging to a unit that is not active
     * (unknown, or deactivated) 404s, matching what a read of the same row would do.
     */
    private function assertEnabledUnitRow(Handover $handover): void
    {
        if ($handover->unit === null || ! $handover->unit->active) {
            abort(404);
        }
    }

    /**
     * Resolve + validate the `{unit}` route param against the active units on the `units`
     * table — an unknown code and a deactivated one both 404, exactly like `firstOrFail()`
     * did. Lowercase URLs keep resolving (legacy links and the nav both use them):
     * `Unit::findByCode()` normalizes the lookup the same way the `code` mutator normalizes
     * storage.
     */
    private function resolveUnit(string $unit): Unit
    {
        $u = Unit::findByCode($unit);

        if ($u === null || ! $u->active) {
            abort(404);
        }

        return $u;
    }

    /**
     * Validate an incoming row against ITS UNIT's profile. Rich-text fields are length-bounded
     * here and sanitized on write by the model cast. Identity columns another unit owns (dob is
     * NICU/SCBU; age + ward_unit are WARD) are simply not validated for this unit, so a client
     * that submits them has them dropped rather than persisted.
     *
     * The four rich-text fields are bounded by `MaxSanitizedBytes` rather than a plain
     * `max:N`, which counts CHARACTERS of the raw value — the database (and the model's
     * `SanitizedHtml` cast) cares about BYTES of the SANITIZED value, and those two disagree
     * for anything wider than ASCII. See `SanitizedHtml::MAX_PLAINTEXT_BYTES`'s docblock
     * (SPC-RPT-058) for the full derivation.
     *
     * @return array<string, mixed>
     */
    private function validateRow(Request $request, Unit $unit): array
    {
        $profile = $unit->profile();

        $rules = [
            'bed' => ['sometimes', 'nullable', 'string', 'max:100'],
            'mrn' => ['sometimes', 'nullable', 'string', 'max:100'],
            'patient_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'disease' => ['sometimes', 'nullable', 'string', new MaxSanitizedBytes()],
            'details' => ['sometimes', 'nullable', 'string', new MaxSanitizedBytes()],
            'plan' => ['sometimes', 'nullable', 'string', new MaxSanitizedBytes()],
            'nevent' => ['sometimes', 'nullable', 'string', new MaxSanitizedBytes()],
        ];

        foreach ($profile->extraRowFields as $field) {
            $rules[$field] = $field === 'dob'
                ? ['sometimes', 'nullable', 'date']
                : ['sometimes', 'nullable', 'string', 'max:100'];
        }

        // P0b (design §6.2) — the unit's OWN custom fields, namespaced under extra_fields.*
        // so they can never collide with a real column name. That namespacing is what keeps
        // EncryptedJson's mass-assignment note inapplicable: these rule keys never become
        // model attribute names. It also means a key with no ACTIVE definition (unknown, or
        // retired) gets no rule at all, so Laravel's excludeUnvalidatedArrayKeys (on by
        // default) drops it from the validated data rather than letting it through to be
        // stored or overwrite an existing value.
        foreach ($unit->fieldDefinitions as $definition) {
            $rules['extra_fields.'.$definition->key] = $this->extraFieldRules($definition);
        }

        return $request->validate($rules);
    }

    /**
     * The validation rule for one custom field, driven entirely by its definition row.
     *
     * @return list<mixed>
     */
    private function extraFieldRules(UnitFieldDefinition $definition): array
    {
        // A `type` outside text|date|select can only reach here via a write that bypassed
        // UnitFieldDefinition's model-level `saving` guard (e.g. raw SQL against
        // production). It falls into the `text` branch below rather than silently getting
        // no type rule at all — an unbounded, unvalidated string is worse than treating an
        // unrecognized type as text.
        //
        // Each element of this array must be ONE rule, never a pipe-joined string: mixing
        // "string|max:500" into an array alongside plain rule names is not exploded by
        // Laravel's parser the way a top-level string rule would be, and fails with
        // "Method ...::validateString|max does not exist."
        $typeRules = match ($definition->type) {
            'date' => ['date'],
            'select' => [Rule::in($definition->options ?? [])],
            default => ['string', 'max:500'],
        };

        return array_merge(
            ['sometimes', $definition->required ? 'required' : 'nullable'],
            $typeRules,
        );
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
                // $h->dob is Carbon|string|null: the nullsafe operator only guards null, not
                // "not an object" — a foreign-ciphertext marker string reaching ->format()
                // fatals. Passing the marker through as-is lets it reach the clinician as
                // visible text instead of a 500.
                //
                // Deliberately NOT routed through Calendar (Task 5): dob is PHI held by
                // App\Casts\EncryptedDateTime, whose getter can return that string marker —
                // Calendar::parse()/ymd() are not built for a value that might not be a real
                // date at all, and Calendar's own docblock names dob as out of scope.
                'dob' => is_string($h->dob) ? $h->dob : $h->dob?->format('Y-m-d H:i'),
                'age' => $h->age,
                'ward_unit' => $h->ward_unit,
                'disease' => $h->disease,
                'details' => $h->details,
                'plan' => $h->plan,
                'nevent' => $h->nevent,
                // P0b (design §6.2) — cast to object so the JSON shape is a stable `{}`
                // rather than flipping between `{}` and `[]` depending on whether the map
                // is empty, which would break client code that assumes an object.
                'extra_fields' => (object) $h->extra_fields,
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
    private function signoffPayload(Unit $unit, string $date, ?User $viewer = null, ?HandoverSignoff $signoff = null): array
    {
        $s = $signoff ?? HandoverSignoff::where('unit_id', $unit->id)
            ->whereDate('handover_date', $date)
            ->first();

        $canReopen = $this->canReopen($viewer);

        // A signature is evidence of an attestation, so it is served only where there IS
        // one. Withholding it in the template would not be enough — the URL must not leave
        // the server for a day nobody has signed.
        $isSignedOff = $s?->isSignedOff() ?? false;

        return [
            // Reopening needs `endorsement.reopen`. The sheet says up front whether THIS viewer has
            // it, and if not, names the people who do — so a ward is never left guessing at the
            // point it discovers it cannot self-correct.
            'can_reopen' => $canReopen,
            'reopen_contacts' => $canReopen ? [] : $this->reopenContacts(),
            'signed_off' => $s?->isSignedOff() ?? false,
            'signed_off_at' => $s?->signed_off_at?->format('Y-m-d H:i'),
            // Snapshot first; the relation only as a fallback for rows written before the
            // column existed. Resolving it live let a rename rewrite every historical sheet
            // and a deactivated account erase the attribution altogether.
            'signed_off_by_name' => $s?->signed_off_by_name ?? $s?->signedOffBy?->full_name,
            // The four named roles are PEOPLE since P0c/D9 (finding 3: `people.id` and `users.id`
            // are independent sequences, so a field still named `*_user_id` here would silently
            // carry a person id under a user-shaped name — exactly how the id-space confusion
            // ships).
            'endorsed_by_person_id' => $s?->endorsed_by_person_id,
            'endorsed_by_name' => $s?->endorsed_by_name,
            'endorsed_by_signature' => $isSignedOff ? self::signatureUrl($s?->endorsed_by_signature_path) : null,
            'endorsed_to_person_id' => $s?->endorsed_to_person_id,
            'endorsed_to_name' => $s?->endorsed_to_name,
            'endorsed_to_signature' => $isSignedOff ? self::signatureUrl($s?->endorsed_to_signature_path) : null,
            'consultant_by_person_id' => $s?->consultant_by_person_id,
            'consultant_by_name' => $s?->consultant_by_name,
            'consultant_to_person_id' => $s?->consultant_to_person_id,
            'consultant_to_name' => $s?->consultant_to_name,
            'endorsement_time' => $s?->endorsement_time,
            'reopened_at' => $s?->reopened_at?->format('Y-m-d H:i'),
            'reopen_reason' => $s?->reopen_reason,
        ];
    }

    /**
     * The roles that may apply ANOTHER clinician's handwritten signature — owner ruling,
     * 2026-07-27: Administrator (0) and Chief Resident (5).
     *
     * These are the roles that complete records on others' behalf, and the ward needs some
     * route to a fully-signed sheet. Everyone else may still NAME a colleague — a handover
     * has two people and the sheet must say so — but only their own handwriting is applied.
     *
     * @var list<int>
     */
    private const SIGNATURE_PROXY_POSITIONS = [0, 5];

    /**
     * Whose signature image this write may freeze, and why — the medico-legal heart of the sheet,
     * so it answers in one place and records its reasoning.
     *
     * The named party is a PERSON; the signature is on their ACCOUNT (`SignatureStore` is keyed
     * on `users`, and `ProfileController::updateSignature()` binds to the session identity). That
     * is precisely what keeps NAMING separate from SIGNING (P0c/D9): a consultant can be named
     * without an account, but signing requires one.
     *
     * Returns [path|null, reason], where reason is one of:
     *   self      — applied; the actor is the person named
     *   proxy     — applied; the actor holds SIGNATURE_PROXY_POSITIONS
     *   withheld  — the named clinician HAS a signature, but this actor may not apply it
     *   unclaimed — the named person has no account, so there is no signature to apply
     *   none      — nobody named, or the named clinician has no signature on file
     *   draft     — this request does not attest the day, so nothing is frozen at all
     *
     * @return array{0: ?string, 1: string}
     */
    private function resolveSignature(?User $actor, ?Person $named, bool $signing): array
    {
        // An unsigned day carries no attestation, so it may carry no handwriting — not even
        // the drafter's own. Freezing on every save meant a draft could be printed with a
        // signature on it and no "signed off" line anywhere to qualify it.
        if (! $signing) {
            return [null, 'draft'];
        }

        if ($named === null) {
            return [null, 'none'];
        }

        // Defence in depth: D9's rule already refuses an unclaimed endorser at validation, and
        // consultants never reach this branch (no consultant signature columns exist). A distinct
        // token means the audit trail can still say WHY, rather than reporting it as "no
        // signature on file".
        $account = $named->user;

        if ($account === null) {
            return [null, 'unclaimed'];
        }

        if ($account->signature_path === null) {
            return [null, 'none'];
        }

        if ($actor !== null && $account->getKey() === $actor->getKey()) {
            return [$account->signature_path, 'self'];
        }

        if ($actor !== null && in_array((int) $actor->position, self::SIGNATURE_PROXY_POSITIONS, true)) {
            return [$account->signature_path, 'proxy'];
        }

        return [null, 'withheld'];
    }

    /**
     * Append the previous value of every tracked field that is actually changing.
     *
     * Only real changes are stored — re-saving an unchanged field on blur must not fill the
     * table with identical rows. `day_was_signed` is captured because an edit to a signed
     * day is the case that matters: it means the attestation no longer describes what was
     * attested, and that is the question an incident review asks.
     *
     * @param  array<string, mixed>  $incoming
     */
    private function recordRevisions(Handover $handover, array $incoming, ?int $userId): void
    {
        $signed = $this->dayIsSigned($handover);

        foreach (\App\Models\HandoverRevision::TRACKED as $field) {
            if (! array_key_exists($field, $incoming)) {
                continue;
            }

            $before = $handover->getAttribute($field);
            $after = $incoming[$field];

            // Compare the DECRYPTED values: comparing ciphertext would report a change on
            // every save, because each encryption uses a fresh IV.
            $beforeString = $before instanceof \DateTimeInterface ? $before->format('Y-m-d') : (string) $before;
            $afterString = $after instanceof \DateTimeInterface ? $after->format('Y-m-d') : (string) $after;

            if ($beforeString === $afterString) {
                continue;
            }

            \App\Models\HandoverRevision::create([
                'handover_id' => $handover->getKey(),
                'field' => $field,
                'old_value' => $beforeString === '' ? null : $beforeString,
                'changed_by_user_id' => $userId,
                'day_was_signed' => $signed,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * The extra_fields.* twin of recordRevisions(). `extra_fields` cannot be added to
     * HandoverRevision::TRACKED as-is (P0b finding 3): TRACKED's loop casts the before-image
     * to `(string)`, and casting an array raises "Array to string conversion" — promoted to a
     * thrown ErrorException by this app's HandleExceptions. Custom fields get their own
     * before-image path instead: one row per submitted SUBKEY that actually changed, named
     * `extra_fields.{key}` so a reopen-rewrite-resign case still has something to reconstruct
     * from, and so history stays queryable per field rather than as one opaque blob.
     *
     * @param  array<string, string|null>  $before  the map as stored BEFORE this write
     * @param  array<string, string|null>  $submitted  only the subkeys THIS request carried
     */
    private function recordExtraFieldRevisions(Handover $handover, array $before, array $submitted, ?int $userId): void
    {
        if ($submitted === []) {
            return;
        }

        $signed = $this->dayIsSigned($handover);

        foreach ($submitted as $subKey => $after) {
            $beforeValue = $before[$subKey] ?? null;
            $beforeString = $beforeValue === null ? '' : (string) $beforeValue;
            $afterString = $after === null ? '' : (string) $after;

            if ($beforeString === $afterString) {
                continue;
            }

            \App\Models\HandoverRevision::create([
                'handover_id' => $handover->getKey(),
                'field' => 'extra_fields.'.$subKey,
                'old_value' => $beforeString === '' ? null : $beforeString,
                'changed_by_user_id' => $userId,
                'day_was_signed' => $signed,
                'created_at' => now(),
            ]);
        }
    }

    /** Whether the day this row belongs to is currently signed off — shared by both revision paths. */
    private function dayIsSigned(Handover $handover): bool
    {
        return HandoverSignoff::where('unit_id', $handover->unit_id)
            ->whereDate('handover_date', $handover->handover_date?->format('Y-m-d'))
            ->whereNotNull('signed_off_at')
            ->exists();
    }

    /**
     * The staff pickers behind the four sign-off selects.
     *
     * Legacy left the two consultant fields as FREE TEXT — which is how a handover sheet ends up
     * attesting to a misspelled name. Both lists are real rostered PEOPLE, and since D9 they are
     * scoped differently: the endorser lists hold only people with a live account, because their
     * signature is the evidence; the consultant list holds any active person, because the
     * covering consultant is a name of record and frequently never logs in. The chosen NAME is
     * frozen into a `*_name` snapshot at write time (updateSignoff), so a later rename cannot
     * rewrite a signed sheet.
     *
     * @return array{endorsers: list<array{id: int, name: string, retired?: bool}>, consultants: list<array{id: int, name: string, retired?: bool}>}
     */
    private function staffPickers(?HandoverSignoff $signoff): array
    {
        return [
            // Both stored ids per field are passed, not just one (`??` used to drop whichever
            // was null-coalesced away) — two different retired people must both reappear.
            'endorsers' => SignoffPickers::offer(
                SignoffPickers::endorserPredicate(),
                [$signoff?->endorsed_by_person_id, $signoff?->endorsed_to_person_id],
            ),
            'consultants' => SignoffPickers::offer(
                SignoffPickers::consultantPredicate(),
                [$signoff?->consultant_by_person_id, $signoff?->consultant_to_person_id],
            ),
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
     * A frozen signature path -> the authenticated URL that serves that exact image.
     * Content-addressed, so the URL pins the version signed with (never "current").
     */
    private static function signatureUrl(?string $path): ?string
    {
        if ($path === null || ! preg_match('#^signatures/([a-f0-9]{64})\.png$#', $path, $m)) {
            return null;
        }

        return '/signatures/file/'.$m[1];
    }

    /**
     * @return array{code: string, name: string, profile: array<string, mixed>}
     */
    private function unitPayload(Unit $unit): array
    {
        $profile = $unit->profile()->toArray();

        // P0b (design §6.2) — the unit's ACTIVE custom field definitions, so the client can
        // render them generically instead of a per-unit code path. A retired definition is
        // simply absent here; its stored values are untouched (UnitFieldDefinition's own
        // docblock — retiring hides a definition, never deletes its data).
        $profile['field_definitions'] = $unit->fieldDefinitions
            ->map(fn (UnitFieldDefinition $d): array => [
                'key' => $d->key,
                'label' => $d->label,
                'type' => $d->type,
                'options' => $d->options,
                'required' => $d->required,
            ])
            ->all();

        return [
            'code' => $unit->code,
            'name' => $unit->name,
            'profile' => $profile,
        ];
    }

    /** Normalize a `{date}` route param to `Y-m-d`, rejecting anything unparseable with a 404. */
    private function normalizeDate(string $date): string
    {
        $parsed = Calendar::tryParse($date);

        if ($parsed === null) {
            abort(404);
        }

        return $parsed->format(Calendar::YMD);
    }

    /** Parse a submitted date to `Y-m-d`, defaulting to today's date when absent/empty. */
    private function parseDateOrToday(mixed $value): string
    {
        return (is_string($value) ? Calendar::tryParse($value)?->format(Calendar::YMD) : null)
            ?? Calendar::todayYmd();
    }
}
