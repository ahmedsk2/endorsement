<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Set up this department (Munawib ST-01/ST-02), at /admin/setup, cap:structure.manage.
 *
 * A PATH, NOT A PLACE. Every step here links to the screen that already owns it — this component
 * re-implements none of them, and there is no "next" button, no step number to be stuck on and no
 * progress to lose. Closing the tab costs nothing: the server derives this list from the data on
 * every request and stores no part of it, so reopening the page recomputes rather than resumes.
 *
 * TWO KINDS OF STEP, AND THE DIFFERENCE IS NOT DECORATIVE.
 *
 *  - REQUIRED steps are derivable and binary, so they carry a state and this screen shows it.
 *  - REVIEW steps — the department profile, the calendar, the holiday rules — can never be
 *    honestly ticked, because a value left on its default is indistinguishable from one somebody
 *    looked at and kept. The Hijri offset is the example the specification itself gives: it
 *    defaults to zero where this department needed minus one, and no query tells a reviewed zero
 *    from an unexamined one. So a review step renders its CURRENT VALUES inline — which is what
 *    makes reviewing possible without leaving the page — and offers no state control at all. A
 *    checkbox on one of these would be a claim the data cannot support.
 *
 * WHAT BLOCKS A STEP IS ADVISORY AND NEVER THE GATE. When a prerequisite is unmet the step says
 * so, and its link still works: the target screen refuses on its own terms, with its own message,
 * because a checklist that started authorizing would be a second authorization boundary and two
 * boundaries drift. Nothing here disables a link.
 *
 * THE STAGE 2 AND STAGE 3 ITEMS ARE STATED, NEVER LISTED AS STEPS. Duty slots, coverage templates
 * and duty-hour rules have no tables behind them yet, so they arrive with prose and a stage label,
 * no destination and no state. Rendering them as two permanently unticked boxes would invite an
 * administrator to click something that cannot exist yet.
 *
 * IT COMPUTES NO DATE AND NAMES NO DAY. Every value on this page — the calendar summary included —
 * arrives pre-formatted from the server, which is the only thing in this system allowed to convert
 * or name one.
 *
 * FREE TEXT IS INTERPOLATED, AND ONLY INTERPOLATED. A department name, a clinic count, a
 * server-built summary line: all plain text stored or assembled verbatim, so escaping is this
 * file's job and the double-brace form is how it is done. The raw-markup directive appears nowhere
 * below, and DepartmentSetup.test.js asserts that over this file's own source.
 *
 * MOBILE FIRST. Below `md` each step is a card, everything stacked in reading order; from `md` up
 * the state, the title and the link sit on one line. One element per step either way — a second
 * copy of the list hidden by CSS would be two places to keep a state badge honest.
 */
const props = defineProps({
    steps: { type: Array, default: () => [] },
    later: { type: Array, default: () => [] },
});

const KIND_REQUIRED = 'required';

const requiredSteps = computed(() => props.steps.filter((step) => step.kind === KIND_REQUIRED));

const doneCount = computed(() => requiredSteps.value.filter((step) => step.done).length);

const isRequired = (step) => step.kind === KIND_REQUIRED;

// The channel bar encodes ONE meaning per element: on a required step it carries the state, and on
// a review step there is no state to carry, so it stays the plain default.
const barClass = (step) => {
    if (!isRequired(step)) {
        return '';
    }

    return step.done ? 'channel-bar-ok' : 'channel-bar-caution';
};
</script>

<template>
    <AppLayout title="Set up this department">
        <div class="mx-auto max-w-3xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">Set up this department</h2>
                <p class="text-sm text-muted">
                    Everything an empty instance needs before it runs a handover. Each step opens
                    the screen that owns it — this page only reports what the data already says, so
                    there is nothing here to save and nothing to lose by leaving.
                </p>
            </div>

            <p class="rounded-md border border-line bg-ground-deep px-4 py-3 text-sm text-body"
               data-testid="setup-progress" role="status" aria-live="polite">
                <span class="readout font-semibold text-ink">{{ doneCount }} of {{ requiredSteps.length }}</span>
                required steps done. The rest are for review — they have sensible defaults, and a
                default nobody has looked at cannot be told apart from one somebody chose.
            </p>

            <ol class="space-y-3">
                <li v-for="step in steps" :key="step.key"
                    :data-testid="`step-${step.key}`"
                    :data-done="isRequired(step) ? String(step.done) : null"
                    class="channel-bar rounded-md border border-line bg-panel p-4"
                    :class="barClass(step)">
                    <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span v-if="isRequired(step)" class="channel-tag rounded-sm px-1.5 py-0.5"
                                      :class="step.done ? 'bg-ok-soft text-ok' : 'bg-caution-soft text-caution'">
                                    {{ step.done ? 'Done' : 'To do' }}
                                </span>
                                <span v-else class="channel-tag rounded-sm bg-channel-soft px-1.5 py-0.5 text-channel-ink">
                                    Review
                                </span>
                                <p class="text-sm font-semibold text-ink">{{ step.title }}</p>
                            </div>

                            <p class="mt-1 text-sm text-body">{{ step.summary }}</p>

                            <p v-if="step.blocked_by" class="mt-1 text-xs text-caution">{{ step.blocked_by }}</p>
                        </div>

                        <Link :href="step.url"
                              class="shrink-0 rounded-md border border-line px-3 py-1.5 text-sm font-medium text-channel-ink transition hover:bg-channel-soft">
                            {{ isRequired(step) ? 'Open' : 'Review' }}
                        </Link>
                    </div>
                </li>
            </ol>

            <section class="rounded-md border border-line bg-panel p-4">
                <h3 class="text-sm font-semibold text-ink">Arriving later</h3>
                <p class="mt-1 text-sm text-muted">
                    Listed in the specification, and not built yet. Nothing is missing from the
                    steps above because of them.
                </p>

                <ul class="mt-3 space-y-3">
                    <li v-for="item in later" :key="item.key" :data-testid="`later-${item.key}`"
                        class="rounded-md border border-line-soft bg-ground p-3">
                        <div class="flex items-baseline gap-2">
                            <span class="channel-tag rounded-sm bg-ground-deep px-1.5 py-0.5">{{ item.stage }}</span>
                            <p class="text-sm font-semibold text-ink">{{ item.title }}</p>
                        </div>
                        <p class="mt-1 text-sm text-body">{{ item.summary }}</p>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
