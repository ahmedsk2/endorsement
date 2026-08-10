<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Admin → Rota import (Munawib MR-06, P1d-2 Decision H, Task 12). The other end of Task 10's
 * export: an administrator plans a year in a spreadsheet, or fixes forty cells at once, and reads
 * it back in.
 *
 * PREVIEW, THEN COMMIT — AND THE PREVIEW IS THE DELIVERABLE. Nothing on this screen writes except
 * one button, and that button is unreachable until the SERVER has answered for the exact file
 * currently chosen. The preview route writes nothing at all: no transaction, no row, no audit.
 *
 * THE DESTRUCTIVE CASE IS LEGIBLE, NOT COUNTED. A cell the file would REPLACE lists the spans it
 * would discard — unit by unit, dated — because the whole reason a confirm step exists is that an
 * operator about to overwrite deliberate split work has to see the work. A count standing in for
 * it would defeat the step while looking like it worked.
 *
 * THE UNIT OF OUTCOME IS THE (person, period) CELL, NOT THE LINE (finding 12), so every row here
 * carries the line numbers of the file rows that produced it — two lines describing two halves of
 * one split period are ONE outcome, and the operator still has to be able to find them in their
 * own file.
 *
 * THE FILE'S OWN CELLS ARE UNTRUSTED TEXT. Every value from the upload — handles, names, unit
 * codes, the server's sentences quoting them — is rendered through interpolation, never as markup.
 * There is no `v-html` on this screen and there must not be one: an imported file is exactly the
 * input somebody would use to put a script tag on an administrator's page.
 *
 * THIS COMPONENT DERIVES NO ROTA FIGURE AND CONVERTS NO DATE. Outcomes, reasons, current and
 * proposed span sets, the snapped leave bounds and the five counts all arrive computed
 * (`RotaImport::preview()`); dates are `Y-m-d` strings the server formatted through
 * `App\Support\Calendar`, printed and parsed by nobody (ST-06, ten needles and no allow-list).
 *
 * THE ANALYSIS IS ONE-SHOT AND MATCHED, TWO WAYS. It arrives on `flash.rota_import_preview`, so
 * any navigation clears it; it is discarded client-side the moment the file or the kind changes;
 * and it is rendered only when the `kind` the SERVER echoes is the one currently selected — a
 * client key alone cannot close that last case, because an analysis flashed by the other radio
 * button is not this file's analysis whatever the client believes about it.
 */
const props = defineProps({
    academic_years: { type: Array, default: () => [] },
    max_kilobytes: { type: Number, default: 4096 },
});

const page = usePage();

/**
 * The two files, and the operator says which. Never sniffed from the headers: they share
 * `short_name`, `full_name`, `starts_on` and `ends_on`, so a guess reads a leave file as a rota
 * missing four columns — or writes leave from a rota.
 */
const KINDS = [
    { value: 'assignments', label: 'Rota (assignments)', hint: 'One row per span. Two rows for a split block.' },
    { value: 'vacations', label: 'Vacations (leave)', hint: 'One row per booking. A week booking is snapped to the department week.' },
];

/** The badge on each row. The REASON under it is the server's own sentence, rendered verbatim. */
const OUTCOME_LABELS = {
    create: 'Will be added',
    replace: 'Will be replaced',
    unchanged: 'No change',
    skip_unknown_person: 'Skipped — person',
    skip_unknown_unit: 'Skipped — unit',
    skip_unknown_period: 'Skipped — block',
    skip_duplicate: 'Skipped — already booked',
    error: 'Error',
};

const kind = ref('assignments');
const file = ref(null);
const busy = ref(false);
const uploadError = ref('');
const staleNotice = ref('');
const formErrors = ref({});

// Everything the operator has looked at is tied to this key. Changing the FILE or the KIND
// invalidates it client-side, so a stale analysis can never be committed against changed inputs —
// the same discipline `RosterImport.vue` and `Promotion.vue` establish.
const previewedKey = ref(null);
const currentKey = computed(
    () => `${file.value?.name ?? ''}|${file.value?.size ?? ''}|${file.value?.lastModified ?? ''}|${kind.value}`,
);
const previewStale = computed(() => previewedKey.value === null || previewedKey.value !== currentKey.value);

const preview = computed(() => {
    if (previewStale.value) return null;

    const analysis = page.props.flash?.rota_import_preview ?? null;
    if (!analysis) return null;

    // The server echoes which file it read. An analysis naming the other kind is a previous
    // answer, and is never drawn under this one's heading.
    if (analysis.kind !== kind.value) return null;

    return analysis;
});

/** What the last import actually did, in the server's own counts. Survives the preview clearing. */
const result = computed(() => page.props.flash?.rota_import_result ?? null);

const isAssignments = computed(() => preview.value?.kind === 'assignments');
const fileErrors = computed(() => preview.value?.file_errors ?? []);
const canCommit = computed(() => preview.value !== null && fileErrors.value.length === 0 && !busy.value);

const maxMegabytes = computed(() => Math.round(props.max_kilobytes / 1024));
const exportHref = computed(
    () => `/admin/rota${props.academic_years.length ? `?year=${encodeURIComponent(props.academic_years[props.academic_years.length - 1])}` : ''}`,
);

const post = (url, extra, handlers) => {
    const data = new FormData();
    data.append('kind', kind.value);
    data.append('file', file.value);
    for (const [k, v] of Object.entries(extra ?? {})) data.append(k, v);

    busy.value = true;
    formErrors.value = {};

    router.post(url, data, {
        forceFormData: true,
        preserveScroll: true,
        // Stated rather than inherited: the operator's chosen file, and the key that says it has
        // been previewed, must survive the round trip or the commit posts nothing. NOT load-bearing
        // in Inertia 3 as it stands — the e2e journey was measured passing with this line removed,
        // because a redirect BACK to the same component reuses the instance — which is exactly why
        // it is written down. `preserveState` defaults to false for POST, so the flow works today
        // on behaviour the documented default does not promise. Do not cite this screen as proof
        // that the flag is unnecessary elsewhere; cite the measurement, and re-measure.
        preserveState: true,
        onSuccess: () => handlers?.onSuccess?.(),
        onError: (errors) => handlers?.onError?.(errors),
        onFinish: () => { busy.value = false; },
    });
};

/** A different file, or a different kind, is a different question. The previous answer is dropped. */
const invalidate = () => {
    previewedKey.value = null;
    staleNotice.value = '';
    formErrors.value = {};
};

const onFileChosen = (event) => {
    uploadError.value = '';
    invalidate();
    file.value = event.target.files[0] ?? null;

    if (file.value && file.value.size > props.max_kilobytes * 1024) {
        uploadError.value = `That file is larger than the ${maxMegabytes.value} MB upload limit — choose a smaller export.`;
        file.value = null;
    }
};

const onKindChosen = (value) => {
    kind.value = value;
    invalidate();
};

const runPreview = () => {
    if (!file.value) return;

    const key = currentKey.value;

    post('/admin/rota/import/preview', {}, {
        onSuccess: () => {
            // Only once the SERVER has answered for this exact file does anything become
            // committable — the analysis on screen is never a client-side estimate.
            previewedKey.value = key;
        },
        onError: (errors) => { formErrors.value = errors; },
    });
};

const commit = () => {
    const analysis = preview.value;
    if (!analysis) return;

    post('/admin/rota/import/commit', { digest: analysis.digest ?? '' }, {
        onSuccess: () => {
            // The import happened, so the analysis that described it no longer describes the rota.
            // Dropped rather than left on screen behind a button that would now be refused.
            previewedKey.value = null;
            staleNotice.value = '';
        },
        onError: (errors) => {
            if (errors.file) {
                // THE FILE CHANGED UNDER THE OPERATOR — `StaleImportFileException`, a 422 on
                // `file`. Never retried: the analysis they approved describes bytes that no longer
                // exist, and re-sending with a fresh digest would apply a set they never saw. So:
                // say it in the server's own words, DROP the analysis so nothing on screen looks
                // committable, and re-run the PREVIEW, which writes nothing.
                staleNotice.value = Array.isArray(errors.file) ? errors.file.join(' ') : errors.file;
                previewedKey.value = null;
                runPreview();

                return;
            }

            formErrors.value = errors;
        },
    });
};
</script>

<template>
    <AppLayout title="Rota import">
        <div class="mx-auto max-w-6xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">Rota import</h2>
                <p class="text-sm text-muted">
                    CSV or tab-separated, UTF-8, up to {{ maxMegabytes }} MB and 2000 rows &mdash; the
                    files Master Rota exports, edited and read back. Nothing is written until you
                    import: preview first, and check what it would replace.
                    <a :href="exportHref" class="font-semibold underline" data-testid="import-export-link">
                        Export a year first
                    </a>
                </p>
            </div>

            <section class="space-y-4 rounded-md border border-line bg-panel p-5">
                <fieldset>
                    <legend class="channel-tag mb-2">Which file is this?</legend>
                    <div class="space-y-2">
                        <label v-for="option in KINDS" :key="option.value" class="flex items-start gap-2 text-sm text-body">
                            <input type="radio" name="rota-import-kind" :value="option.value"
                                   :checked="kind === option.value"
                                   :data-testid="`import-kind-${option.value}`"
                                   @change="onKindChosen(option.value)" />
                            <span>
                                <span class="font-semibold text-ink">{{ option.label }}</span>
                                <span class="block text-xs text-muted">{{ option.hint }}</span>
                            </span>
                        </label>
                    </div>
                </fieldset>

                <div>
                    <label class="channel-tag mb-1 block" for="rota-import-file">Rota file</label>
                    <input id="rota-import-file" type="file" accept=".csv,.txt,.tsv,text/csv"
                           data-testid="import-file" class="w-full text-sm" @change="onFileChosen" />
                    <p v-if="uploadError" class="mt-2 text-xs text-critical" role="alert">{{ uploadError }}</p>
                    <p v-if="formErrors.file" class="mt-2 text-xs text-critical" role="alert" data-testid="import-file-error">
                        {{ formErrors.file }}
                    </p>
                    <p v-if="formErrors.kind" class="mt-2 text-xs text-critical" role="alert">{{ formErrors.kind }}</p>
                    <p v-if="formErrors.digest" class="mt-2 text-xs text-critical" role="alert">{{ formErrors.digest }}</p>
                </div>

                <button type="button" data-testid="import-preview" :disabled="!file || busy"
                        class="min-h-11 rounded-md border border-line bg-ground-deep px-4 py-2 text-sm font-semibold text-ink disabled:opacity-60"
                        @click="runPreview">
                    Preview this file
                </button>
            </section>

            <!-- The file moved under the operator. Their analysis is gone and a fresh one is on its way. -->
            <p v-if="staleNotice" role="alert" data-testid="import-stale-notice"
               class="rounded-md border border-line bg-critical-soft p-3 text-sm text-critical">
                {{ staleNotice }}
            </p>

            <section v-if="fileErrors.length" class="rounded-md border border-line bg-panel p-5"
                     data-testid="import-file-errors" role="alert">
                <p class="channel-tag mb-2 text-critical">This file cannot be imported</p>
                <p v-for="(message, index) in fileErrors" :key="index" class="text-sm text-critical">
                    {{ message }}
                </p>
            </section>

            <section v-if="preview" class="space-y-4 rounded-md border border-line bg-panel p-5"
                     data-testid="import-preview-section">
                <!-- The SERVER's counts. This component computes none of them. -->
                <p class="channel-tag" aria-live="polite" data-testid="import-summary">
                    <span class="readout">{{ preview.summary.create }}</span> to add &middot;
                    <span class="readout">{{ preview.summary.replace }}</span> to replace &middot;
                    <span class="readout">{{ preview.summary.unchanged }}</span> unchanged &middot;
                    <span class="readout">{{ preview.summary.skipped }}</span> skipped &middot;
                    <span class="readout">{{ preview.summary.error }}</span>
                    error<span v-if="preview.summary.error !== 1">s</span>
                </p>

                <!--
                  The destructive case, named before the table shows it cell by cell. What each of
                  those cells holds today is listed against it, dated, in the "Now" column.
                -->
                <div v-if="preview.summary.replace > 0" class="rounded-md border border-line bg-caution-soft p-3"
                     data-testid="import-replace-warning">
                    <p class="text-sm text-ink">
                        <span class="readout">{{ preview.summary.replace }}</span> cell(s) below already
                        hold something else. Importing this file discards what is listed under
                        &ldquo;Now&rdquo; and writes what is listed under &ldquo;After&rdquo;.
                    </p>
                </div>

                <!-- ASSIGNMENTS: one row per (person, period) cell, with its contributing lines. -->
                <div v-if="isAssignments" class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-ground-deep">
                            <tr>
                                <th scope="col" class="channel-tag px-2 py-1">Line(s)</th>
                                <th scope="col" class="channel-tag px-2 py-1">Person</th>
                                <th scope="col" class="channel-tag px-2 py-1">Block</th>
                                <th scope="col" class="channel-tag px-2 py-1">Outcome</th>
                                <th scope="col" class="channel-tag px-2 py-1">Now</th>
                                <th scope="col" class="channel-tag px-2 py-1">After</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in preview.outcomes" :key="index"
                                :data-testid="`import-row-${index}`" class="border-t border-line-soft align-top">
                                <td class="px-2 py-1 readout" data-testid="import-lines">{{ row.lines.join(', ') }}</td>
                                <td class="px-2 py-1 text-body">
                                    <span class="readout">{{ row.short_name || '—' }}</span>
                                    <span class="block text-xs text-muted">{{ row.full_name || '—' }}</span>
                                </td>
                                <td class="px-2 py-1 text-body">
                                    {{ row.period_label || '—' }}
                                    <span class="block text-xs text-muted">
                                        {{ row.academic_year }} &middot; #{{ row.period_position }}
                                    </span>
                                </td>
                                <td class="px-2 py-1">
                                    <span class="channel-tag" data-testid="import-outcome">
                                        {{ OUTCOME_LABELS[row.outcome] ?? row.outcome }}
                                    </span>
                                    <p v-if="row.reason" class="mt-1 max-w-xs text-xs text-muted" data-testid="import-reason">
                                        {{ row.reason }}
                                    </p>
                                    <p v-for="(message, i) in row.errors" :key="i"
                                       class="mt-1 max-w-xs text-xs text-critical" data-testid="import-error">
                                        {{ message }}
                                    </p>
                                </td>
                                <!--
                                  WHAT WOULD BE DISCARDED, span by span and dated — never a count.
                                  This column is the entire reason the preview step exists.
                                -->
                                <td class="px-2 py-1" data-testid="import-current">
                                    <ul v-if="row.current && row.current.length" class="space-y-0.5">
                                        <li v-for="(span, i) in row.current" :key="i" class="channel-tag"
                                            :class="row.outcome === 'replace' ? 'text-critical' : ''">
                                            {{ span.unit_code || '—' }}
                                            <span class="readout">{{ span.starts_on }}</span>&ndash;<span class="readout">{{ span.ends_on }}</span>
                                        </li>
                                    </ul>
                                    <p v-else class="text-xs text-muted">Empty</p>
                                </td>
                                <td class="px-2 py-1" data-testid="import-proposed">
                                    <ul v-if="row.spans && row.spans.length" class="space-y-0.5">
                                        <li v-for="(span, i) in row.spans" :key="i" class="channel-tag">
                                            {{ span.unit_code || '—' }}
                                            <span class="readout">{{ span.starts_on }}</span>&ndash;<span class="readout">{{ span.ends_on }}</span>
                                        </li>
                                    </ul>
                                    <p v-else class="text-xs text-muted">Nothing would be written</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- LEAVE: one row per booking, with the snapped bounds the server would write. -->
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-ground-deep">
                            <tr>
                                <th scope="col" class="channel-tag px-2 py-1">Line</th>
                                <th scope="col" class="channel-tag px-2 py-1">Person</th>
                                <th scope="col" class="channel-tag px-2 py-1">In the file</th>
                                <th scope="col" class="channel-tag px-2 py-1">Outcome</th>
                                <th scope="col" class="channel-tag px-2 py-1">Would be booked</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in preview.outcomes" :key="index"
                                :data-testid="`import-row-${index}`" class="border-t border-line-soft align-top">
                                <td class="px-2 py-1 readout" data-testid="import-lines">{{ row.lines.join(', ') }}</td>
                                <td class="px-2 py-1 text-body">
                                    <span class="readout">{{ row.short_name || '—' }}</span>
                                    <span class="block text-xs text-muted">{{ row.full_name || '—' }}</span>
                                </td>
                                <td class="px-2 py-1 text-body">
                                    <span class="readout">{{ row.starts_on }}</span>&ndash;<span class="readout">{{ row.ends_on }}</span>
                                    <span class="block text-xs text-muted">{{ row.granularity }}</span>
                                </td>
                                <td class="px-2 py-1">
                                    <span class="channel-tag" data-testid="import-outcome">
                                        {{ OUTCOME_LABELS[row.outcome] ?? row.outcome }}
                                    </span>
                                    <p v-if="row.reason" class="mt-1 max-w-xs text-xs text-muted" data-testid="import-reason">
                                        {{ row.reason }}
                                    </p>
                                    <p v-for="(message, i) in row.errors" :key="i"
                                       class="mt-1 max-w-xs text-xs text-critical" data-testid="import-error">
                                        {{ message }}
                                    </p>
                                </td>
                                <td class="px-2 py-1" data-testid="import-proposed">
                                    <template v-if="row.snapped_starts_on">
                                        <span class="readout">{{ row.snapped_starts_on }}</span>&ndash;<span class="readout">{{ row.snapped_ends_on }}</span>
                                        <!-- The adjustment, said out loud: an operator must see that
                                             their week got wider BEFORE they commit it. -->
                                        <span v-if="row.snapped" class="block text-xs text-caution" data-testid="import-snapped">
                                            Widened to the department&rsquo;s week
                                        </span>
                                    </template>
                                    <p v-else class="text-xs text-muted">Nothing would be written</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center gap-3 border-t border-line-soft pt-4">
                    <button type="button" data-testid="import-commit" :disabled="!canCommit"
                            class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                            @click="commit">
                        Import this file
                    </button>
                    <button type="button" data-testid="import-preview-again" :disabled="busy"
                            class="min-h-11 rounded-md border border-line px-3 py-2 text-sm font-semibold text-body disabled:opacity-60"
                            @click="runPreview">
                        Preview again
                    </button>
                    <span v-if="fileErrors.length" class="text-xs text-critical">
                        Fix the problems above before importing.
                    </span>
                </div>
            </section>

            <section v-if="result" class="rounded-md border border-line bg-panel p-4" data-testid="import-result">
                <p class="channel-tag">
                    Last import &mdash; <span class="readout">{{ result.applied }}</span> cell(s) written:
                    <span class="readout">{{ result.summary.create }}</span> added,
                    <span class="readout">{{ result.summary.replace }}</span> replaced,
                    <span class="readout">{{ result.summary.unchanged }}</span> already matched,
                    <span class="readout">{{ result.summary.skipped }}</span> skipped
                </p>
            </section>
        </div>
    </AppLayout>
</template>
