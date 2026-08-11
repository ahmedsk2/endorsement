<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Admin → Structure → Clinics (Munawib CL-01 / CL-02).
 *
 * THIS COMPONENT NAMES NO DAY OF THE WEEK. The `weekdays` prop arrives from
 * `App\Support\Calendar::weekdayColumns()` already labelled AND already rotated to the order this
 * department's week runs in — the two common weekend configurations start the week on different
 * days, and both come from the same stored ISO integers with nothing rewritten. A literal array of
 * seven day names here would be a second answer to "what order does the week run in", which is
 * exactly the drift AR-08 exists to prevent; every label a row shows (`weekday_label`,
 * `session_label`, `mode_label`) is likewise built server-side. `Clinics.test.js` needles this file
 * for such an array, because the source guard over `resources/js` looks for date CONSTRUCTION and
 * would not see one.
 *
 * THIS COMPONENT NAMES NO UNIT EITHER. `units` is the offered list of clinic-owning units and
 * `clinics` is grouped by the units that actually have one — units are configuration, so a fifth
 * department appears here with no frontend change.
 *
 * THERE IS NO DELETE. A clinic that stopped running is deactivated (UN-04: hides forward, never
 * deletes history) — the same shape the Units, Levels and Holidays screens take, and the route does
 * not exist to call.
 *
 * FREE TEXT IS INTERPOLATED, AND ONLY INTERPOLATED. A clinic's name, location and note are plain
 * text and are stored verbatim — never purified server-side, exactly like `handovers.extra_fields` —
 * so escaping is this file's job, `{{ }}` is how it is done, and the raw-markup directive appears
 * nowhere below. `Clinics.test.js` asserts that over this file's own source, which is why the
 * directive is not spelled out even here.
 */
const props = defineProps({
    clinics: { type: Array, default: () => [] },
    units: { type: Array, default: () => [] },
    weekdays: { type: Array, default: () => [] },
    sessions: { type: Object, default: () => ({}) },
    modes: { type: Object, default: () => ({}) },
    levels: { type: Array, default: () => [] },
    people: { type: Array, default: () => [] },
    today: { type: Object, default: () => ({}) },
});

const inputClass = 'w-full rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none';

// The table's headings, written once and counted, so a column added here cannot leave a `colspan`
// behind pointing at the old width. The last is the actions column and is announced but not drawn.
const headings = ['Day', 'Session', 'Clinic', 'Who attends', 'Today', 'Actions'];
const columnCount = computed(() => headings.length);

/**
 * The two refinement modes that need a picker. These mirror `Clinic::ATTENDEE_MODES`' KEYS, which
 * are the values the server sends on every clinic — the LABELS are never written here, they arrive
 * in the `modes` prop, and so does the offered list each one picks from. Naming the keys is how the
 * component knows which picker a mode wants; it is not a second definition of the modes themselves,
 * and positional indexing into the prop was rejected as the alternative because re-ordering the
 * constant would then silently swap two pickers.
 */
const MODE_LEVELS = 'levels';
const MODE_NAMED = 'named';

const sessionKeys = computed(() => Object.keys(props.sessions));

const levelName = (id) => {
    const level = props.levels.find((l) => l.id === id);

    return level ? `${level.code} — ${level.name}` : null;
};

const personName = (id) => {
    const person = props.people.find((p) => p.id === id);

    return person ? person.full_name : null;
};

// What the clinic's rule set comes down to in words, for the row that is not being edited. An
// attached subject the pickers no longer offer (a retired level, a departed colleague) arrives
// pre-labelled in `unlisted` rather than silently missing.
const ruleSummary = (clinic) => {
    if (clinic.attendee_mode === MODE_LEVELS) {
        return clinic.level_ids.map((id) => levelName(id) || unlistedLabel(clinic, 'level', id)).join(', ');
    }

    if (clinic.attendee_mode === MODE_NAMED) {
        return clinic.person_ids.map((id) => personName(id) || unlistedLabel(clinic, 'person', id)).join(', ');
    }

    return '';
};

const unlistedLabel = (clinic, kind, id) => {
    const row = clinic.unlisted.find((u) => u.kind === kind && u.id === id);

    return row ? row.label : `#${id}`;
};

// --- Create ---------------------------------------------------------------------------------

const createOpen = ref(false);

const blankCreateForm = () => ({
    unit_id: props.units.length ? props.units[0].id : null,
    name: '',
    weekday: props.weekdays.length ? props.weekdays[0].iso : null,
    session: sessionKeys.value.length ? sessionKeys.value[0] : null,
    location: '',
    note: '',
});

const createForm = useForm(blankCreateForm());

const submitCreate = () => {
    createForm.post('/admin/structure/clinics', {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
            createOpen.value = false;
        },
    });
};

// --- Edit (one clinic open at a time) ---------------------------------------------------------

const editingId = ref(null);

const editForm = useForm({
    unit_id: null, name: '', weekday: null, session: null, location: '', note: '',
});

const startEdit = (clinic) => {
    editingId.value = clinic.id;
    editForm.clearErrors();
    editForm.unit_id = clinic.unit_id;
    editForm.name = clinic.name;
    editForm.weekday = clinic.weekday;
    editForm.session = clinic.session;
    editForm.location = clinic.location ?? '';
    editForm.note = clinic.note ?? '';
};

const cancelEdit = () => {
    editingId.value = null;
    editForm.clearErrors();
};

const submitEdit = (clinic) => {
    editForm.patch(`/admin/structure/clinics/${clinic.id}`, {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null; },
    });
};

// --- Stop / restart (UN-04) --------------------------------------------------------------------

const activeForm = useForm({ active: true });

/**
 * WHICH clinic the last Stop/Restart was pressed on, so its refusal renders THERE.
 *
 * `activeForm` is one form shared by every row's button — there is one field and it would be
 * wasteful to mint a form per clinic — so `activeForm.errors.active` is page-global. Rendered
 * unguarded it would hang the same refusal under every clinic on the page, most of which were
 * never touched; not rendered at all (which is what shipped) the button simply appears to do
 * nothing, because `ClinicWriter` refuses to revive a clinic onto a unit that has since been
 * retired or stopped owning clinics, and that refusal reached no element (P1e-1 adversarial review
 * finding 3).
 */
const activeErrorId = ref(null);

const toggleActive = (clinic) => {
    activeForm.active = !clinic.active;
    activeErrorId.value = null;
    activeForm.clearErrors();
    activeForm.patch(`/admin/structure/clinics/${clinic.id}/active`, {
        preserveScroll: true,
        onError: () => { activeErrorId.value = clinic.id; },
    });
};

// --- Who attends (CL-02) ------------------------------------------------------------------------

const attendeesId = ref(null);

const attendeesForm = useForm({ mode: 'rotators', level_ids: [], person_ids: [] });

/**
 * Seeded from what the PICKERS OFFER, intersected with what the clinic stores — never from the
 * stored list alone.
 *
 * An id with no checkbox is an id the administrator cannot untick, so carrying it into the form
 * makes it unremovable: it goes back on every save, the server refuses the request under
 * `level_ids.N` / `person_ids.N` — keys nothing below renders — and the panel sits open having
 * reported nothing at all. A retired training level or a departed colleague locked the clinic's
 * whole rule set that way, permanently (P1e-1 adversarial review finding 1).
 *
 * Dropping them here is also what makes the banner below honest: it says "No longer offered, and
 * saving will drop them", and now that is what saving does.
 */
const offeredOnly = (ids, offered) => ids.filter((id) => offered.some((row) => row.id === id));

const startAttendees = (clinic) => {
    attendeesId.value = clinic.id;
    attendeesForm.clearErrors();
    attendeesForm.mode = clinic.attendee_mode;
    // Copies, not the prop arrays: a checkbox bound straight to the prop would edit the page's own
    // data before anything was saved.
    attendeesForm.level_ids = offeredOnly(clinic.level_ids, props.levels);
    attendeesForm.person_ids = offeredOnly(clinic.person_ids, props.people);
};

const cancelAttendees = () => {
    attendeesId.value = null;
    attendeesForm.clearErrors();
};

const submitAttendees = (clinic) => {
    attendeesForm.put(`/admin/structure/clinics/${clinic.id}/attendees`, {
        preserveScroll: true,
        onSuccess: () => { attendeesId.value = null; },
    });
};
</script>

<template>
    <AppLayout title="Clinics">
        <div class="mx-auto max-w-5xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">Clinics</h2>
                <p class="text-sm text-muted">
                    A clinic is the one part of the department's week the master rota does not
                    describe: an owning unit, a day, a morning or afternoon session, and who attends.
                    Only units marked as owning clinics on
                    <a href="/admin/structure/units" class="underline">Units</a> may hold one. The
                    week below runs in this department's own order, set on
                    <a href="/admin/structure/calendar" class="underline">Calendar settings</a>.
                    There is no delete — a clinic that stops running is marked stopped, so anything
                    already pointing at it keeps working.
                </p>
                <p v-if="today.date" class="channel-tag mt-2">
                    Attendance resolved for {{ today.date }}<span v-if="today.hijri"> · {{ today.hijri }}</span>
                </p>
            </div>

            <!-- New clinic -->
            <section class="rounded-md border border-line bg-panel p-5">
                <button type="button" class="text-sm font-semibold text-ink" @click="createOpen = !createOpen">
                    {{ createOpen ? '– Hide' : '+ New clinic' }}
                </button>

                <p v-if="createOpen && !units.length" class="mt-3 text-sm text-critical">
                    No unit owns clinics yet. Tick “owns clinics” on a unit first.
                </p>

                <form v-else-if="createOpen" class="mt-4 space-y-4" @submit.prevent="submitCreate">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="channel-tag mb-1 block" for="new-unit">Unit</label>
                            <select id="new-unit" v-model.number="createForm.unit_id" :class="inputClass">
                                <option v-for="unit in units" :key="unit.id" :value="unit.id">
                                    {{ unit.code }} — {{ unit.name }}
                                </option>
                            </select>
                            <p v-if="createForm.errors.unit_id" class="mt-1 text-xs text-critical">{{ createForm.errors.unit_id }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-name">Name</label>
                            <input id="new-name" v-model="createForm.name" type="text" :class="inputClass" maxlength="120" />
                            <p v-if="createForm.errors.name" class="mt-1 text-xs text-critical">{{ createForm.errors.name }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-weekday">Day</label>
                            <select id="new-weekday" v-model.number="createForm.weekday" :class="inputClass">
                                <option v-for="day in weekdays" :key="day.iso" :value="day.iso">{{ day.label }}</option>
                            </select>
                            <p v-if="createForm.errors.weekday" class="mt-1 text-xs text-critical">{{ createForm.errors.weekday }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-session">Session</label>
                            <select id="new-session" v-model="createForm.session" :class="inputClass">
                                <option v-for="(label, key) in sessions" :key="key" :value="key">{{ label }}</option>
                            </select>
                            <p v-if="createForm.errors.session" class="mt-1 text-xs text-critical">{{ createForm.errors.session }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-location">Location</label>
                            <input id="new-location" v-model="createForm.location" type="text" :class="inputClass" maxlength="120" />
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-note">Note</label>
                            <input id="new-note" v-model="createForm.note" type="text" :class="inputClass" maxlength="500" />
                        </div>
                    </div>

                    <div class="flex items-center gap-3 border-t border-line-soft pt-4">
                        <button type="submit" :disabled="createForm.processing"
                                class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                            Add clinic
                        </button>
                        <span v-if="createForm.recentlySuccessful" class="text-sm text-ok" role="status">Saved.</span>
                    </div>
                </form>
            </section>

            <p v-if="!clinics.length" class="rounded-md border border-line bg-panel p-5 text-sm text-muted">
                No clinics yet.
            </p>

            <section v-for="group in clinics" :key="group.unit.id" class="space-y-3">
                <div class="channel-bar rounded-md border border-line bg-panel px-4 py-2" :class="group.unit.bar_class">
                    <span class="readout text-sm font-semibold text-ink">{{ group.unit.code }}</span>
                    <span class="ml-2 text-sm text-body">{{ group.unit.name }}</span>
                    <span v-if="!group.unit.active" class="channel-tag ml-2">Unit retired</span>
                    <span v-else-if="!group.unit.clinic_owner" class="channel-tag ml-2">No longer owns clinics</span>
                </div>

                <!-- Phone: one card per clinic. -->
                <div class="space-y-3 lg:hidden">
                    <article v-for="clinic in group.clinics" :key="clinic.id"
                             class="rounded-md border border-line bg-panel p-4">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="text-sm font-semibold text-ink">{{ clinic.name }}</span>
                            <span class="channel-tag">{{ clinic.active ? 'Running' : 'Stopped' }}</span>
                        </div>
                        <p class="readout text-sm text-body">{{ clinic.weekday_label }} · {{ clinic.session_label }}</p>
                        <p v-if="clinic.location" class="text-sm text-body">{{ clinic.location }}</p>
                        <p v-if="clinic.note" class="text-xs text-muted">{{ clinic.note }}</p>
                        <p class="channel-tag mt-2">{{ clinic.mode_label }}</p>
                        <p v-if="ruleSummary(clinic)" class="text-xs text-muted">{{ ruleSummary(clinic) }}</p>
                        <p class="mt-2 text-xs text-muted">
                            Today: {{ clinic.resolved_today.length }}
                            <span v-for="row in clinic.resolved_today" :key="row.id" class="ml-1">
                                {{ row.full_name }}<span v-if="row.stale"> (off the roster)</span>
                            </span>
                        </p>
                        <div class="mt-3 flex flex-wrap gap-3">
                            <button type="button" class="text-xs font-semibold text-channel-ink" @click="startEdit(clinic)">Edit</button>
                            <button type="button" class="text-xs font-semibold text-channel-ink" @click="startAttendees(clinic)">Who attends</button>
                            <button type="button" class="text-xs font-semibold text-critical" @click="toggleActive(clinic)">
                                {{ clinic.active ? 'Stop' : 'Restart' }}
                            </button>
                        </div>
                        <p v-if="activeErrorId === clinic.id && activeForm.errors.active"
                           class="mt-2 text-xs text-critical" data-testid="clinic-active-error">
                            {{ activeForm.errors.active }}
                        </p>
                        <!--
                          Stop/Restart is one field and works here; the two multi-field forms are the
                          desktop table's, exactly as Units.vue degrades. Said out loud on the card
                          rather than left as a button that appears to do nothing.
                        -->
                        <p v-if="editingId === clinic.id || attendeesId === clinic.id" class="mt-2 text-xs text-muted">
                            The full form is on the desktop table — widen the window to reach it.
                        </p>
                    </article>
                </div>

                <!-- Desktop: a table. -->
                <div class="hidden overflow-x-auto rounded-md border border-line bg-panel lg:block">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-ground-deep">
                            <tr>
                                <th v-for="heading in headings" :key="heading" scope="col" class="channel-tag px-4 py-2">
                                    <span :class="heading === 'Actions' ? 'sr-only' : ''">{{ heading }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="clinic in group.clinics" :key="clinic.id">
                                <tr class="border-t border-line">
                                    <td class="readout px-4 py-2 text-body">{{ clinic.weekday_label }}</td>
                                    <td class="px-4 py-2 text-body">{{ clinic.session_label }}</td>
                                    <td class="px-4 py-2">
                                        <span class="font-semibold text-ink">{{ clinic.name }}</span>
                                        <span v-if="!clinic.active" class="channel-tag ml-2">Stopped</span>
                                        <p v-if="clinic.location" class="text-xs text-muted">{{ clinic.location }}</p>
                                        <p v-if="clinic.note" class="text-xs text-muted">{{ clinic.note }}</p>
                                    </td>
                                    <td class="px-4 py-2 text-body">
                                        {{ clinic.mode_label }}
                                        <p v-if="ruleSummary(clinic)" class="text-xs text-muted">{{ ruleSummary(clinic) }}</p>
                                    </td>
                                    <td class="readout px-4 py-2 text-body">
                                        {{ clinic.resolved_today.length }}
                                        <p v-for="row in clinic.resolved_today" :key="row.id" class="text-xs text-muted">
                                            {{ row.full_name }}<span v-if="row.stale"> (off the roster)</span>
                                        </p>
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        <button type="button" class="text-xs font-semibold text-channel-ink" @click="startEdit(clinic)">Edit</button>
                                        <button type="button" class="ml-3 text-xs font-semibold text-channel-ink" @click="startAttendees(clinic)">Who attends</button>
                                        <button type="button" class="ml-3 text-xs font-semibold text-critical" @click="toggleActive(clinic)">
                                            {{ clinic.active ? 'Stop' : 'Restart' }}
                                        </button>
                                        <p v-if="activeErrorId === clinic.id && activeForm.errors.active"
                                           class="mt-1 text-xs text-critical" data-testid="clinic-active-error">
                                            {{ activeForm.errors.active }}
                                        </p>
                                    </td>
                                </tr>

                                <tr v-if="editingId === clinic.id" class="border-t border-line bg-ground-deep">
                                    <td :colspan="columnCount" class="px-4 py-4">
                                        <form class="space-y-4" @submit.prevent="submitEdit(clinic)">
                                            <div class="grid gap-3 sm:grid-cols-3">
                                                <div>
                                                    <label class="channel-tag mb-1 block" :for="`edit-unit-${clinic.id}`">Unit</label>
                                                    <select :id="`edit-unit-${clinic.id}`" v-model.number="editForm.unit_id" :class="inputClass">
                                                        <option v-for="unit in units" :key="unit.id" :value="unit.id">
                                                            {{ unit.code }} — {{ unit.name }}
                                                        </option>
                                                    </select>
                                                    <p v-if="editForm.errors.unit_id" class="mt-1 text-xs text-critical">{{ editForm.errors.unit_id }}</p>
                                                </div>
                                                <div>
                                                    <label class="channel-tag mb-1 block" :for="`edit-day-${clinic.id}`">Day</label>
                                                    <select :id="`edit-day-${clinic.id}`" v-model.number="editForm.weekday" :class="inputClass">
                                                        <option v-for="day in weekdays" :key="day.iso" :value="day.iso">{{ day.label }}</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="channel-tag mb-1 block" :for="`edit-session-${clinic.id}`">Session</label>
                                                    <select :id="`edit-session-${clinic.id}`" v-model="editForm.session" :class="inputClass">
                                                        <option v-for="(label, key) in sessions" :key="key" :value="key">{{ label }}</option>
                                                    </select>
                                                </div>
                                                <div class="sm:col-span-3">
                                                    <label class="channel-tag mb-1 block" :for="`edit-name-${clinic.id}`">Name</label>
                                                    <input :id="`edit-name-${clinic.id}`" v-model="editForm.name" type="text" :class="inputClass" maxlength="120" />
                                                    <p v-if="editForm.errors.name" class="mt-1 text-xs text-critical">{{ editForm.errors.name }}</p>
                                                </div>
                                                <div>
                                                    <label class="channel-tag mb-1 block" :for="`edit-location-${clinic.id}`">Location</label>
                                                    <input :id="`edit-location-${clinic.id}`" v-model="editForm.location" type="text" :class="inputClass" maxlength="120" />
                                                </div>
                                                <div class="sm:col-span-2">
                                                    <label class="channel-tag mb-1 block" :for="`edit-note-${clinic.id}`">Note</label>
                                                    <input :id="`edit-note-${clinic.id}`" v-model="editForm.note" type="text" :class="inputClass" maxlength="500" />
                                                </div>
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

                                <tr v-if="attendeesId === clinic.id" class="border-t border-line bg-ground-deep">
                                    <td :colspan="columnCount" class="px-4 py-4">
                                        <form class="space-y-4" @submit.prevent="submitAttendees(clinic)">
                                            <div>
                                                <label class="channel-tag mb-1 block" :for="`mode-${clinic.id}`">Who attends</label>
                                                <select :id="`mode-${clinic.id}`" v-model="attendeesForm.mode" :class="inputClass">
                                                    <option v-for="(label, key) in modes" :key="key" :value="key">{{ label }}</option>
                                                </select>
                                                <p v-if="attendeesForm.errors.mode" class="mt-1 text-xs text-critical">{{ attendeesForm.errors.mode }}</p>
                                            </div>

                                            <fieldset v-if="attendeesForm.mode === MODE_LEVELS" class="flex flex-wrap gap-3">
                                                <legend class="channel-tag mb-1">Training levels</legend>
                                                <label v-for="level in levels" :key="level.id" class="flex items-center gap-2 text-sm text-body">
                                                    <input v-model="attendeesForm.level_ids" type="checkbox" :value="level.id" />
                                                    {{ level.code }} — {{ level.name }}
                                                </label>
                                            </fieldset>

                                            <fieldset v-if="attendeesForm.mode === MODE_NAMED" class="grid gap-2 sm:grid-cols-3">
                                                <legend class="channel-tag mb-1">People</legend>
                                                <label v-for="person in people" :key="person.id" class="flex items-center gap-2 text-sm text-body">
                                                    <input v-model="attendeesForm.person_ids" type="checkbox" :value="person.id" />
                                                    {{ person.full_name }}
                                                </label>
                                            </fieldset>

                                            <p v-if="clinic.unlisted.length" class="text-xs text-critical">
                                                No longer offered, and saving will drop them:
                                                <span v-for="row in clinic.unlisted" :key="`${row.kind}-${row.id}`" class="ml-1">{{ row.label }}</span>
                                            </p>

                                            <div class="flex items-center gap-3">
                                                <button type="submit" :disabled="attendeesForm.processing"
                                                        class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                                                    Save
                                                </button>
                                                <button type="button" class="text-sm font-semibold text-body" @click="cancelAttendees">Cancel</button>
                                                <span v-if="attendeesForm.recentlySuccessful" class="text-sm text-ok" role="status">Saved.</span>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
