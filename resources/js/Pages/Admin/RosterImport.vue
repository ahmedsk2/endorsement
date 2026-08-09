<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Admin → Roster import (Munawib ST-04). P1c Decision E: CSV/TSV only, no spreadsheet package —
 * the screen states plainly what it accepts rather than silently rejecting an .xlsx as "invalid
 * file".
 *
 * THE DRY-RUN PREVIEW IS THE DELIVERABLE, NOT A NICETY (owner decision 3, 2026-08-09): commit
 * stays disabled until a preview exists for the file+mapping CURRENTLY selected — any change to
 * either clears it, so a stale preview can never be committed against changed inputs.
 *
 * The mapping <select> options come from the SERVER's own header list (an initial preview call
 * with an empty mapping, which returns `headers` alongside file-level errors it does not act on
 * yet) — this page never parses CSV itself, the same "the client does no date maths" discipline
 * extended to "the client does no CSV parsing either": a client-side header split that drifted
 * from `CsvRosterReader`'s own delimiter-sniffing would silently mismap a column with no visible
 * sign on screen.
 */
const props = defineProps({
    positions: { type: Array, default: () => [] },
    levels: { type: Array, default: () => [] },
});

const page = usePage();
const inputClass = 'w-full rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none';

const FIELDS = [
    { key: 'full_name', label: 'Full Name', required: true, guesses: ['fullname', 'name'] },
    { key: 'short_name', label: 'Short Name', required: false, guesses: ['shortname'] },
    { key: 'email', label: 'Email', required: false, guesses: ['email', 'emailaddress'] },
    { key: 'phone', label: 'Phone', required: false, guesses: ['phone', 'mobile', 'cell'] },
    { key: 'position', label: 'Position', required: true, guesses: ['position', 'role', 'jobrole'] },
    { key: 'level', label: 'Level', required: false, guesses: ['level', 'traininglevel'] },
    { key: 'joined_at', label: 'Joined', required: false, guesses: ['joined', 'joinedat', 'joindate', 'dateofjoining'] },
];

// Laravel's `required|array` rule treats an empty JS object `{}` as absent — every key starts
// present (empty string), so the header-discovery call's "mapping" is structurally valid even
// before the operator has chosen anything.
const emptyMapping = () => Object.fromEntries(FIELDS.map((f) => [f.key, '']));

const file = ref(null);
const headers = ref([]);
const mapping = ref(emptyMapping());
const busy = ref(false);
const uploadError = ref('');

// Every uploaded byte the operator has looked at is tied to this key; changing the FILE or the
// MAPPING invalidates it client-side, so a stale preview can never be committed against changed
// inputs — the same discipline Promotion.vue's own previewedKey/currentKey pair establishes.
const previewedKey = ref(null);
const currentKey = computed(() => `${file.value?.name ?? ''}|${file.value?.size ?? ''}|${file.value?.lastModified ?? ''}|${JSON.stringify(mapping.value)}`);
const previewStale = computed(() => previewedKey.value === null || previewedKey.value !== currentKey.value);

const preview = computed(() => (previewStale.value ? null : page.props.flash?.roster_preview));
const result = computed(() => page.props.flash?.roster_result);

const normalise = (s) => String(s).toLowerCase().trim().replace(/[^a-z0-9]/g, '');

const guessMapping = () => {
    const used = new Set();
    const guessed = {};

    for (const f of FIELDS) {
        const match = headers.value.find((h) => !used.has(h) && f.guesses.includes(normalise(h)));
        guessed[f.key] = match ?? '';
        if (match) used.add(match);
    }

    mapping.value = guessed;
};

const post = (url, extra, onSuccess) => {
    const data = new FormData();
    data.append('file', file.value);
    data.append('mapping', JSON.stringify(mapping.value));
    for (const [k, v] of Object.entries(extra ?? {})) {
        data.append(k, typeof v === 'string' ? v : JSON.stringify(v));
    }

    busy.value = true;
    router.post(url, data, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => onSuccess?.(),
        onFinish: () => { busy.value = false; },
    });
};

const onFileChosen = (e) => {
    uploadError.value = '';
    file.value = e.target.files[0] ?? null;
    headers.value = [];
    mapping.value = emptyMapping();
    previewedKey.value = null;

    if (!file.value) return;

    if (file.value.size > 4 * 1024 * 1024) {
        uploadError.value = 'That file is larger than the 4 MB upload limit — choose a smaller export.';
        file.value = null;
        return;
    }

    // Discover the real headers through the SAME preview endpoint — see this file's own
    // docblock for why this page never parses CSV client-side.
    post('/admin/roster-import/preview', {}, () => {
        headers.value = page.props.flash?.roster_preview?.headers ?? [];
        guessMapping();
    });
};

const runPreview = () => {
    post('/admin/roster-import/preview', {}, () => {
        previewedKey.value = currentKey.value;
    });
};

// Every no-email row starts UNCONFIRMED; the operator ticks the ones that are genuinely new.
const confirmations = ref({});
const toggleConfirm = (line) => {
    confirmations.value = { ...confirmations.value, [line]: !confirmations.value[line] };
};

const requiredMapped = computed(() => FIELDS.filter((f) => f.required).every((f) => mapping.value[f.key]));
const hasFileErrors = computed(() => (preview.value?.file_errors ?? []).length > 0);
const canCommit = computed(() => !previewStale.value && !hasFileErrors.value && !busy.value);

const commit = () => {
    if (!confirm('Commit this import? Rows marked "create" or "update" above will be written.')) return;

    // confirmations are keyed by row POSITION (0-based), matching RosterImport's own contract —
    // "line" on screen is position + 2, so translate back before sending.
    const byPosition = {};
    for (const [line, checked] of Object.entries(confirmations.value)) {
        if (checked) byPosition[Number(line) - 2] = true;
    }

    post('/admin/roster-import/commit', {
        confirmations: byPosition,
        digest: preview.value?.digest ?? '',
    }, () => {
        previewedKey.value = null;
        confirmations.value = {};
    });
};

const outcomeLabels = {
    create: 'Will be created',
    update: 'Will be updated',
    skip_no_email_unconfirmed: 'No email — confirm to create',
    skip_has_account: 'Matches an account holder — not applied',
    error: 'Error',
};
</script>

<template>
    <AppLayout title="Roster import">
        <div class="mx-auto max-w-5xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">Roster import</h2>
                <p class="text-sm text-muted">
                    CSV or tab-separated, UTF-8. From Excel: File → Save As → CSV UTF-8.
                    Up to 4 MB and 2000 rows. Nothing is written until you commit — preview
                    first.
                </p>
            </div>

            <section class="rounded-md border border-line bg-panel p-5">
                <label class="channel-tag mb-1 block" for="roster-file">Roster file</label>
                <input id="roster-file" type="file" accept=".csv,.txt,.tsv,text/csv"
                       data-testid="roster-file" class="w-full text-sm" @change="onFileChosen" />
                <p v-if="uploadError" class="mt-2 text-xs text-critical" role="alert">{{ uploadError }}</p>
                <p v-if="preview?.file_errors?.length && !uploadError" class="mt-2 text-xs text-critical" role="alert">
                    <span v-for="(msg, i) in preview.file_errors" :key="i" class="block">{{ msg }}</span>
                </p>
                <p class="mt-3 text-xs text-muted">
                    Valid positions: {{ positions.map((p) => p.name).join(', ') }}.
                    Valid levels: {{ levels.map((l) => l.code).join(', ') }} — a blank Level
                    cell leaves that field alone.
                </p>
            </section>

            <section v-if="headers.length" class="rounded-md border border-line bg-panel p-5">
                <p class="channel-tag mb-3">Map columns</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div v-for="f in FIELDS" :key="f.key">
                        <label class="mb-1 block text-sm text-body" :for="`map-${f.key}`">
                            {{ f.label }}<span v-if="f.required" class="text-critical"> *</span>
                        </label>
                        <select :id="`map-${f.key}`" v-model="mapping[f.key]" :class="inputClass">
                            <option value="">— not mapped —</option>
                            <option v-for="h in headers" :key="h" :value="h">{{ h }}</option>
                        </select>
                    </div>
                </div>
                <div class="mt-4">
                    <button type="button" data-testid="run-preview" :disabled="!requiredMapped || busy"
                            class="min-h-11 rounded-md border border-line bg-ground-deep px-4 py-2 text-sm font-semibold text-ink disabled:opacity-60"
                            @click="runPreview">
                        Preview
                    </button>
                </div>
            </section>

            <section v-if="!previewStale && preview" class="space-y-4 rounded-md border border-line bg-panel p-5">
                <p class="channel-tag">
                    {{ preview.summary.create }} to create ·
                    {{ preview.summary.update }} to update ·
                    {{ preview.summary.skip }} skipped ·
                    {{ preview.summary.error }} error<span v-if="preview.summary.error !== 1">s</span>
                </p>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-ground-deep text-left">
                                <th class="p-2">Line</th>
                                <th class="p-2">Name</th>
                                <th class="p-2">Outcome</th>
                                <th class="p-2">Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in preview.rows" :key="row.line" class="border-t border-line-soft">
                                <td class="p-2">{{ row.line }}</td>
                                <td class="p-2 readout">{{ row.full_name ?? '—' }}</td>
                                <td class="p-2">
                                    <span class="channel-tag">{{ outcomeLabels[row.outcome] ?? row.outcome }}</span>
                                </td>
                                <td class="p-2 text-xs text-muted">
                                    <template v-if="row.outcome === 'skip_no_email_unconfirmed'">
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" :checked="!!confirmations[row.line]"
                                                   @change="toggleConfirm(row.line)" />
                                            This is a new person
                                        </label>
                                    </template>
                                    <template v-else-if="row.outcome === 'update' && Object.keys(row.changes).length">
                                        <span v-for="(c, field) in row.changes" :key="field" class="block">
                                            {{ field }}: {{ c.from ?? '—' }} → {{ c.to ?? '—' }}
                                        </span>
                                    </template>
                                    <template v-else-if="row.errors?.length">
                                        <span v-for="(e, i) in row.errors" :key="i" class="block text-critical">{{ e }}</span>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center gap-3 border-t border-line-soft pt-4">
                    <button type="button" data-testid="commit-import" :disabled="!canCommit"
                            class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                            @click="commit">
                        Commit import
                    </button>
                    <span v-if="hasFileErrors" class="text-xs text-critical">
                        Fix the file-level problems above before committing.
                    </span>
                </div>
            </section>

            <section v-if="result" class="rounded-md border border-line bg-panel p-4">
                <p class="channel-tag mb-2">
                    Last import — {{ result.summary.created }} created,
                    {{ result.summary.updated }} updated,
                    {{ result.summary.skipped }} skipped
                </p>
            </section>
        </div>
    </AppLayout>
</template>
