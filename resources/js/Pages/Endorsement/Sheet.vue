<script setup>
import { computed, ref, onBeforeUnmount } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import RichTextEditor from '../../Components/RichTextEditor.vue';
import SaveStatus from '../../Components/SaveStatus.vue';
import { useCan } from '../../Composables/useCan.js';

/**
 * C2 — the editable shift-endorsement / handover sheet. G1 — the registry is PICU-only, so the
 * unit-specific columns the other units used (NICU/SCBU neonatal DOB, WARD age + sub-unit) are
 * gone along with their server flags. The four rich-text fields (disease/details/plan/nevent) are
 * edited through `RichTextEditor`, which supplies the formatting toolbar restored in G3; the server
 * sanitizes them on write (HTMLPurifier allow-list) so the echoed values are safe to render. The
 * server `cap:` gates are the real authority — `canEdit` only decides whether the write affordances
 * show.
 */
const props = defineProps({
    unit: { type: Object, default: () => ({ code: '', name: '' }) },
    date: { type: String, default: '' },
    // UX-04 dual dating + Decision A's adjacent-day navigation — all three are pre-formatted
    // server-side (App\Support\Calendar) so this component does no date arithmetic of its own.
    date_hijri: { type: String, default: '' },
    next_date: { type: String, default: '' },
    previous_date: { type: String, default: '' },
    rows: { type: Array, default: () => [] },
    // H/GAP-5 — the day's shift attestation + the staff pickers behind it (see below).
    signoff: { type: Object, default: () => ({}) },
    staff: { type: Object, default: () => ({ endorsers: [], consultants: [] }) },
    timeOptions: { type: Array, default: () => [] },
});

const { can } = useCan();
const canEdit = can('endorsement.edit');

// The per-unit shape, defined once server-side (App\Support\UnitProfile).
const profile = computed(() => props.unit.profile ?? {
    extra_row_fields: [], bed_label: 'Bed', bar_class: '', narrative_label: 'New events',
});

// The four rich-text handover fields, with the column heading each one lives under.
// The narrative label is per-unit ("New events" on PICU, "To be followed" elsewhere).
const richFields = computed(() => [
    { key: 'disease', label: 'Disease' },
    { key: 'details', label: 'Details' },
    { key: 'plan', label: 'Plan' },
    { key: 'nevent', label: profile.value.narrative_label },
]);

// The extra per-unit identity columns (NICU/SCBU: dob; WARD: age + sub-unit).
const extraFields = computed(() => (profile.value.extra_row_fields ?? []).map((key) => ({
    key,
    label: key === 'dob' ? 'DOB' : (key === 'age' ? 'Age' : 'Unit/Speciality'),
    type: key === 'dob' ? 'datetime-local' : 'text',
})));

// datetime-local wants 'YYYY-MM-DDTHH:mm'; the server sends 'YYYY-MM-DD HH:mm'.
const extraValue = (row, key) => (key === 'dob' ? String(row.dob ?? '').replace(' ', 'T') : (row[key] ?? ''));

/*
 * P0b (design §6.2, "Ceiling 2") — a unit's OWN custom fields, driven entirely by data
 * (`unit.profile.field_definitions`: active-only, already ordered — see
 * EndorsementController::unitPayload()). This is a SECOND, SEPARATE list from `extraFields`
 * above: those are hardcoded named identity columns (dob/age/ward_unit) with their own
 * column on `handovers`; these are unit-defined and keyed into the single `extra_fields` JSON
 * map on each row. The two concepts are rendered one after the other, never merged.
 */
const customFields = computed(() => profile.value.field_definitions ?? []);

// Custom-field values are PLAIN TEXT and are NEVER PURIFIED server-side (App\Casts\EncryptedJson's
// own docblock) — every read here MUST stay a plain value binding (`:value`, never `v-html`).
const customFieldValue = (row, key) => (row.extra_fields ?? {})[key] ?? '';

// EncryptedJson's sentinel (App\Casts\EncryptedJson::UNREADABLE_KEY): the row's WHOLE
// extra_fields column failed to decrypt (foreign APP_KEY). A renderer keyed strictly on
// definitions would drop this silently and show a clean but incomplete clinical sheet — this
// is the entire reason the sentinel exists, so it gets its own visible, row-level warning.
const extraFieldsWarning = (row) => (row.extra_fields ?? {}).__unreadable ?? null;

// bed + mrn + name, plus every rendered column, plus the delete button column when present —
// so the desktop unreadable-warning row's colspan always spans the FULL width of that table,
// whatever combination of identity/custom columns this unit happens to have.
const desktopColumnCount = computed(() => (
    3 + extraFields.value.length + customFields.value.length + richFields.value.length + (canEdit ? 1 : 0)
));

// Presentation only: the signature channel bar carries this sheet's unit hue.
const unitBarClass = computed(() => profile.value.bar_class ?? '');

/*
 * G3 — per-field save state, keyed `rowId:field`, so the confirmation lands on the cell that was
 * actually written rather than as one page-level flash. `saved` self-clears; `error` persists until
 * the next attempt, because a silently-lost handover edit is the failure that matters here.
 */
const fieldStatus = ref({});
const timers = {};

const setStatus = (id, field, value) => {
    const key = `${id}:${field}`;
    fieldStatus.value = { ...fieldStatus.value, [key]: value };

    clearTimeout(timers[key]);
    if (value === 'saved') {
        timers[key] = setTimeout(() => {
            const next = { ...fieldStatus.value };
            delete next[key];
            fieldStatus.value = next;
        }, 2500);
    }
};

const statusOf = (id, field) => fieldStatus.value[`${id}:${field}`] ?? '';

onBeforeUnmount(() => Object.values(timers).forEach(clearTimeout));

const saveField = (id, field, value) => {
    setStatus(id, field, 'saving');
    router.patch(`/endorsement/rows/${id}`, { [field]: value }, {
        preserveScroll: true,
        // G3 — preserveState was false, which re-created the page component and wiped the per-field
        // indicator before it could be seen. The write is single-field and the editor re-syncs from
        // the refreshed prop whenever it is not focused, so preserving state is safe.
        preserveState: true,
        onSuccess: () => setStatus(id, field, 'saved'),
        onError: () => setStatus(id, field, 'error'),
    });
};

// Inline-save a plain-text cell (bed / mrn / name).
const saveText = (id, field, event) => {
    saveField(id, field, event.target.value);
};

/*
 * P0b — a custom field saves through the SAME PATCH endpoint and the SAME per-field
 * save-on-blur/change path as every other cell, shaped {extra_fields: {[key]: value}}. The
 * server (EndorsementController::updateRow()) merges this one key into the row's stored map
 * (array_replace) rather than replacing the whole column, so sending one key here is safe —
 * do NOT invent a second save mechanism or send the whole map from the client.
 */
const saveCustomField = (id, key, value) => {
    const statusKey = `extra_fields.${key}`;
    setStatus(id, statusKey, 'saving');
    router.patch(`/endorsement/rows/${id}`, { extra_fields: { [key]: value } }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => setStatus(id, statusKey, 'saved'),
        onError: () => setStatus(id, statusKey, 'error'),
    });
};

const saveCustomText = (id, key, event) => {
    saveCustomField(id, key, event.target.value);
};

const addRow = () => {
    router.post(`/endorsement/${props.unit.code}/${props.date}/rows`, {
        bed: '',
        mrn: '',
        patient_name: '',
    }, { preserveScroll: true });
};

const deleteRow = (id) => {
    router.delete(`/endorsement/rows/${id}`, { preserveScroll: true });
};

/*
 * G3 — this button used to post the date already open, which the server short-circuits as
 * "already exists", making it a silent no-op. It now starts the NEXT day's sheet, which is what
 * "new day" means from inside a sheet: you finish today's handover and roll the census forward to
 * tomorrow. Creating a sheet for an arbitrary or past date lives on the day index, where a date
 * picker makes the target explicit.
 *
 * Decision A — `next_date` is server-formatted (App\Support\Calendar), replacing the deleted
 * browser-side date construction that used to rewind local midnight to the SAME date at
 * +03:00 and make this button a silent no-op.
 */
const newDay = () => {
    router.post(`/endorsement/${props.unit.code}/new-day`, { date: props.next_date });
};

/*
 * H / GAP-5 — the SHIFT SIGN-OFF, the medico-legal attestation the re-platform had dropped
 * (legacy `validate-endorsement.php`; the pickers at `picu-endorsement-patients.php:362/397/431`).
 * It is per DAY, not per row, so it lives in one panel above the census rather than in the table.
 *
 * Both endorser fields are pickers over real user accounts — legacy left the two consultant fields
 * as free text, which is how a handover sheet ends up attesting to a misspelled name. The server
 * freezes the chosen NAME at sign-off, so this record does not change if someone is later renamed.
 */
const signForm = ref({
    endorsed_by_person_id: props.signoff?.endorsed_by_person_id ?? '',
    endorsed_to_person_id: props.signoff?.endorsed_to_person_id ?? '',
    consultant_by_person_id: props.signoff?.consultant_by_person_id ?? '',
    consultant_to_person_id: props.signoff?.consultant_to_person_id ?? '',
});

/*
 * ACTUAL CLOCK TIME. Legacy offered exactly two shift labels and nothing else, so a handover that
 * genuinely happened at 02:40 could not be recorded as 02:40. The two labels stay as the QUICK
 * DEFAULT — routine 07:30 / 15:30 use is one click, unchanged — and "Other time" reveals a real
 * time input for everything else. The server normalizes a non-legacy entry to 24-hour HH:MM and
 * stores the unambiguous minutes alongside it.
 */
const CUSTOM_TIME = '__custom__';
const isLegacyLabel = (v) => props.timeOptions.includes(v);
const initialTime = props.signoff?.endorsement_time ?? '';
const timeMode = ref(initialTime && !isLegacyLabel(initialTime) ? CUSTOM_TIME : initialTime);
const customTime = ref(timeMode.value === CUSTOM_TIME ? initialTime : '');

const effectiveTime = computed(() => (
    timeMode.value === CUSTOM_TIME ? (customTime.value || null) : (timeMode.value || null)
));

const signError = ref('');
const isSigned = computed(() => Boolean(props.signoff?.signed_off));

const signPayload = (extra = {}) => ({
    // '' is the "nobody selected" option; send it as null so the server clears the field.
    endorsed_by_person_id: signForm.value.endorsed_by_person_id || null,
    endorsed_to_person_id: signForm.value.endorsed_to_person_id || null,
    consultant_by_person_id: signForm.value.consultant_by_person_id || null,
    consultant_to_person_id: signForm.value.consultant_to_person_id || null,
    endorsement_time: effectiveTime.value,
    ...extra,
});

const submitSignoff = (sign) => {
    signError.value = '';
    router.patch(`/endorsement/${props.unit.code}/${props.date}/signoff`, signPayload({ sign_off: sign }), {
        preserveScroll: true,
        onError: (errors) => {
            signError.value = Object.values(errors)[0] ?? 'Could not save the sign-off.';
        },
    });
};

// The audited correction path. A signed day is locked; reopening it demands a reason, records who
// reopened it and why, and erases nothing — see EndorsementController::reopenSignoff().
const showReopen = ref(false);
const reopenReason = ref('');

const submitReopen = () => {
    signError.value = '';
    router.post(`/endorsement/${props.unit.code}/${props.date}/signoff/reopen`, { reason: reopenReason.value }, {
        preserveScroll: true,
        onSuccess: () => {
            showReopen.value = false;
            reopenReason.value = '';
        },
        onError: (errors) => {
            signError.value = Object.values(errors)[0] ?? 'Could not reopen the handover.';
        },
    });
};
</script>

<template>
    <AppLayout :title="`${unit.code} Endorsement`">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl font-semibold text-ink">{{ unit.name }} — Handover</h2>
                <p class="text-sm text-muted">
                    <span class="readout">{{ date }}</span>
                    <!-- UX-04 dual dating — server-formatted, from Calendar::hijriLabel(). -->
                    <span v-if="date_hijri" data-testid="date-hijri"> · {{ date_hijri }}</span>
                    ·
                    <span class="readout">{{ rows.length }}</span> patient(s)
                </p>
            </div>
            <div class="flex items-center gap-2">
                <!-- Decision A — `previous_date` is server-formatted, no client date arithmetic. -->
                <Link :href="`/endorsement/${unit.code}/${previous_date}`" data-testid="previous-day"
                      class="rounded-md border border-line px-3 py-1.5 text-sm text-body hover:bg-ground-deep">
                    Previous day
                </Link>
                <Link :href="`/endorsement/${unit.code}`" class="rounded-md border border-line px-3 py-1.5 text-sm text-body hover:bg-ground-deep">
                    Day index
                </Link>
                <!--
                  No target="_blank". From an installed PWA that opens the SYSTEM browser,
                  which has a different cookie jar — so on a phone "Print" could land on the
                  sign-in page instead of the sheet. The print page drives itself
                  (window.print on mount) and carries its own way back.
                -->
                <Link :href="`/endorsement/${unit.code}/${date}/print`"
                      class="rounded-md border border-line px-3 py-1.5 text-sm text-body hover:bg-ground-deep">
                    Print
                </Link>
                <button v-if="canEdit" type="button" data-testid="new-day" @click="newDay"
                        :title="`Carry this census forward to ${next_date}`"
                        class="rounded-md bg-channel px-3 py-1.5 text-sm font-semibold text-panel hover:bg-channel-ink">
                    Start next day
                </button>
                <button v-if="canEdit" type="button" data-testid="add-row" @click="addRow"
                        class="rounded-md bg-ok px-3 py-1.5 text-sm font-semibold text-panel hover:bg-ok/90">
                    Add patient
                </button>
            </div>
        </div>

        <!--
          Ruling 1 — "To be followed" carries forward on a new day, exactly as the deployed
          legacy system does. Follow-up items persist until a clinician clears them.
        -->
        <p data-testid="nevent-note" class="mb-4 rounded-md border border-line bg-ground px-3 py-2 text-xs text-muted">
            "{{ richFields[3].label }}" <strong class="text-body">carries forward</strong> with the census on every
            new day — clear an item once it has been dealt with, or it stays on tomorrow's sheet too.
        </p>

        <!--
          H/GAP-5 — the shift sign-off. Per DAY (legacy wrote one `endorsement` header row per date),
          so it sits above the census, not inside the table.
        -->
        <section data-testid="signoff-panel" aria-labelledby="signoff-heading"
                 class="channel-bar mb-5 rounded-md border border-line bg-panel p-4"
                 :class="isSigned ? 'channel-bar-ok' : ''">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 id="signoff-heading" class="text-sm font-semibold text-ink">Shift sign-off</h3>
                <span v-if="isSigned" data-testid="signoff-state-signed"
                      class="rounded-md border border-ok px-2 py-0.5 text-xs font-semibold text-ok">
                    Signed off <span class="readout">{{ signoff.signed_off_at }}</span>
                    <span v-if="signoff.signed_off_by_name"> by {{ signoff.signed_off_by_name }}</span>
                </span>
                <span v-else data-testid="signoff-state-unsigned"
                      class="rounded-md border border-line px-2 py-0.5 text-xs font-semibold text-muted">
                    Not signed
                </span>
            </div>

            <p v-if="signoff.reopened_at" data-testid="signoff-reopened"
               class="mb-3 rounded-md border border-line bg-ground px-3 py-2 text-xs text-muted">
                Reopened for correction on <span class="readout">{{ signoff.reopened_at }}</span>.
                Reason: {{ signoff.reopen_reason }}
            </p>

            <p v-if="signError" data-testid="signoff-error" role="alert" class="mb-3 text-xs font-semibold text-critical">
                {{ signError }}
            </p>

            <!-- Signed: the attestation is read-only until it is reopened through the audited path. -->
            <dl v-if="isSigned" data-testid="signoff-summary" class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                <div class="flex gap-2"><dt class="channel-tag">Endorsed by</dt><dd class="text-ink">{{ signoff.endorsed_by_name || '—' }}</dd></div>
                <div class="flex gap-2"><dt class="channel-tag">Endorsed to</dt><dd class="text-ink">{{ signoff.endorsed_to_name || '—' }}</dd></div>
                <div class="flex gap-2"><dt class="channel-tag">{{ profile.consultant_by_label || 'Consultant covering' }}</dt><dd class="text-ink">{{ signoff.consultant_by_name || '—' }}</dd></div>
                <div v-if="profile.consultant_pair !== false" class="flex gap-2"><dt class="channel-tag">Consultant receiving</dt><dd class="text-ink">{{ signoff.consultant_to_name || '—' }}</dd></div>
                <div class="flex gap-2"><dt class="channel-tag">Time</dt><dd class="readout text-ink">{{ signoff.endorsement_time || '—' }}</dd></div>
            </dl>

            <form v-else-if="canEdit" data-testid="signoff-form" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                  @submit.prevent="submitSignoff(true)">
                <div>
                    <label for="endorsed-by" class="channel-tag mb-1 block">Endorsed by</label>
                    <select id="endorsed-by" v-model="signForm.endorsed_by_person_id" data-testid="endorsed-by"
                            class="w-full rounded-md border border-line bg-panel px-2 py-1.5 text-sm text-ink focus:border-channel focus:outline-none">
                        <option value="">Select</option>
                        <option v-for="s in staff.endorsers" :key="s.id" :value="s.id" :disabled="s.retired">
                            {{ s.retired ? `${s.name} (no longer offered)` : s.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label for="endorsed-to" class="channel-tag mb-1 block">Endorsed to</label>
                    <select id="endorsed-to" v-model="signForm.endorsed_to_person_id" data-testid="endorsed-to"
                            class="w-full rounded-md border border-line bg-panel px-2 py-1.5 text-sm text-ink focus:border-channel focus:outline-none">
                        <option value="">Select</option>
                        <option v-for="s in staff.endorsers" :key="s.id" :value="s.id" :disabled="s.retired">
                            {{ s.retired ? `${s.name} (no longer offered)` : s.name }}
                        </option>
                    </select>
                </div>
                <!--
                    The two legacy shift times stay the quick pick; "Other time" records a handover
                    that genuinely happened off-schedule, at the clock time it happened.
                -->
                <div>
                    <label for="endorsement-time" class="channel-tag mb-1 block">Endorsement at</label>
                    <select id="endorsement-time" v-model="timeMode" data-testid="endorsement-time"
                            class="readout w-full rounded-md border border-line bg-panel px-2 py-1.5 text-sm text-ink focus:border-channel focus:outline-none">
                        <option value="">Select</option>
                        <option v-for="t in timeOptions" :key="t" :value="t">{{ t }}</option>
                        <option :value="CUSTOM_TIME">Other time&hellip;</option>
                    </select>
                    <input v-if="timeMode === CUSTOM_TIME" v-model="customTime" data-testid="endorsement-time-custom"
                           type="time" aria-label="Actual handover time"
                           class="readout mt-1 w-full rounded-md border border-line bg-panel px-2 py-1.5 text-sm text-ink focus:border-channel focus:outline-none" />
                </div>
                <div>
                    <!-- Ruling 5 — WARD's single field is labelled "Consultant Oncall" (profile-driven). -->
                    <label for="consultant-by" class="channel-tag mb-1 block">{{ profile.consultant_by_label || 'Consultant covering' }}</label>
                    <select id="consultant-by" v-model="signForm.consultant_by_person_id" data-testid="consultant-by"
                            class="w-full rounded-md border border-line bg-panel px-2 py-1.5 text-sm text-ink focus:border-channel focus:outline-none">
                        <option value="">Select</option>
                        <option v-for="s in staff.consultants" :key="s.id" :value="s.id" :disabled="s.retired">
                            {{ s.retired ? `${s.name} (no longer offered)` : s.name }}
                        </option>
                    </select>
                </div>
                <div v-if="profile.consultant_pair !== false">
                    <label for="consultant-to" class="channel-tag mb-1 block">Consultant receiving</label>
                    <select id="consultant-to" v-model="signForm.consultant_to_person_id" data-testid="consultant-to"
                            class="w-full rounded-md border border-line bg-panel px-2 py-1.5 text-sm text-ink focus:border-channel focus:outline-none">
                        <option value="">Select</option>
                        <option v-for="s in staff.consultants" :key="s.id" :value="s.id" :disabled="s.retired">
                            {{ s.retired ? `${s.name} (no longer offered)` : s.name }}
                        </option>
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" data-testid="signoff-submit"
                            class="rounded-md bg-channel px-3 py-1.5 text-sm font-semibold text-panel hover:bg-channel-ink">
                        Sign off
                    </button>
                    <button type="button" data-testid="signoff-save-draft" @click="submitSignoff(false)"
                            class="rounded-md border border-line px-3 py-1.5 text-sm font-semibold text-channel-ink hover:bg-channel-soft">
                        Save without signing
                    </button>
                </div>
            </form>

            <p v-else data-testid="signoff-readonly" class="text-sm text-muted">
                This handover has not been signed off.
            </p>

            <!--
                The audited correction path: reversible, never silent, never destructive — and gated
                on its OWN capability (`endorsement.reopen`), because reopening reverses another
                clinician's attestation rather than merely editing a sheet.

                Task 3(a) — this panel used to say "restricted to an Administrator". That is no longer
                true and, more importantly, no longer USEFUL: the capability is grantable per role and
                per named user, so the people who can actually help may include a senior clinician and
                may EXCLUDE an administrator who was denied it. `reopen_contacts` is therefore
                resolved server-side from the grant tables (App\Support\AccessControl::holdersOf) and
                the wording defers to it. Never re-introduce a hard-coded role name here — a ward at
                03:00 acting on a stale one rings the wrong person.
            -->
            <div v-if="isSigned && canEdit && !signoff.can_reopen" data-testid="signoff-reopen-blocked"
                 class="mt-3 border-t border-line-soft pt-3 text-xs leading-relaxed text-muted">
                Reopening a signed handover needs the
                <span class="font-semibold">reopen handover</span> permission, because it reverses
                another clinician's attestation.
                <span v-if="signoff.reopen_contacts && signoff.reopen_contacts.length" data-testid="reopen-contacts">
                    Ask one of the people who hold it to reopen the sheet, with the reason for the
                    correction:
                    <span class="font-semibold text-body">{{ signoff.reopen_contacts.join(', ') }}</span>.
                </span>
                <span v-else>
                    No active account currently holds that permission. Ask your registry
                    administrator to authorise someone before this sheet can be corrected.
                </span>
            </div>

            <div v-else-if="isSigned && canEdit && signoff.can_reopen" class="mt-3 border-t border-line-soft pt-3">
                <button v-if="!showReopen" type="button" data-testid="signoff-reopen-open" @click="showReopen = true"
                        class="text-xs font-semibold text-channel-ink hover:underline">
                    Reopen for correction
                </button>
                <form v-else data-testid="signoff-reopen-form" class="flex flex-wrap items-end gap-2" @submit.prevent="submitReopen">
                    <div class="grow">
                        <label for="reopen-reason" class="channel-tag mb-1 block">Reason for reopening (recorded)</label>
                        <input id="reopen-reason" v-model="reopenReason" data-testid="reopen-reason" type="text"
                               class="w-full rounded-md border border-line bg-panel px-2 py-1.5 text-sm text-ink focus:border-channel focus:outline-none" />
                    </div>
                    <button type="submit" data-testid="signoff-reopen-submit"
                            class="rounded-md border border-line px-3 py-1.5 text-sm font-semibold text-channel-ink hover:bg-channel-soft">
                        Reopen
                    </button>
                    <button type="button" data-testid="signoff-reopen-cancel" @click="showReopen = false"
                            class="rounded-md px-3 py-1.5 text-sm text-muted hover:text-body hover:underline">
                        Cancel
                    </button>
                </form>
            </div>
        </section>

        <p v-if="rows.length === 0" class="rounded-md border border-dashed border-line p-8 text-center text-sm text-muted">
            No handover rows for this day.
        </p>

        <!--
          MOBILE (below md): a card per patient — the wide table cannot be used one-handed on a
          phone, and horizontal scrolling hides columns. Same rows, same save handlers, same
          per-field status; only the layout differs. Inputs are text-base (16px) so iOS Safari
          does not zoom the page on focus.
        -->
        <div v-if="rows.length > 0" class="space-y-3 md:hidden" data-testid="mobile-cards">
            <section v-for="r in rows" :key="r.id" :data-row-id="r.id" data-testid="handover-card"
                     class="channel-bar rounded-md border border-line bg-panel p-3" :class="unitBarClass">
                <div class="mb-2 grid grid-cols-3 gap-2">
                    <div>
                        <label :for="`m-bed-${r.id}`" class="channel-tag mb-0.5 block">{{ profile.bed_label }}</label>
                        <input :id="`m-bed-${r.id}`" :value="r.bed" :readonly="!canEdit" inputmode="text"
                               class="readout w-full rounded-md border border-line bg-panel px-2 py-2 text-base focus:border-channel focus:outline-none"
                               @change="saveText(r.id, 'bed', $event)" />
                        <SaveStatus :status="statusOf(r.id, 'bed')" testid="m-status-bed" />
                    </div>
                    <div class="col-span-2">
                        <label :for="`m-mrn-${r.id}`" class="channel-tag mb-0.5 block">MRN</label>
                        <input :id="`m-mrn-${r.id}`" :value="r.mrn" :readonly="!canEdit" inputmode="numeric"
                               class="readout w-full rounded-md border border-line bg-panel px-2 py-2 text-base focus:border-channel focus:outline-none"
                               @change="saveText(r.id, 'mrn', $event)" />
                        <SaveStatus :status="statusOf(r.id, 'mrn')" testid="m-status-mrn" />
                    </div>
                </div>
                <div class="mb-3">
                    <label :for="`m-name-${r.id}`" class="channel-tag mb-0.5 block">Patient name</label>
                    <input :id="`m-name-${r.id}`" :value="r.patient_name" :readonly="!canEdit"
                           class="w-full rounded-md border border-line bg-panel px-2 py-2 text-base text-ink focus:border-channel focus:outline-none"
                           @change="saveText(r.id, 'patient_name', $event)" />
                    <SaveStatus :status="statusOf(r.id, 'patient_name')" testid="m-status-patient_name" />
                </div>
                <div v-for="x in extraFields" :key="x.key" class="mb-3">
                    <label :for="`m-${x.key}-${r.id}`" class="channel-tag mb-0.5 block">{{ x.label }}</label>
                    <input :id="`m-${x.key}-${r.id}`" :type="x.type" :value="extraValue(r, x.key)" :readonly="!canEdit"
                           class="w-full rounded-md border border-line bg-panel px-2 py-2 text-base text-ink focus:border-channel focus:outline-none"
                           :class="x.key === 'dob' ? 'readout' : ''"
                           @change="saveText(r.id, x.key, $event)" />
                    <SaveStatus :status="statusOf(r.id, x.key)" :testid="`m-status-${x.key}`" />
                </div>
                <!--
                  P0b — the unit's OWN custom fields (design §6.2, "Ceiling 2"). A SECOND,
                  separate list from `extraFields` above, rendered after them.
                -->
                <p v-if="extraFieldsWarning(r)" data-testid="m-extra-fields-unreadable" role="alert"
                   class="channel-bar channel-bar-critical mb-3 rounded-md bg-critical-soft px-2 py-1.5 text-xs font-semibold text-critical">
                    Custom fields unreadable: {{ extraFieldsWarning(r) }}
                </p>
                <div v-for="cf in customFields" :key="cf.key" :data-testid="`m-cf-${cf.key}`" class="mb-3">
                    <label :for="`m-cf-${cf.key}-${r.id}`" class="channel-tag mb-0.5 block">
                        {{ cf.label }}<span v-if="cf.required" aria-hidden="true"> *</span>
                    </label>
                    <select v-if="cf.type === 'select'" :id="`m-cf-${cf.key}-${r.id}`"
                            :value="customFieldValue(r, cf.key)" :disabled="!canEdit"
                            class="w-full rounded-md border border-line bg-panel px-2 py-2 text-base text-ink focus:border-channel focus:outline-none"
                            @change="saveCustomText(r.id, cf.key, $event)">
                        <option value="">Select</option>
                        <option v-for="opt in cf.options ?? []" :key="opt" :value="opt">{{ opt }}</option>
                    </select>
                    <input v-else :id="`m-cf-${cf.key}-${r.id}`" :type="cf.type === 'date' ? 'date' : 'text'"
                           :value="customFieldValue(r, cf.key)" :readonly="!canEdit"
                           class="w-full rounded-md border border-line bg-panel px-2 py-2 text-base text-ink focus:border-channel focus:outline-none"
                           @change="saveCustomText(r.id, cf.key, $event)" />
                    <SaveStatus :status="statusOf(r.id, `extra_fields.${cf.key}`)" :testid="`m-status-cf-${cf.key}`" />
                </div>
                <div v-for="f in richFields" :key="f.key" class="mb-3">
                    <p class="channel-tag mb-0.5">{{ f.label }}</p>
                    <RichTextEditor :model-value="r[f.key]" :editable="canEdit" :label="f.label"
                                    :status="statusOf(r.id, f.key)"
                                    @save="(html) => saveField(r.id, f.key, html)" />
                </div>
                <div v-if="canEdit" class="text-right">
                    <button type="button" @click="deleteRow(r.id)"
                            class="min-h-11 px-2 text-xs text-critical hover:underline">Remove patient</button>
                </div>
            </section>
        </div>

        <!-- DESKTOP (md and up): the dense census table. -->
        <div v-if="rows.length > 0" class="channel-bar hidden overflow-x-auto rounded-md border border-line md:block" :class="unitBarClass">
            <table class="min-w-full divide-y divide-line-soft text-sm">
                <thead class="bg-ground-deep text-left">
                    <tr>
                        <th class="channel-tag px-3 py-2">{{ profile.bed_label }}</th>
                        <th class="channel-tag px-3 py-2">MRN</th>
                        <th class="channel-tag px-3 py-2">Name</th>
                        <th v-for="x in extraFields" :key="x.key" class="channel-tag px-3 py-2">{{ x.label }}</th>
                        <th v-for="cf in customFields" :key="cf.key" class="channel-tag px-3 py-2">{{ cf.label }}</th>
                        <th v-for="f in richFields" :key="f.key" class="channel-tag px-3 py-2">{{ f.label }}</th>
                        <th v-if="canEdit" class="channel-tag px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft bg-panel">
                    <!--
                      `data-row-id` is the row's stable server identity. The census is re-sorted by
                      BED on every read (bedComparator), so a row's position changes the moment its
                      bed is edited — anything addressing a row by index or by rendered value is
                      addressing a moving target. The browser e2e harness needs to come back to the
                      same row after a reload to prove a handover edit persisted, and this is the
                      only non-PHI handle that survives the re-sort.
                    -->
                    <template v-for="r in rows" :key="r.id">
                    <tr data-testid="handover-row" :data-row-id="r.id" class="align-top">
                        <td class="px-2 py-2">
                            <input :value="r.bed" data-testid="cell-bed" :readonly="!canEdit" aria-label="Bed"
                                   class="readout w-16 rounded-md border border-transparent bg-transparent px-1 py-0.5 hover:border-line focus:border-channel focus:outline-none"
                                   @change="saveText(r.id, 'bed', $event)" />
                            <SaveStatus :status="statusOf(r.id, 'bed')" testid="status-bed" />
                        </td>
                        <td class="px-2 py-2">
                            <input :value="r.mrn" :readonly="!canEdit" aria-label="MRN"
                                   class="readout w-24 rounded-md border border-transparent bg-transparent px-1 py-0.5 hover:border-line focus:border-channel focus:outline-none"
                                   @change="saveText(r.id, 'mrn', $event)" />
                            <SaveStatus :status="statusOf(r.id, 'mrn')" testid="status-mrn" />
                        </td>
                        <td class="px-2 py-2">
                            <input :value="r.patient_name" :readonly="!canEdit" aria-label="Patient name"
                                   class="w-32 rounded-md border border-transparent bg-transparent px-1 py-0.5 text-ink hover:border-line focus:border-channel focus:outline-none"
                                   @change="saveText(r.id, 'patient_name', $event)" />
                            <SaveStatus :status="statusOf(r.id, 'patient_name')" testid="status-patient_name" />
                        </td>
                        <td v-for="x in extraFields" :key="x.key" :data-testid="`cell-${x.key}`" class="px-2 py-2">
                            <input :type="x.type" :value="extraValue(r, x.key)" :readonly="!canEdit" :aria-label="x.label"
                                   class="w-36 rounded-md border border-transparent bg-transparent px-1 py-0.5 hover:border-line focus:border-channel focus:outline-none"
                                   :class="x.key === 'dob' ? 'readout' : ''"
                                   @change="saveText(r.id, x.key, $event)" />
                            <SaveStatus :status="statusOf(r.id, x.key)" :testid="`status-${x.key}`" />
                        </td>
                        <!-- P0b — the unit's OWN custom fields, a second column set after extraFields. -->
                        <td v-for="cf in customFields" :key="cf.key" :data-testid="`cell-cf-${cf.key}`" class="px-2 py-2">
                            <select v-if="cf.type === 'select'" :value="customFieldValue(r, cf.key)" :disabled="!canEdit"
                                    :aria-label="cf.label"
                                    class="w-36 rounded-md border border-transparent bg-transparent px-1 py-0.5 hover:border-line focus:border-channel focus:outline-none"
                                    @change="saveCustomText(r.id, cf.key, $event)">
                                <option value="">Select</option>
                                <option v-for="opt in cf.options ?? []" :key="opt" :value="opt">{{ opt }}</option>
                            </select>
                            <input v-else :type="cf.type === 'date' ? 'date' : 'text'" :value="customFieldValue(r, cf.key)"
                                   :readonly="!canEdit" :aria-label="cf.label"
                                   class="w-36 rounded-md border border-transparent bg-transparent px-1 py-0.5 hover:border-line focus:border-channel focus:outline-none"
                                   @change="saveCustomText(r.id, cf.key, $event)" />
                            <SaveStatus :status="statusOf(r.id, `extra_fields.${cf.key}`)" :testid="`status-cf-${cf.key}`" />
                        </td>
                        <td v-for="f in richFields" :key="f.key" :data-testid="`cell-${f.key}`" class="px-2 py-2">
                            <RichTextEditor :model-value="r[f.key]" :editable="canEdit" :label="f.label"
                                            :status="statusOf(r.id, f.key)"
                                            @save="(html) => saveField(r.id, f.key, html)" />
                        </td>
                        <td v-if="canEdit" class="px-2 py-2 text-right">
                            <button type="button" data-testid="delete-row" @click="deleteRow(r.id)"
                                    class="text-xs text-critical hover:underline">Remove</button>
                        </td>
                    </tr>
                    <!--
                      P0b — the sentinel row. `extra_fields.__unreadable` means the WHOLE map
                      failed to decrypt (foreign APP_KEY); a renderer keyed on definitions alone
                      would drop it and show a clean but silently incomplete clinical sheet.
                    -->
                    <tr v-if="extraFieldsWarning(r)" data-testid="row-extra-fields-unreadable" :data-row-id="r.id">
                        <td :colspan="desktopColumnCount" class="bg-critical-soft px-3 py-1.5 text-xs font-semibold text-critical">
                            Custom fields unreadable: {{ extraFieldsWarning(r) }}
                        </td>
                    </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
