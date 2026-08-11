<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useCan } from '../../Composables/useCan.js';

/**
 * Admin → People (Munawib PE-01…03, LV-02…04, ST-04).
 *
 * PERSON-scoped, where Admin → Users is ACCOUNT-scoped: a roster-only person (never logged in)
 * appears here and nowhere on Admin → Users, because that screen's list is
 * `User::query()->join('people', ...)`.
 *
 * Contact fields (Task 2, PE-02): `phone` renders only when the key is PRESENT in a person's
 * props — `'phone' in p`, never `p.phone` — because absent and null are different facts (a
 * withheld number vs one nobody recorded) and the header must not appear for a viewer the
 * policy has refused. Every row on this page was projected by `App\Support\PersonPresenter`,
 * so the key is either present for everyone in the list or absent for everyone in it. (On THIS
 * screen it is always present: the route itself requires `people.manage`, and a holder of that
 * capability always passes `PersonPolicy::viewContact()`/`viewNotes()`'s first branch.)
 *
 * "Status" is always DERIVED (active/retired × account/roster-only × claim state) — there is no
 * stored `person_status` column and no stored invitation status, on purpose (design §5.1
 * deviation 3; P1c-2 Decision B).
 *
 * CLAIM STATE (AC-02) arrives as `person.invitation`, built by `App\Support\Invitations\
 * InvitationStatus` and passed as `$extra` — never a `PersonPresenter` base key, because the base
 * map reaches every `rota.view` holder and "who has not claimed their account yet" is not a fact
 * the whole department gets. Every string and every date in it is composed SERVER-SIDE
 * (`invitation.label`, `invitation.at.date`, `invitation.at.hijri`): this component interpolates
 * and formats nothing, which is what `CalendarIsTheOnlyConverterTest` requires.
 *
 * It is rendered BESIDE the account tag rather than replacing it, because the two answer different
 * questions and a person can hold an account with no invitation row at all (the bootstrap
 * administrator, a legacy-imported member, an approved pending registration). Folding them into
 * one tag would label those people "No invitation", which reads as "unclaimed" and is the opposite
 * of true.
 *
 * NOTHING HERE CREATES AN ACCOUNT — position is a JOB ROLE, not a login, and the Invite and Resend
 * buttons below POST to `admin.invitations.store` / `admin.invitations.resend` (gated
 * in-controller by `App\Support\ManagerScope`), never to any `/admin/people/*` endpoint. The
 * screen is shared; the authorization is not — this route needs `people.manage`, those endpoints
 * apply their own two-tier rule. `RosterNeverMintsCredentialsTest` fails the build if that ever
 * changes. There is no delete control: people are deactivated (the "Retire" toggle
 * below), never deleted (owner ruling).
 *
 * Mobile cards + desktop table, matching Levels.vue and Units.vue. Create/edit mirror
 * `Levels.vue`'s `createOpen` / `editingId` structure verbatim.
 */
const props = defineProps({
    people: { type: Array, default: () => [] },
    positions: { type: Array, default: () => [] },
    // WHICH of `positions` this viewer may PLACE somebody at (review finding F2). The full
    // catalog above is still what renders a role NAME — an existing Administrator's row would
    // read "Role 0" if position 0 were simply dropped from it. This narrower list is what the two
    // role <select>s offer, and it comes from `PositionChange::grantableBy()`, the same
    // definition the server refuses with (D9: offer and write, one predicate).
    grantable_positions: { type: Array, default: () => [] },
    // LV-02's bulk "set level" picker (Task 9). Every active level, EXT included.
    levels: { type: Array, default: () => [] },
    contact_visibility: { type: String, default: 'admins' },
    contact_visibilities: { type: Object, default: () => ({}) },
    // LV-04. Only the currently-open row's spans — `PersonController::history()` is a dedicated,
    // fetched-on-open endpoint, not part of every roster load (Task 3's levelsAt() already
    // answers "current level" for all 60 rows in one query; a full span LIST per row is a much
    // larger shape only the expanded row needs).
    history: { type: Object, default: null },
    // LV-02's bulk resend cap (P1c-2 Task 4). `App\Support\Invitations\BulkResend::CAP` is the ONE
    // definition — it is stated beside the button, enforced by `InvitationBulkResendRequest`, and
    // never written as a literal here, because a screen that promised fifty while the endpoint
    // accepted forty would refuse exactly the selection it invited.
    invitation_resend_cap: { type: Number, default: 50 },
    // AC-04's roles panel (P1c-2 Task 6). ABSENT — not empty — for a viewer who does not hold
    // `access.manage`, which is a different capability from the `people.manage` that opens this
    // screen at all. An empty override map and a withheld one look identical on screen, and this
    // is the same discipline `PersonPresenter` applies to a withheld contact field.
    //   { capabilities: [{ id, key, label, description }], overrides: { personId: { capId: effect } } }
    capability_grants: { type: Object, default: null },
    // Inertia's shared error bag. The only key this screen reads from it is `invitation`: the
    // Invite/Resend controls post to `InvitationController`, which is not a `useForm()` here, so
    // its refusals have no form object to land on. Without this the endpoint's "that invitation
    // was revoked / has already been claimed" would be silent on a stale page.
    errors: { type: Object, default: () => ({}) },
});

const inputClass = 'w-full rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none';

const search = ref('');

const positionName = (id) => props.positions.find((p) => p.id === id)?.name || `Role ${id}`;

// The options a role <select> offers: every position this viewer may assign, PLUS whatever the
// row already holds. The second half matters — the edit form submits the position it was
// rendered with, so an Administrator's row opened by a `people.manage` holder must still be able
// to save itself unchanged. The server gates the TRANSITION for the same reason.
const positionOptions = (current = null) => props.positions.filter(
    (p) => props.grantable_positions.includes(p.id) || p.id === current,
);

// Every row was projected by the same presenter for the same viewer, so checking the first
// row's shape is enough to know whether the `phone` column applies to the whole list.
const showsPhone = computed(() => props.people.length > 0 && 'phone' in props.people[0]);

const filteredPeople = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (q === '') return props.people;

    return props.people.filter((p) =>
        [p.full_name, p.short_name].some((v) => (v || '').toLowerCase().includes(q)));
});

// --- Level history (LV-04): one row's panel open at a time, fetched on open -------------------
//
// `only: ['history']` trims the partial-reload RESPONSE to just that key (the server still
// builds the full roster props, matching PersonController::rosterProps() — Inertia's `only`
// controls what comes BACK over the wire, not what the controller computes). `preserveState` is
// what keeps every other row's in-progress edit intact rather than remounting the whole page
// component — Sheet.vue's own G3 finding, the same fix applied here.
//
// Every date below is a `Calendar::label()` shape the server already formatted — `h.from.date`,
// `h.from.hijri` — never a client-side date computation.
const openHistoryId = ref(null);
const historyLoading = ref(false);

const toggleHistory = (person) => {
    if (openHistoryId.value === person.id) {
        openHistoryId.value = null;

        return;
    }

    openHistoryId.value = person.id;
    historyLoading.value = true;

    router.get(`/admin/people/${person.id}/history`, {}, {
        only: ['history'],
        preserveState: true,
        preserveScroll: true,
        onFinish: () => { historyLoading.value = false; },
    });
};

const historySpans = computed(() => (
    props.history && props.history.person_id === openHistoryId.value ? props.history.spans : null
));

// --- Invite (AC-02's convenience on top of an already-satisfied AC-01) ----------------------
//
// A roster-only person with an address, whose invitation has never been issued / has expired /
// was revoked, and whom this viewer may target. Every one of those conditions is decided by the
// SERVER: `invitation.state` and `invitation.may_invite` come from
// `App\Support\Invitations\InvitationStatus`, which reads `App\Support\ManagerScope` and
// `InvitationController::OFFERABLE` — so the button is offered exactly where the endpoint would
// accept it, rather than where the client guesses it would (D9's rule, applied to an affordance).
//
// The address is checked with `'email' in person`, not `person.email`: a withheld contact field is
// ABSENT from the props, never null, and the two must not be conflated. (On this screen the route
// itself requires `people.manage`, so it is always present — the check is the shape, not a
// prediction about this page.)
//
// INVITE AND RESEND PARTITION THE STATES; neither person ever sees both buttons. `none` and
// `revoked` are an INVITE, because there is nothing live to replace — and a revoked link was
// deliberately killed by somebody, so reviving it from that row would undo their act through a
// shorter path. `open` and `expired` are a RESEND: a row exists, and the endpoint that owns it
// rotates its token rather than minting beside it. `expired` moved from the first list to the
// second in P1c-2 Task 3 — the outcome is identical either way, and one affordance per person is
// the point.
const invitableStates = ['none', 'revoked'];

const canInvite = (person) => !!person.invitation
    && person.invitation.may_invite
    && invitableStates.includes(person.invitation.state)
    && !person.has_account
    && 'email' in person
    && !!person.email;

const resendableStates = ['open', 'expired'];

// No `email` check: a resend is addressed from the ROSTER row the server resolves for itself, and
// this screen may legitimately be withholding the address (a withheld contact field is ABSENT from
// the props, never null). `invitation.id` is what the endpoint is bound to.
const canResend = (person) => !!person.invitation
    && person.invitation.may_invite
    && resendableStates.includes(person.invitation.state)
    && !person.has_account;

const invitingId = ref(null);
const invitationLink = ref(null);

// Straight to the invitation endpoint, which carries ManagerScope's two-tier gate. The link comes
// back in a one-shot flash and is shown once: it is a bearer credential, stored hashed, and it
// cannot be re-displayed. If mail is configured it has also been sent; if it is not, this panel is
// the delivery.
const invite = (person) => {
    invitingId.value = person.id;
    invitationLink.value = null;

    router.post('/admin/invitations', {
        member_email: person.email,
        position: person.position,
    }, {
        preserveScroll: true,
        onSuccess: (page) => { invitationLink.value = page.props.flash?.invitation_link ?? null; },
        onFinish: () => { invitingId.value = null; },
    });
};

// AC-02's "resendable singly". The confirmation says the old link dies, because it does: a resend
// ROTATES the token, so anyone still holding the previous link — a forwarded email, a shared
// mailbox — cannot claim the account with it.
const resend = (person) => {
    if (!confirm(`Send ${person.full_name} a new invitation link? The previous link stops working immediately.`)) return;

    invitingId.value = person.id;
    invitationLink.value = null;

    router.post(`/admin/invitations/${person.invitation.id}/resend`, {}, {
        preserveScroll: true,
        onSuccess: (page) => { invitationLink.value = page.props.flash?.invitation_link ?? null; },
        onFinish: () => { invitingId.value = null; },
    });
};

// --- Roles (AC-04, P1c-2 Task 6) ----------------------------------------------------------
//
// GRANTED HERE, HELD ON THE ACCOUNT. Owner decision 2 keeps `user_capabilities` keyed to the
// account; what AC-04 asks for is that an administrator can grant a role WHERE THE PERSON IS
// rather than hunting the same colleague down on a second console. So this panel posts to
// `/admin/access-control/person`, in the `cap:access.manage` route group, and the server resolves
// the person's linked account and writes through to it — one writer, two surfaces.
//
// THE GATE IS `access.manage`, NOT THIS SCREEN'S `people.manage`. `people.manage` is "who exists
// and what level they hold": its holder may rename a ward, and if the roster gate also decided
// who may grant capabilities it would be a path to `access.manage` — a privilege escalation
// created by a UI convenience. Two independent signals have to agree before anything renders:
// the server omitted the whole prop for a viewer without the capability, and `useCan` says the
// same from the shared `auth.can`. NEITHER IS THE GATE — the route group is — but a control that
// appears where the endpoint would refuse it is its own kind of defect.
const { can } = useCan();

const canGrantRoles = computed(() => can('access.manage') && !!props.capability_grants);

const capabilityCatalog = computed(() => props.capability_grants?.capabilities ?? []);

const overridesFor = (person) => props.capability_grants?.overrides?.[person.id] ?? {};

const openRolesId = ref(null);

// A map { capabilityId: 'grant' | 'deny' } — a capability ABSENT from it is inherited from the
// role default, which is a third state and not a missing one. That is why the control is a
// three-way select rather than a checkbox.
const rolesForm = useForm({ person_id: null, overrides: {} });

const toggleRoles = (person) => {
    if (openRolesId.value === person.id) {
        openRolesId.value = null;

        return;
    }

    openRolesId.value = person.id;
    rolesForm.clearErrors();
    rolesForm.person_id = person.id;
    rolesForm.overrides = { ...overridesFor(person) };
};

const roleOverrideFor = (capId) => rolesForm.overrides[capId] ?? 'inherit';

const setRoleOverride = (capId, value) => {
    const next = { ...rolesForm.overrides };
    if (value === 'inherit') {
        delete next[capId];
    } else {
        next[capId] = value;
    }
    rolesForm.overrides = next;
};

const saveRoles = () => rolesForm.put('/admin/access-control/person', { preserveScroll: true });

// --- Contact visibility (PE-02's department setting) ------------------------------------

const visibilityForm = useForm({ contact_visibility: props.contact_visibility });

const submitVisibility = () => {
    visibilityForm.patch('/admin/people/visibility', { preserveScroll: true });
};

// --- Constraints: a small JSON object edited as text ------------------------------------
//
// `constraints` (PE-01) is structured JSON the scheduling solver reads directly (P1d); there is
// no dedicated field-by-field editor yet, so it round-trips through a plain textarea. Blank
// clears it to null; invalid JSON is a client-side error that blocks submit rather than sending
// malformed data the server would 422 on anyway.
const parseConstraints = (text, formErrors) => {
    const trimmed = text.trim();

    if (trimmed === '') return null;

    try {
        const parsed = JSON.parse(trimmed);

        if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
            throw new Error('not an object');
        }

        return parsed;
    } catch {
        formErrors.constraints = 'Enter valid JSON (e.g. {"no_nights": true}), or leave blank.';

        return undefined;
    }
};

// --- Create -------------------------------------------------------------------------------

const createOpen = ref(false);

const blankCreateForm = () => ({
    full_name: '',
    short_name: '',
    // The first position this viewer may actually assign — never `positions[0]`, which is
    // Administrator and which a `people.manage` holder would then be refused for by default.
    position: props.grantable_positions[0] ?? props.positions[0]?.id ?? 4,
    email: '',
    phone: '',
    joined_at: '',
    notes: '',
    constraintsText: '',
    external: false,
    active: true,
});

const createForm = useForm(blankCreateForm());

const submitCreate = () => {
    createForm.clearErrors();
    const constraints = parseConstraints(createForm.constraintsText, createForm.errors);
    if (constraints === undefined) return;

    createForm
        .transform((data) => ({ ...data, constraints, joined_at: data.joined_at || null }))
        .post('/admin/people', {
            preserveScroll: true,
            onSuccess: () => {
                createForm.reset();
                createOpen.value = false;
            },
        });
};

// --- Edit (one person open at a time) ----------------------------------------------------

const editingId = ref(null);

const editForm = useForm({
    full_name: '',
    short_name: '',
    position: 4,
    email: '',
    phone: '',
    joined_at: '',
    notes: '',
    constraintsText: '',
    external: false,
    active: true,
});

const startEdit = (person) => {
    editingId.value = person.id;
    editForm.clearErrors();
    editForm.full_name = person.full_name;
    editForm.short_name = person.short_name || '';
    editForm.position = person.position;
    editForm.email = person.email || '';
    editForm.phone = 'phone' in person ? (person.phone || '') : '';
    editForm.joined_at = person.joined_at || '';
    editForm.notes = 'notes' in person ? (person.notes || '') : '';
    editForm.constraintsText = ('constraints' in person && person.constraints)
        ? JSON.stringify(person.constraints)
        : '';
    editForm.external = person.external;
    editForm.active = person.active;
};

const cancelEdit = () => {
    editingId.value = null;
    editForm.clearErrors();
};

const submitEdit = (person) => {
    editForm.clearErrors();
    const constraints = parseConstraints(editForm.constraintsText, editForm.errors);
    if (constraints === undefined) return;

    editForm
        .transform((data) => ({ ...data, constraints, joined_at: data.joined_at || null }))
        .patch(`/admin/people/${person.id}`, {
            preserveScroll: true,
            onSuccess: () => { editingId.value = null; },
        });
};

// --- Bulk operations (LV-02) ---------------------------------------------------------------
//
// Selection is a Set of person ids, independent of search/edit/history state. "Select all
// filtered" selects only what the search box currently shows — never every row ever LOADED —
// because selecting rows the search has hidden from view is exactly how a bulk deactivation
// surprises someone.
//
// "Resend invitations" is the one action on this bar that does NOT post to /admin/people/bulk:
// it is an ACCOUNT action and goes to InvitationController's own endpoints, which carry
// ManagerScope's two-tier gate rather than this route's `people.manage`.
const page = usePage();

const selected = ref(new Set());

const isSelected = (id) => selected.value.has(id);

const toggleSelected = (id) => {
    const next = new Set(selected.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    selected.value = next;
};

const allFilteredSelected = computed(() => filteredPeople.value.length > 0
    && filteredPeople.value.every((p) => selected.value.has(p.id)));

const toggleSelectAllFiltered = () => {
    selected.value = allFilteredSelected.value
        ? new Set()
        : new Set(filteredPeople.value.map((p) => p.id));
};

const selectionCount = computed(() => selected.value.size);

const bulkLevelId = ref(props.levels[0]?.id ?? null);
const bulkForm = useForm({ action: '', ids: [], level_id: null, active: true });

const runBulk = (overrides) => {
    bulkForm.clearErrors();
    bulkForm
        .transform((data) => ({ ...data, ...overrides, ids: Array.from(selected.value) }))
        .post('/admin/people/bulk', {
            preserveScroll: true,
            onSuccess: () => { selected.value = new Set(); },
        });
};

const bulkSetLevel = () => runBulk({ action: 'set_level', level_id: bulkLevelId.value });
const bulkActivate = () => runBulk({ action: 'set_active', active: true });
const bulkDeactivate = () => runBulk({ action: 'set_active', active: false });

// A plain (non-Inertia) form submission: the export response is a file stream carrying a
// Content-Disposition header, not an X-Inertia page object, so Inertia's own router cannot
// handle it — a native <form> POST lets the browser do what it already knows how to do with a
// file download. The first file download this application has ever shipped (finding 16).
const submitExport = () => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/admin/people/bulk';
    form.style.display = 'none';

    const field = (name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    };

    field('_token', token);
    field('action', 'export');
    Array.from(selected.value).forEach((id) => field('ids[]', id));

    document.body.appendChild(form);
    form.submit();
    form.remove();
};

// Per-person outcome, straight from the writer's own return values
// (LevelAssignment::ASSIGNED etc., or 'activated'/'deactivated') — never a client-side guess at
// what happened. Shared via the same session-flash channel as `flash.status`/`flash.error`
// (HandleInertiaRequests), because a bulk report is exactly as one-shot as those.
const outcomeLabels = {
    assigned: 'Level set',
    skipped_existing: 'Already had a change recorded on that date',
    skipped_same_level: 'Already at that level',
    refused_overlap: 'A later change already exists — not applied',
    activated: 'Activated',
    deactivated: 'Deactivated',
};

const bulkReport = computed(() => {
    const report = page.props.flash?.bulk_report;
    if (!report) return [];

    return Object.entries(report).map(([id, outcome]) => {
        const person = props.people.find((p) => p.id === Number(id));

        return {
            id: Number(id),
            name: person?.full_name ?? `Person #${id}`,
            label: outcomeLabels[outcome] ?? outcome,
        };
    });
});

// --- LV-02's bulk resend (AC-02, P1c-2 Task 4) --------------------------------------------
//
// PREVIEW, THEN CONFIRM, and the confirm carries the preview's own digest. The server pins that
// digest to the STATE the plan was computed against — each selected person's account and
// invitation facts — so a roster that moved between the two clicks is a refusal naming the fix,
// never a silent send against a plan somebody else invalidated. Re-deriving server-side is
// necessary and not sufficient; without the pin the operator would approve "47 emails" and send
// whatever the roster now says.
//
// EVERY OUTCOME AND EVERY REASON IS THE SERVER'S. This component decides nothing about who is
// resendable — it renders `row.outcome` and `row.reason` from `App\Support\Invitations\
// BulkResend`, which is the same analysis the commit re-runs (D9: one predicate offers and
// accepts). Names come from the roster props this page already holds, because the preview and the
// report carry person ids and counts ONLY — no address, and above all no link. A bulk path has
// nowhere to surface fifty one-time bearer credentials and does not try.
const resendForm = useForm({ person_ids: [], digest: null });

const bulkPreview = computed(() => page.props.flash?.invitation_bulk_preview ?? null);
const bulkResendReport = computed(() => page.props.flash?.invitation_bulk_report ?? null);

const personName = (id) => props.people.find((p) => p.id === Number(id))?.full_name ?? `Person #${id}`;

const previewResend = () => {
    resendForm.clearErrors();
    resendForm
        .transform(() => ({ person_ids: Array.from(selected.value) }))
        .post('/admin/invitations/bulk-resend/preview', { preserveScroll: true });
};

// The selection is sent again rather than trusted from the preview: the endpoint re-resolves and
// re-authorizes everything, and the digest is what proves the two agree.
const confirmResend = (plan) => {
    resendForm.clearErrors();
    resendForm
        .transform(() => ({
            person_ids: plan.rows.map((row) => row.person_id),
            digest: plan.digest,
        }))
        .post('/admin/invitations/bulk-resend', {
            preserveScroll: true,
            onSuccess: () => { selected.value = new Set(); },
        });
};

const resendOutcomeLabels = {
    resend: 'Will be sent a new link',
    sent: 'New link sent',
    mail_failed: 'Could not be delivered — try again',
    skipped_has_account: 'Skipped — already claimed',
    skipped_no_email: 'Skipped — no email address',
    skipped_inactive: 'Skipped — not on the active roster',
    skipped_no_invitation: 'Skipped — never invited',
    skipped_revoked: 'Skipped — invitation revoked',
};

const resendRows = (plan) => plan.rows.map((row) => ({
    id: row.person_id,
    name: personName(row.person_id),
    label: resendOutcomeLabels[row.outcome] ?? row.outcome,
    reason: row.reason,
    failed: row.outcome === 'mail_failed',
}));
</script>

<template>
    <AppLayout title="People">
        <div class="mx-auto max-w-6xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">People</h2>
                <p class="text-sm text-muted">
                    The departmental roster — everyone named on a sheet or a rota, whether or not
                    they have ever logged in. A roster-only person (an external consultant, say)
                    is invisible to Admin → Users by construction; this screen is where they live.
                    Creating or editing a person here never creates an account — the invitation
                    flow (Admin → Users) is the only way one is made.
                </p>
            </div>

            <section v-if="Object.keys(contact_visibilities).length" class="rounded-md border border-line bg-panel p-5">
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="submitVisibility">
                    <div>
                        <label class="channel-tag mb-1 block" for="contact-visibility">Contact visibility</label>
                        <select id="contact-visibility" v-model="visibilityForm.contact_visibility"
                                class="w-full max-w-xs rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none">
                            <option v-for="(label, key) in contact_visibilities" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <p v-if="visibilityForm.errors.contact_visibility" class="mt-1 text-xs text-critical">
                            {{ visibilityForm.errors.contact_visibility }}
                        </p>
                    </div>
                    <button type="submit" :disabled="visibilityForm.processing"
                            class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                        Save
                    </button>
                    <span v-if="visibilityForm.recentlySuccessful" class="text-sm text-ok" role="status">Saved.</span>
                </form>
                <p class="mt-2 text-xs text-muted">
                    Notes are always restricted to roster managers, whatever this is set to.
                </p>
            </section>

            <!-- New person -->
            <section class="rounded-md border border-line bg-panel p-5">
                <button type="button" class="text-sm font-semibold text-ink" @click="createOpen = !createOpen">
                    {{ createOpen ? '– Hide' : '+ New person' }}
                </button>

                <form v-if="createOpen" class="mt-4 space-y-4" @submit.prevent="submitCreate">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="channel-tag mb-1 block" for="new-full-name">Full name</label>
                            <input id="new-full-name" v-model="createForm.full_name" type="text" :class="inputClass" maxlength="255" />
                            <p v-if="createForm.errors.full_name" class="mt-1 text-xs text-critical">{{ createForm.errors.full_name }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-short-name">Short name (rota handle)</label>
                            <input id="new-short-name" v-model="createForm.short_name" type="text" :class="inputClass" maxlength="50" />
                            <p v-if="createForm.errors.short_name" class="mt-1 text-xs text-critical">{{ createForm.errors.short_name }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-position">Role</label>
                            <select id="new-position" v-model.number="createForm.position" :class="inputClass">
                                <option v-for="p in positionOptions()" :key="p.id" :value="p.id">{{ p.name }}</option>
                            </select>
                            <p v-if="createForm.errors.position" class="mt-1 text-xs text-critical">{{ createForm.errors.position }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-joined-at">Joined</label>
                            <input id="new-joined-at" v-model="createForm.joined_at" type="date" :class="inputClass" />
                            <p v-if="createForm.errors.joined_at" class="mt-1 text-xs text-critical">{{ createForm.errors.joined_at }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-email">Email</label>
                            <input id="new-email" v-model="createForm.email" type="email" :class="inputClass" maxlength="255" />
                            <p v-if="createForm.errors.email" class="mt-1 text-xs text-critical">{{ createForm.errors.email }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-phone">Phone</label>
                            <input id="new-phone" v-model="createForm.phone" type="text" :class="inputClass" maxlength="32" />
                            <p v-if="createForm.errors.phone" class="mt-1 text-xs text-critical">{{ createForm.errors.phone }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="channel-tag mb-1 block" for="new-notes">Notes</label>
                            <textarea id="new-notes" v-model="createForm.notes" :class="inputClass" rows="2" maxlength="5000"></textarea>
                            <p v-if="createForm.errors.notes" class="mt-1 text-xs text-critical">{{ createForm.errors.notes }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="channel-tag mb-1 block" for="new-constraints">Scheduling constraints (JSON, optional)</label>
                            <textarea id="new-constraints" v-model="createForm.constraintsText" :class="inputClass" rows="2"
                                      placeholder='{"no_nights": true}'></textarea>
                            <p v-if="createForm.errors.constraints" class="mt-1 text-xs text-critical">{{ createForm.errors.constraints }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-4">
                        <label class="flex items-center gap-2 text-sm text-body">
                            <input v-model="createForm.active" type="checkbox" />
                            Active
                        </label>
                        <label class="flex items-center gap-2 text-sm text-body">
                            <input v-model="createForm.external" type="checkbox" />
                            External rotator
                        </label>
                    </div>

                    <div class="flex items-center gap-3 border-t border-line-soft pt-4">
                        <button type="submit" :disabled="createForm.processing"
                                class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                            Create person
                        </button>
                        <span v-if="createForm.recentlySuccessful" class="text-sm text-ok" role="status">Saved.</span>
                    </div>
                </form>
            </section>

            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <label class="channel-tag mb-1 block" for="people-search">Search</label>
                    <input id="people-search" v-model="search" type="search"
                           class="w-full max-w-sm rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none"
                           placeholder="Name…" />
                </div>
                <label class="flex items-center gap-2 text-sm text-body">
                    <input type="checkbox" :checked="allFilteredSelected" @change="toggleSelectAllFiltered" />
                    Select all filtered ({{ filteredPeople.length }})
                </label>
            </div>

            <!-- LV-02's bulk action bar: appears once anything is selected, sticky so it stays
                 reachable while scrolling a long roster. -->
            <div v-if="selectionCount > 0" class="sticky top-0 z-10 flex flex-wrap items-center gap-3 rounded-md border border-line bg-ground-deep p-4">
                <span class="channel-tag">{{ selectionCount }} selected</span>

                <label class="flex items-center gap-2 text-sm text-body">
                    <select v-model.number="bulkLevelId" :class="inputClass" class="w-auto">
                        <option v-for="l in levels" :key="l.id" :value="l.id">{{ l.code }} — {{ l.name }}</option>
                    </select>
                    <button type="button" :disabled="bulkForm.processing || !bulkLevelId"
                            class="min-h-11 rounded-md border border-line bg-panel px-3 py-2 text-sm font-semibold text-ink disabled:opacity-60"
                            @click="bulkSetLevel">
                        Set level
                    </button>
                </label>

                <button type="button" :disabled="bulkForm.processing"
                        class="min-h-11 rounded-md border border-line bg-panel px-3 py-2 text-sm font-semibold text-ink disabled:opacity-60"
                        @click="bulkActivate">
                    Activate
                </button>
                <button type="button" :disabled="bulkForm.processing"
                        class="min-h-11 rounded-md border border-line bg-panel px-3 py-2 text-sm font-semibold text-ink disabled:opacity-60"
                        @click="bulkDeactivate">
                    Deactivate
                </button>
                <button type="button" class="min-h-11 rounded-md border border-line bg-panel px-3 py-2 text-sm font-semibold text-ink"
                        @click="submitExport">
                    Export CSV
                </button>
                <!-- LV-02's bulk resend. The cap is stated BESIDE the button, from the server's own
                     constant, because a batch silently truncated to fifty is a batch whose
                     fifty-first person never finds out they were not mailed. -->
                <span class="flex items-center gap-2">
                    <button type="button" :disabled="resendForm.processing"
                            class="min-h-11 rounded-md border border-line bg-panel px-3 py-2 text-sm font-semibold text-ink disabled:opacity-60"
                            data-testid="bulk-resend-preview"
                            @click="previewResend">
                        Resend invitations
                    </button>
                    <span class="text-xs text-muted">up to {{ invitation_resend_cap }} at a time</span>
                </span>

                <p v-if="bulkForm.errors.ids" class="w-full text-xs text-critical">{{ bulkForm.errors.ids }}</p>
                <p v-if="resendForm.errors.person_ids" class="w-full text-xs text-critical" data-testid="bulk-resend-error">
                    {{ resendForm.errors.person_ids }}
                </p>
                <p v-if="resendForm.errors.digest" class="w-full text-xs text-critical" data-testid="bulk-resend-stale">
                    {{ resendForm.errors.digest }}
                </p>
            </div>

            <!-- The plan, before it is made. Nothing has been written or sent at this point. -->
            <div v-if="bulkPreview" data-testid="bulk-resend-preview-panel"
                 class="rounded-md border border-line bg-panel p-4">
                <p class="channel-tag mb-2">Resend invitations — preview</p>
                <p class="mb-3 text-sm text-body">
                    {{ bulkPreview.summary.selected }} selected.
                    <span class="font-semibold text-ink">{{ bulkPreview.summary.will_send }}</span>
                    {{ bulkPreview.summary.will_send === 1 ? 'email' : 'emails' }} will be sent;
                    {{ bulkPreview.summary.skipped }} skipped. Each person's previous link stops
                    working the moment the new one is created.
                </p>
                <ul class="mb-4 space-y-1 text-sm text-body">
                    <li v-for="row in resendRows(bulkPreview)" :key="row.id">
                        <span class="readout font-semibold text-ink">{{ row.name }}</span> — {{ row.label }}
                        <span v-if="row.reason" class="text-xs text-muted">({{ row.reason }})</span>
                    </li>
                </ul>
                <button type="button" :disabled="resendForm.processing || bulkPreview.summary.will_send === 0"
                        class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                        data-testid="bulk-resend-confirm"
                        @click="confirmResend(bulkPreview)">
                    Send {{ bulkPreview.summary.will_send }}
                    {{ bulkPreview.summary.will_send === 1 ? 'invitation' : 'invitations' }}
                </button>
            </div>

            <!-- What actually happened, per person. A partial send is an expected outcome, not an
                 error: the rows exist either way, so the operator can select just the failures and
                 run it again. -->
            <div v-if="bulkResendReport" data-testid="bulk-resend-report"
                 class="rounded-md border border-line bg-panel p-4">
                <p class="channel-tag mb-2">Resend invitations — result</p>
                <p class="mb-3 text-sm text-body">
                    {{ bulkResendReport.summary.sent }} sent,
                    {{ bulkResendReport.summary.failed }} could not be delivered,
                    {{ bulkResendReport.summary.skipped }} skipped.
                </p>
                <ul class="space-y-1 text-sm text-body">
                    <li v-for="row in resendRows(bulkResendReport)" :key="row.id">
                        <span class="readout font-semibold text-ink">{{ row.name }}</span> —
                        <span :class="row.failed ? 'text-critical' : ''">{{ row.label }}</span>
                    </li>
                </ul>
            </div>

            <!-- An Invite or Resend the server refused. The controls are offered only where the
                 endpoint would accept them, so this is what a stale page looks like — somebody
                 claimed, or somebody revoked, since this list was rendered. -->
            <div v-if="errors.invitation" role="alert" data-testid="invitation-error"
                 class="channel-bar channel-bar-critical rounded-md bg-critical-soft px-4 py-3 text-sm text-ink">
                {{ errors.invitation }}
            </div>

            <!-- Shown once, immediately after an Invite. The link is a bearer credential that
                 creates an account: it is stored hashed and can never be re-displayed, so if mail
                 is not configured this panel IS the delivery. -->
            <div v-if="invitationLink" data-testid="invitation-link"
                 class="channel-bar channel-bar-ok rounded-md bg-ok-soft px-4 py-3 text-sm">
                <p class="mb-1 font-semibold text-ink">Invitation link — shown only once</p>
                <p class="readout break-all text-xs text-ink">{{ invitationLink }}</p>
            </div>

            <!-- The last bulk action's per-person outcome — from the writer's own return
                 values, never "Done." -->
            <div v-if="bulkReport.length" class="rounded-md border border-line bg-panel p-4">
                <p class="channel-tag mb-2">Last bulk action</p>
                <ul class="space-y-1 text-sm text-body">
                    <li v-for="row in bulkReport" :key="row.id">
                        <span class="readout font-semibold text-ink">{{ row.name }}</span> — {{ row.label }}
                    </li>
                </ul>
            </div>

            <!-- Phone: one card per person. -->
            <div class="space-y-3 lg:hidden">
                <article v-for="person in filteredPeople" :key="person.id" class="rounded-md border border-line bg-panel p-4">
                    <div v-if="editingId !== person.id">
                        <div class="flex items-baseline justify-between gap-3">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" :checked="isSelected(person.id)" @change="toggleSelected(person.id)" />
                                <span class="readout text-sm font-semibold text-ink">{{ person.full_name }}</span>
                            </label>
                            <span class="channel-tag">{{ person.active ? 'Active' : 'Retired' }}</span>
                        </div>
                        <p class="text-sm text-body">{{ positionName(person.position) }} · {{ person.level?.code ?? '—' }}</p>
                        <p v-if="person.short_name" class="text-xs text-muted">{{ person.short_name }}</p>
                        <p v-if="'phone' in person" class="readout text-xs text-body">{{ person.phone || '—' }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <span class="channel-tag">{{ person.has_account ? 'Account' : 'Roster only' }}</span>
                            <span v-if="person.invitation" class="channel-tag">{{ person.invitation.label }}</span>
                            <span v-if="person.external" class="channel-tag">External</span>
                        </div>
                        <p v-if="person.invitation?.at" class="readout text-xs text-muted">
                            {{ person.invitation.at.date }} {{ person.invitation.at.time }}
                            <span>({{ person.invitation.at.hijri }})</span>
                        </p>
                        <div class="mt-3 flex gap-3">
                            <button type="button" class="text-xs font-semibold text-channel-ink" @click="startEdit(person)">Edit</button>
                            <button type="button" class="text-xs font-semibold text-channel-ink" @click="toggleHistory(person)">
                                {{ openHistoryId === person.id ? 'Hide history' : 'History' }}
                            </button>
                            <button v-if="canGrantRoles" type="button" class="text-xs font-semibold text-channel-ink"
                                    :aria-label="`Roles for ${person.full_name}`"
                                    :aria-expanded="openRolesId === person.id ? 'true' : 'false'"
                                    @click="toggleRoles(person)">
                                {{ openRolesId === person.id ? 'Hide roles' : 'Roles' }}
                            </button>
                            <button v-if="canInvite(person)" type="button" :disabled="invitingId === person.id"
                                    class="text-xs font-semibold text-channel-ink disabled:opacity-60"
                                    :aria-label="`Invite ${person.full_name} to create an account`"
                                    @click="invite(person)">
                                Invite
                            </button>
                            <button v-if="canResend(person)" type="button" :disabled="invitingId === person.id"
                                    class="text-xs font-semibold text-channel-ink disabled:opacity-60"
                                    :aria-label="`Send ${person.full_name} a new invitation link`"
                                    @click="resend(person)">
                                Resend
                            </button>
                        </div>
                        <div v-if="openHistoryId === person.id" class="mt-3 border-t border-line-soft pt-3">
                            <p v-if="historyLoading && !historySpans" class="text-xs text-muted">Loading…</p>
                            <p v-else-if="historySpans && historySpans.length === 0" class="text-xs text-muted">
                                No level history recorded.
                            </p>
                            <ul v-else-if="historySpans" class="space-y-2">
                                <li v-for="(h, i) in historySpans" :key="i" class="text-xs text-body">
                                    <div class="flex items-baseline gap-2">
                                        <span class="channel-tag">{{ h.level.code }}</span>
                                        <span class="text-muted">{{ h.level.name }}</span>
                                    </div>
                                    <p class="readout text-body">
                                        {{ h.from.date }} <span class="text-muted">({{ h.from.hijri }})</span>
                                        &rarr;
                                        <span v-if="h.to">{{ h.to.date }} <span class="text-muted">({{ h.to.hijri }})</span></span>
                                        <span v-else class="channel-tag">current</span>
                                    </p>
                                    <p v-if="h.reason || h.by" class="text-muted">
                                        <span v-if="h.reason">{{ h.reason }}</span>
                                        <span v-if="h.by"> — recorded by {{ h.by }}</span>
                                    </p>
                                </li>
                            </ul>
                        </div>
                        <div v-if="canGrantRoles && openRolesId === person.id" class="mt-3 border-t border-line-soft pt-3">
                            <p v-if="!person.has_account" class="text-xs text-body">
                                This person has no account. Roles are granted to an account — invite them first.
                            </p>
                            <div v-else>
                                <div v-for="cap in capabilityCatalog" :key="cap.id" class="mb-2">
                                    <label class="channel-tag mb-1 block" :for="`m-person-${person.id}-cap-${cap.id}`">
                                        {{ cap.label }} <span class="readout text-muted">{{ cap.key }}</span>
                                    </label>
                                    <select :id="`m-person-${person.id}-cap-${cap.id}`"
                                            :value="roleOverrideFor(cap.id)"
                                            class="w-full rounded-md border border-line bg-panel px-2 py-2 text-xs text-ink focus:border-channel focus:outline-none"
                                            @change="setRoleOverride(cap.id, $event.target.value)">
                                        <option value="inherit">Inherit (role default)</option>
                                        <option value="grant">Grant</option>
                                        <option value="deny">Deny</option>
                                    </select>
                                </div>
                                <p v-if="rolesForm.errors.overrides" class="mt-2 text-xs text-critical" role="alert">
                                    {{ rolesForm.errors.overrides }}
                                </p>
                                <p v-if="rolesForm.errors.person_id" class="mt-2 text-xs text-critical" role="alert">
                                    {{ rolesForm.errors.person_id }}
                                </p>
                                <button type="button" :disabled="rolesForm.processing"
                                        class="mt-2 min-h-11 w-full rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                                        @click="saveRoles">
                                    Save roles
                                </button>
                                <p class="mt-3 text-xs text-muted">
                                    Roles belong to the account, not the person. If this person leaves and later
                                    returns on a new account, an administrator grants their roles again — they are
                                    not restored automatically.
                                </p>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-xs text-muted">Editing below — use the desktop table for the full form, or resize the window.</p>
                </article>
            </div>

            <!-- Desktop: a table. -->
            <div class="hidden overflow-x-auto rounded-md border border-line bg-panel lg:block">
                <table class="w-full text-left text-sm">
                    <thead class="bg-ground-deep">
                        <tr>
                            <th scope="col" class="px-4 py-2">
                                <span class="sr-only">Select</span>
                            </th>
                            <th scope="col" class="channel-tag px-4 py-2">Name</th>
                            <th scope="col" class="channel-tag px-4 py-2">Short name</th>
                            <th scope="col" class="channel-tag px-4 py-2">Role</th>
                            <th scope="col" class="channel-tag px-4 py-2">Level</th>
                            <th v-if="showsPhone" scope="col" class="channel-tag px-4 py-2">Phone</th>
                            <th scope="col" class="channel-tag px-4 py-2">Status</th>
                            <th scope="col" class="channel-tag px-4 py-2">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="person in filteredPeople" :key="person.id">
                            <tr v-if="editingId !== person.id" class="border-t border-line">
                                <td class="px-4 py-2">
                                    <input type="checkbox" :aria-label="`Select ${person.full_name}`"
                                           :checked="isSelected(person.id)" @change="toggleSelected(person.id)" />
                                </td>
                                <td class="readout px-4 py-2 font-semibold text-ink">{{ person.full_name }}</td>
                                <td class="readout px-4 py-2 text-body">{{ person.short_name || '—' }}</td>
                                <td class="px-4 py-2 text-body">{{ positionName(person.position) }}</td>
                                <td class="readout px-4 py-2 text-body">{{ person.level?.code ?? '—' }}</td>
                                <td v-if="'phone' in person" class="readout px-4 py-2 text-body">{{ person.phone || '—' }}</td>
                                <td class="px-4 py-2">
                                    <div class="flex flex-wrap gap-2">
                                        <span class="channel-tag">{{ person.active ? 'Active' : 'Retired' }}</span>
                                        <span class="channel-tag">{{ person.has_account ? 'Account' : 'Roster only' }}</span>
                                        <span v-if="person.invitation" class="channel-tag"
                                              :data-testid="`claim-state-${person.id}`">{{ person.invitation.label }}</span>
                                        <span v-if="person.external" class="channel-tag">External</span>
                                    </div>
                                    <p v-if="person.invitation?.at" class="readout mt-1 text-xs text-muted">
                                        {{ person.invitation.at.time }}
                                        <span>({{ person.invitation.at.hijri }})</span>
                                    </p>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <button type="button" class="mr-3 text-xs font-semibold text-channel-ink" @click="startEdit(person)">Edit</button>
                                    <button type="button" class="mr-3 text-xs font-semibold text-channel-ink" @click="toggleHistory(person)">
                                        {{ openHistoryId === person.id ? 'Hide history' : 'History' }}
                                    </button>
                                    <button v-if="canGrantRoles" type="button" class="mr-3 text-xs font-semibold text-channel-ink"
                                            :data-testid="`roles-${person.id}`"
                                            :aria-label="`Roles for ${person.full_name}`"
                                            :aria-expanded="openRolesId === person.id ? 'true' : 'false'"
                                            @click="toggleRoles(person)">
                                        {{ openRolesId === person.id ? 'Hide roles' : 'Roles' }}
                                    </button>
                                    <button v-if="canInvite(person)" type="button" :disabled="invitingId === person.id"
                                            :data-testid="`invite-${person.id}`"
                                            class="text-xs font-semibold text-channel-ink disabled:opacity-60"
                                            :aria-label="`Invite ${person.full_name} to create an account`"
                                            @click="invite(person)">
                                        Invite
                                    </button>
                                    <button v-if="canResend(person)" type="button" :disabled="invitingId === person.id"
                                            :data-testid="`resend-${person.id}`"
                                            class="text-xs font-semibold text-channel-ink disabled:opacity-60"
                                            :aria-label="`Send ${person.full_name} a new invitation link`"
                                            @click="resend(person)">
                                        Resend
                                    </button>
                                </td>
                            </tr>
                            <tr v-else class="border-t border-line bg-ground-deep">
                                <td :colspan="showsPhone ? 8 : 7" class="px-4 py-4">
                                    <form class="space-y-4" @submit.prevent="submitEdit(person)">
                                        <div class="grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <label class="channel-tag mb-1 block" :for="`edit-full-name-${person.id}`">Full name</label>
                                                <input :id="`edit-full-name-${person.id}`" v-model="editForm.full_name" type="text" :class="inputClass" maxlength="255" />
                                                <p v-if="editForm.errors.full_name" class="mt-1 text-xs text-critical">{{ editForm.errors.full_name }}</p>
                                            </div>
                                            <div>
                                                <label class="channel-tag mb-1 block" :for="`edit-short-name-${person.id}`">Short name</label>
                                                <input :id="`edit-short-name-${person.id}`" v-model="editForm.short_name" type="text" :class="inputClass" maxlength="50" />
                                                <p v-if="editForm.errors.short_name" class="mt-1 text-xs text-critical">{{ editForm.errors.short_name }}</p>
                                            </div>
                                            <div>
                                                <label class="channel-tag mb-1 block" :for="`edit-position-${person.id}`">Role</label>
                                                <select :id="`edit-position-${person.id}`" v-model.number="editForm.position" :class="inputClass">
                                                    <option v-for="p in positionOptions(person.position)" :key="p.id" :value="p.id">{{ p.name }}</option>
                                                </select>
                                                <p v-if="editForm.errors.position" class="mt-1 text-xs text-critical">{{ editForm.errors.position }}</p>
                                            </div>
                                            <div>
                                                <label class="channel-tag mb-1 block" :for="`edit-joined-at-${person.id}`">Joined</label>
                                                <input :id="`edit-joined-at-${person.id}`" v-model="editForm.joined_at" type="date" :class="inputClass" />
                                                <p v-if="editForm.errors.joined_at" class="mt-1 text-xs text-critical">{{ editForm.errors.joined_at }}</p>
                                            </div>
                                            <div>
                                                <label class="channel-tag mb-1 block" :for="`edit-email-${person.id}`">Email</label>
                                                <input :id="`edit-email-${person.id}`" v-model="editForm.email" type="email" :class="inputClass" maxlength="255" />
                                                <p v-if="editForm.errors.email" class="mt-1 text-xs text-critical">{{ editForm.errors.email }}</p>
                                            </div>
                                            <div v-if="'phone' in person">
                                                <label class="channel-tag mb-1 block" :for="`edit-phone-${person.id}`">Phone</label>
                                                <input :id="`edit-phone-${person.id}`" v-model="editForm.phone" type="text" :class="inputClass" maxlength="32" />
                                                <p v-if="editForm.errors.phone" class="mt-1 text-xs text-critical">{{ editForm.errors.phone }}</p>
                                            </div>
                                            <div class="sm:col-span-3" v-if="'notes' in person">
                                                <label class="channel-tag mb-1 block" :for="`edit-notes-${person.id}`">Notes</label>
                                                <textarea :id="`edit-notes-${person.id}`" v-model="editForm.notes" :class="inputClass" rows="2" maxlength="5000"></textarea>
                                                <p v-if="editForm.errors.notes" class="mt-1 text-xs text-critical">{{ editForm.errors.notes }}</p>
                                            </div>
                                            <div class="sm:col-span-3">
                                                <label class="channel-tag mb-1 block" :for="`edit-constraints-${person.id}`">Scheduling constraints (JSON, optional)</label>
                                                <textarea :id="`edit-constraints-${person.id}`" v-model="editForm.constraintsText" :class="inputClass" rows="2"></textarea>
                                                <p v-if="editForm.errors.constraints" class="mt-1 text-xs text-critical">{{ editForm.errors.constraints }}</p>
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap gap-4">
                                            <label class="flex items-center gap-2 text-sm text-body">
                                                <input v-model="editForm.active" type="checkbox" />
                                                Active
                                            </label>
                                            <label class="flex items-center gap-2 text-sm text-body">
                                                <input v-model="editForm.external" type="checkbox" />
                                                External rotator
                                            </label>
                                        </div>

                                        <div class="flex items-center gap-3">
                                            <button type="submit" :disabled="editForm.processing"
                                                    class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                                                Save
                                            </button>
                                            <button type="button" class="text-sm font-semibold text-body" @click="cancelEdit">Cancel</button>
                                            <span v-if="editForm.recentlySuccessful" class="text-sm text-ok" role="status">Saved.</span>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                            <tr v-if="editingId !== person.id && openHistoryId === person.id" class="border-t border-line bg-ground-deep">
                                <td :colspan="showsPhone ? 8 : 7" class="px-4 py-3">
                                    <p v-if="historyLoading && !historySpans" class="text-xs text-muted">Loading…</p>
                                    <p v-else-if="historySpans && historySpans.length === 0" class="text-xs text-muted">
                                        No level history recorded.
                                    </p>
                                    <table v-else-if="historySpans" class="w-full text-left text-xs">
                                        <thead>
                                            <tr>
                                                <th scope="col" class="channel-tag pb-1 pr-4">Level</th>
                                                <th scope="col" class="channel-tag pb-1 pr-4">From</th>
                                                <th scope="col" class="channel-tag pb-1 pr-4">To</th>
                                                <th scope="col" class="channel-tag pb-1 pr-4">Reason</th>
                                                <th scope="col" class="channel-tag pb-1">Recorded by</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(h, i) in historySpans" :key="i" class="border-t border-line-soft">
                                                <td class="readout py-1 pr-4 text-body">{{ h.level.code }} <span class="text-muted">{{ h.level.name }}</span></td>
                                                <td class="readout py-1 pr-4 text-body">
                                                    {{ h.from.date }}
                                                    <span class="block text-muted">{{ h.from.hijri }}</span>
                                                </td>
                                                <td class="readout py-1 pr-4 text-body">
                                                    <span v-if="h.to">
                                                        {{ h.to.date }}
                                                        <span class="block text-muted">{{ h.to.hijri }}</span>
                                                    </span>
                                                    <span v-else class="channel-tag">current</span>
                                                </td>
                                                <td class="py-1 pr-4 text-body">{{ h.reason || '—' }}</td>
                                                <td class="py-1 text-body">{{ h.by || '—' }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                            <!-- AC-04's roles panel. Gated on `access.manage`, NOT on the
                                 `people.manage` that opens this screen — see the script block. -->
                            <tr v-if="canGrantRoles && editingId !== person.id && openRolesId === person.id"
                                class="border-t border-line bg-ground-deep">
                                <td :colspan="showsPhone ? 8 : 7" class="px-4 py-3">
                                    <div v-if="!person.has_account" data-testid="roles-no-account">
                                        <p class="text-sm text-body">
                                            This person has no account. Roles are granted to an account — invite them first.
                                        </p>
                                    </div>
                                    <div v-else>
                                        <table class="w-full max-w-3xl text-left text-xs">
                                            <caption class="sr-only">Capability overrides for {{ person.full_name }}</caption>
                                            <thead>
                                                <tr>
                                                    <th scope="col" class="channel-tag pb-1 pr-4">Capability</th>
                                                    <th scope="col" class="channel-tag pb-1">Override</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="cap in capabilityCatalog" :key="cap.id" class="border-t border-line-soft">
                                                    <th scope="row" class="py-1 pr-4 text-left font-normal">
                                                        <span class="font-medium text-ink">{{ cap.label }}</span>
                                                        <span class="readout ml-2 text-muted">{{ cap.key }}</span>
                                                        <span v-if="cap.description" class="mt-0.5 block max-w-md font-normal text-muted">{{ cap.description }}</span>
                                                    </th>
                                                    <td class="py-1">
                                                        <select :value="roleOverrideFor(cap.id)"
                                                                :data-testid="`person-${person.id}-cap-${cap.id}`"
                                                                :aria-label="`Override ${cap.key} for ${person.full_name}`"
                                                                class="rounded-md border border-line bg-panel px-2 py-1 text-xs text-ink focus:border-channel focus:outline-none"
                                                                @change="setRoleOverride(cap.id, $event.target.value)">
                                                            <option value="inherit">Inherit (role default)</option>
                                                            <option value="grant">Grant</option>
                                                            <option value="deny">Deny</option>
                                                        </select>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                        <p v-if="rolesForm.errors.overrides" class="mt-2 text-xs text-critical" role="alert">
                                            {{ rolesForm.errors.overrides }}
                                        </p>
                                        <p v-if="rolesForm.errors.person_id" class="mt-2 text-xs text-critical" role="alert">
                                            {{ rolesForm.errors.person_id }}
                                        </p>
                                        <div class="mt-3 flex flex-wrap items-center gap-3">
                                            <button type="button" :disabled="rolesForm.processing"
                                                    :data-testid="`save-roles-${person.id}`"
                                                    class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                                                    @click="saveRoles">
                                                Save roles
                                            </button>
                                            <span v-if="rolesForm.recentlySuccessful" class="text-xs text-ok" role="status">Saved.</span>
                                        </div>
                                        <!-- Stated where an operator will read it, not left as folklore.
                                             Auto-restoring privileges when a person is re-bound to a new
                                             account means a departed administrator's grants silently
                                             reattach to whoever claims that identity next, and nobody
                                             reviews a restore that nobody performed. -->
                                        <p class="mt-3 max-w-2xl text-xs text-muted">
                                            Roles belong to the account, not the person. If this person leaves and later
                                            returns on a new account, an administrator grants their roles again — they are
                                            not restored automatically.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
