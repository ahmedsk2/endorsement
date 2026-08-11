<script setup>
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Admin → Somewhere to practise (Munawib ST-05), at /admin/structure/demo, cap:structure.manage.
 *
 * THIS SCREEN MAY BE OPENED ON THE LIVE INSTANCE, so it says out loud what it is before it offers
 * either control: a whole small department of obviously fictional records, every one of them
 * labelled, none of them able to sign in, all of them removable in one act. The two facts an
 * operator needs before pressing anything — what would be created here specifically, and what
 * would be removed — are on the page, not behind a button.
 *
 * THE PAGE IS THE PREVIEW. Neither action takes any input beyond its confirmation, so the state
 * each one is pinned to is computed when this page is rendered and posted straight back. That is
 * why both forms carry an opaque `pin` they never interpret: it is the server's record of the
 * department this page was describing. If it moved, the confirm is refused and this page is read
 * again — nothing is queued and nothing is retried.
 *
 * REMOVAL IS THE IRREVERSIBLE HALF AND IS SHAPED LIKE ONE. Nothing here soft-deletes; there is no
 * undo anywhere in the product. So the word is typed rather than a button clicked (the academic
 * year deletion on the Periods screen does the same, for the same reason), and when real records
 * have attached themselves to the demo the confirm is not offered at all — the table of what is
 * holding it is shown instead, because "refused" with no reason is a dead end.
 *
 * FREE TEXT IS INTERPOLATED, AND ONLY INTERPOLATED. Every sentence here is server-built prose and
 * every table name is a plain string; escaping is this file's job and the double-brace form is how
 * it is done. The raw-markup directive appears nowhere below, and DemoDepartment.test.js asserts
 * that over this file's own source — which is why it is not spelled out even here.
 *
 * MOBILE FIRST, ONE ELEMENT PER FACT. The two lists are short enough to read as lists at every
 * width, so there is no second copy of anything hidden by CSS.
 */
const props = defineProps({
    plan: { type: Object, default: () => ({ refusals: [], skipped: [], pin: '' }) },
    demo: { type: Object, default: null },
    confirm_word: { type: String, default: '' },
    unit_code: { type: String, default: '' },
    label_prefix: { type: String, default: '' },
    address_domain: { type: String, default: '' },
});

const page = usePage();

const inputClass = 'w-full rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none';

// The one-shot result of the last creation: how many rows, and what a demo department did NOT do
// in this department. A skip nobody is told about is how an operator concludes a button is broken.
const result = computed(() => page.props.flash?.demo_result ?? null);

const canCreate = computed(() => !props.demo && props.plan.refusals.length === 0);
const blocked = computed(() => props.demo?.blocked ?? []);
const swept = computed(() => props.demo?.swept ?? []);

const createForm = useForm({ pin: '' });
const removeForm = useForm({ pin: '', confirm_demo: '' });

const typed = ref('');

const create = () => {
    createForm.pin = props.plan.pin;
    createForm.post('/admin/structure/demo', { preserveScroll: true });
};

const remove = () => {
    removeForm.pin = props.demo?.pin ?? '';
    removeForm.confirm_demo = typed.value;
    removeForm.delete('/admin/structure/demo', {
        preserveScroll: true,
        onSuccess: () => { typed.value = ''; },
    });
};
</script>

<template>
    <AppLayout title="Somewhere to practise">
        <div class="mx-auto max-w-3xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">Somewhere to practise</h2>
                <p class="text-sm text-muted">
                    A small, obviously fictional department to train people on. It can be created
                    here and removed here, and removing it leaves nothing behind.
                </p>
            </div>

            <section class="channel-bar rounded-md border border-line bg-panel p-5" data-testid="what-it-is">
                <h3 class="text-sm font-semibold text-ink">What it is</h3>
                <ul class="mt-2 space-y-1 text-sm text-body">
                    <li>
                        One extra unit, code <span class="readout">{{ unit_code }}</span>, with a few
                        staff records, a rotation, a week of leave and a weekly clinic — enough to
                        show every screen doing its job.
                    </li>
                    <li>
                        Every name it creates begins <span class="readout">{{ label_prefix }}</span>
                        and every address it uses is on <span class="readout">{{ address_domain }}</span>,
                        a domain that cannot receive mail anywhere. The label survives into a printed
                        sheet and a spreadsheet export, where nothing looks anything up.
                    </li>
                    <li>
                        <strong class="font-semibold text-ink">It creates no account.</strong>
                        Nobody it names can sign in, so this adds no way into the system.
                    </li>
                    <li>
                        Every row it writes is recorded, which is what lets removal prove it removed
                        all of them rather than assume it.
                    </li>
                </ul>
            </section>

            <!-- Creating ------------------------------------------------------------------->
            <section v-if="!demo" class="channel-bar rounded-md border border-line bg-panel p-5" data-testid="create-panel">
                <h3 class="text-sm font-semibold text-ink">Create it</h3>

                <ul v-if="plan.refusals.length" class="mt-2 space-y-1" data-testid="create-refusals">
                    <li v-for="(refusal, index) in plan.refusals" :key="index" class="text-sm text-caution">
                        {{ refusal }}
                    </li>
                </ul>

                <div v-else>
                    <p class="mt-2 text-sm text-body">
                        Here, in this department, it will be built from what is already configured.
                    </p>

                    <ul v-if="plan.skipped.length" class="mt-2 space-y-1" data-testid="create-skipped">
                        <li v-for="(note, index) in plan.skipped" :key="index" class="text-sm text-muted">
                            {{ note }}
                        </li>
                    </ul>
                </div>

                <p v-if="createForm.errors.create_pin" class="mt-2 text-xs text-critical" data-testid="create-error">
                    {{ createForm.errors.create_pin }}
                </p>

                <div class="mt-4 flex items-center gap-3">
                    <button type="button" data-testid="create-demo"
                            :disabled="!canCreate || createForm.processing"
                            class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                            @click="create">
                        Create the demo department
                    </button>
                </div>
            </section>

            <div v-if="result" class="rounded-md border border-line bg-ground-deep px-4 py-3 text-sm text-body"
                 data-testid="create-result" role="status" aria-live="polite">
                <p><span class="readout font-semibold text-ink">{{ result.rows }}</span> rows created.</p>
                <ul v-if="result.skipped.length" class="mt-1 space-y-1">
                    <li v-for="(note, index) in result.skipped" :key="index" class="text-muted">{{ note }}</li>
                </ul>
            </div>

            <!-- Removing ------------------------------------------------------------------->
            <section v-if="demo" class="channel-bar rounded-md border border-line bg-panel p-5" data-testid="remove-panel">
                <h3 class="text-sm font-semibold text-ink">Remove it</h3>

                <p class="mt-1 text-sm text-body">
                    A demo department is here now:
                    <span class="readout font-semibold text-ink">{{ demo.total }}</span> records.
                </p>

                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-left text-sm" data-testid="remove-counts">
                        <caption class="sr-only">What would be removed</caption>
                        <thead>
                            <tr class="border-b border-line">
                                <th scope="col" class="channel-tag px-3 py-2">Where</th>
                                <th scope="col" class="channel-tag px-3 py-2">Records</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in demo.counts" :key="row.table" class="border-t border-line">
                                <td class="readout px-3 py-2 text-body">{{ row.table }}</td>
                                <td class="readout px-3 py-2 text-body">{{ row.count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Rows removal deletes that the demo never created. Said BEFORE the word is
                     typed: a cleanup button reaching outside its own ledger has to be legible. -->
                <div v-if="swept.length" class="mt-3 rounded-md border border-line p-3" data-testid="remove-swept">
                    <p class="text-sm text-body">
                        Removing also deletes these, which the demo did not create but which name
                        nobody else — an invitation addressed to a demo person can reach no real
                        inbox and would otherwise hold the demo here for good.
                    </p>
                    <ul class="mt-1 space-y-1">
                        <li v-for="row in swept" :key="row.table" class="readout text-sm text-body">
                            {{ row.count }} in {{ row.table }}
                        </li>
                    </ul>
                </div>

                <!-- Refused, and by what, and what to do about each one. Never merely "refused",
                     and never one remedy for tables whose remedies differ. -->
                <div v-if="blocked.length" class="mt-4 rounded-md border border-caution p-3" data-testid="remove-blocked">
                    <p class="text-sm font-semibold text-caution">This cannot be removed yet.</p>
                    <p class="mt-1 text-sm text-body">
                        Real records now point at it, so removing the demo would take them with it.
                        Deal with these first and this page will offer the removal again.
                    </p>
                    <table class="mt-2 min-w-full text-left text-sm">
                        <caption class="sr-only">What is holding the removal</caption>
                        <thead>
                            <tr class="border-b border-line">
                                <th scope="col" class="channel-tag px-3 py-2">Where</th>
                                <th scope="col" class="channel-tag px-3 py-2">Records</th>
                                <th scope="col" class="channel-tag px-3 py-2">What clears it</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in blocked" :key="row.table" class="border-t border-line">
                                <td class="readout px-3 py-2 text-body">{{ row.table }}</td>
                                <td class="readout px-3 py-2 text-body">{{ row.count }}</td>
                                <td class="px-3 py-2 text-body">{{ row.remedy || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="mt-4">
                    <p class="text-sm text-body">
                        Nothing is in the way. This is permanent — there is no undo — so type
                        <span class="readout font-semibold text-ink">{{ confirm_word }}</span> to confirm.
                    </p>

                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <label class="sr-only" for="confirm-demo">Type {{ confirm_word }} to confirm</label>
                        <input id="confirm-demo" v-model="typed" type="text" :class="inputClass" class="max-w-xs"
                               data-testid="confirm-demo" autocomplete="off" />
                        <button type="button" data-testid="remove-demo" :disabled="removeForm.processing"
                                class="min-h-11 rounded-md border border-critical px-3 py-1.5 text-sm font-semibold text-critical disabled:opacity-60"
                                @click="remove">
                            Remove the demo department
                        </button>
                    </div>
                </div>

                <p v-if="removeForm.errors.confirm_demo" class="mt-2 text-xs text-critical" data-testid="remove-error">
                    {{ removeForm.errors.confirm_demo }}
                </p>
                <p v-if="removeForm.errors.remove_pin" class="mt-2 text-xs text-critical" data-testid="remove-pin-error">
                    {{ removeForm.errors.remove_pin }}
                </p>
            </section>
        </div>
    </AppLayout>
</template>
