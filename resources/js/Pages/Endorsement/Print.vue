<script setup>
import { computed, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

/**
 * The printable A4 endorsement sheet — ONE parameterised template for all four units
 * (spec ruling 4: the re-platform style, extended per unit). Per-unit variation comes
 * entirely from `unit.profile` (App\Support\UnitProfile):
 *
 *   PICU        Bed | MRN | Name                | Diagnosis List | Clinical Condition | Plan Of Care | New events
 *   NICU/SCBU   Bed | MRN | Name | DOB          | Diagnosis List | Clinical Condition | Plan Of Care | To be followed
 *   WARD        Room | Unit | MRN | Name | Age  | Diagnosis List | Clinical Condition | Management   | To be followed
 *
 * It has its OWN minimal layout — no app chrome — so the browser print output is a clean
 * A4 handover. The four rich-text fields are server-sanitized on write, so `v-html` is safe.
 * PRINT FIDELITY IS A CONTRACT: once approved, do not restyle (docs/DESIGN-TOKENS.md).
 */
const props = defineProps({
    unit: { type: Object, default: () => ({ code: '', name: '', profile: {} }) },
    date: { type: String, default: '' },
    rows: { type: Array, default: () => [] },
    /**
     * The day's shift attestation. Legacy printed it twice: "Consultant Covering" + "TIME"
     * in the header block and an "Endorsed By" / "Endorsed To" footer under the table, with
     * the literal fallback "Not Selected". Both placements are kept; the receiving
     * consultant is printed too (legacy captured it but never printed it — we port the
     * intent, not the omission), except on WARD, whose profile has a single Consultant
     * Oncall field (ruling 5).
     */
    signoff: { type: Object, default: () => ({}) },
    printed_by: { type: String, default: '' },
    printed_at: { type: String, default: '' },
});

const profile = computed(() => props.unit.profile ?? {});

// The identity columns, in legacy print order per unit.
const identityColumns = computed(() => {
    const p = profile.value;

    if ((p.extra_row_fields ?? []).includes('age')) {
        // WARD: Room | Unit | MRN | Name | Age
        return [
            { key: 'bed', label: p.bed_label ?? 'Room' },
            { key: 'ward_unit', label: 'Unit' },
            { key: 'mrn', label: 'MRN' },
            { key: 'patient_name', label: 'Name' },
            { key: 'age', label: 'Age' },
        ];
    }

    const cols = [
        { key: 'bed', label: p.bed_label ?? 'Bed' },
        { key: 'mrn', label: 'MRN' },
        { key: 'patient_name', label: 'Name' },
    ];

    if ((p.extra_row_fields ?? []).includes('dob')) {
        cols.push({ key: 'dob', label: 'DOB' });
    }

    return cols;
});

// The four rich-text columns with the legacy print headings (plan + narrative per unit).
const richColumns = computed(() => [
    { key: 'disease', label: 'Diagnosis List' },
    { key: 'details', label: 'Clinical Condition' },
    { key: 'plan', label: profile.value.plan_label ?? 'Plan Of Care' },
    { key: 'nevent', label: profile.value.narrative_label ?? 'New events' },
]);

const consultantByLabel = computed(() => profile.value.consultant_by_label ?? 'Consultant Covering');
const hasConsultantPair = computed(() => profile.value.consultant_pair !== false);

// Legacy printed the literal string "Not Selected" for an unfilled endorser rather than an
// empty line — an explicitly blank attestation, which is the safer thing on a medico-legal sheet.
const orNotSelected = (value) => (value ? value : 'Not Selected');

// Auto-open the print dialog once rendered (parity with the legacy print pages).
onMounted(() => {
    if (typeof window !== 'undefined' && props.rows.length > 0) {
        window.setTimeout(() => window.print(), 250);
    }
});
</script>

<template>
    <div class="print-sheet">
        <Head :title="`${unit.code} Endorsement ${date}`" />

        <!--
          Its own way back, since Print is no longer opened in a throwaway tab — a new tab
          from an installed PWA lands in the system browser, with a different cookie jar and
          therefore a sign-in page. `print:hidden` keeps it off the paper.
        -->
        <p class="print:hidden mb-4">
            <Link :href="`/endorsement/${unit.code}/${date}`"
                  class="rounded-md border border-line px-3 py-1.5 text-sm text-body hover:bg-ground-deep">
                ← Back to the sheet
            </Link>
        </p>

        <header class="print-head">
            <h1>{{ unit.name }} — Shift Endorsement</h1>
            <p>Qatif Central Hospital · {{ date }} · {{ rows.length }} patient(s)</p>
            <!-- Legacy header line: "Consultant Covering: …" (WARD: "Consultant Oncall") and "TIME: …". -->
            <p class="print-signoff-head" data-testid="print-signoff-head">
                {{ consultantByLabel }}: {{ orNotSelected(signoff.consultant_by_name) }}
                <span v-if="signoff.endorsement_time">&nbsp;&nbsp;·&nbsp;&nbsp;TIME: {{ signoff.endorsement_time }}</span>
            </p>
        </header>

        <p v-if="rows.length === 0" class="print-empty">No handover rows for this day.</p>

        <table v-else class="print-table" data-testid="print-table">
            <thead>
                <tr>
                    <th v-for="c in identityColumns" :key="c.key">{{ c.label }}</th>
                    <th v-for="c in richColumns" :key="c.key">{{ c.label }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in rows" :key="r.id" data-testid="print-row">
                    <td v-for="c in identityColumns" :key="c.key">{{ r[c.key] }}</td>
                    <td v-for="c in richColumns" :key="c.key" v-html="r[c.key]"></td>
                </tr>
            </tbody>
        </table>

        <!--
          Legacy footer block: "Endorsed By" / "Endorsed To" under the table, names from the
          snapshots frozen at sign-off so this sheet reads the same forever.
        -->
        <footer class="print-signoff" data-testid="print-signoff">
            <div class="print-signoff-grid">
                <!-- Name AND the signature the sheet was actually signed with (frozen at
                     sign-off, served by content hash — never "whatever they use today"). -->
                <p>
                    <strong>Endorsed By: </strong>{{ orNotSelected(signoff.endorsed_by_name) }}
                    <img v-if="signoff.endorsed_by_signature" :src="signoff.endorsed_by_signature"
                         alt="" data-testid="print-signature-by" class="print-signature" />
                </p>
                <p>
                    <strong>Endorsed To: </strong>{{ orNotSelected(signoff.endorsed_to_name) }}
                    <img v-if="signoff.endorsed_to_signature" :src="signoff.endorsed_to_signature"
                         alt="" data-testid="print-signature-to" class="print-signature" />
                </p>
                <p><strong>{{ consultantByLabel }}: </strong>{{ orNotSelected(signoff.consultant_by_name) }}</p>
                <p v-if="hasConsultantPair"><strong>Consultant Receiving: </strong>{{ orNotSelected(signoff.consultant_to_name) }}</p>
            </div>
            <p v-if="signoff.signed_off" class="print-signoff-stamp" data-testid="print-signoff-stamp">
                Signed off {{ signoff.signed_off_at }}<span v-if="signoff.signed_off_by_name"> by {{ signoff.signed_off_by_name }}</span>.
            </p>
            <p v-else class="print-signoff-stamp" data-testid="print-signoff-unsigned">
                NOT SIGNED OFF — this sheet has no shift attestation.
            </p>

            <!--
              Attribution. Paper leaves the building and nothing downstream controls it; an
              unattributed census found on a desk tells you nothing about where it came from.
            -->
            <p v-if="printed_by" class="print-attribution" data-testid="print-attribution">
                Printed by {{ printed_by }} — {{ printed_at }} — {{ unit.code }} {{ date }}.
                Contains patient-identifiable information: handle and dispose of accordingly.
            </p>
        </footer>
    </div>
</template>

<style scoped>
.print-sheet {
    max-width: 297mm; /* A4 landscape width */
    margin: 0 auto;
    padding: 12mm;
    color: #000;
    background: #fff;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 11px;
}

.print-head {
    margin-bottom: 8px;
    border-bottom: 2px solid #000;
    padding-bottom: 6px;
}

.print-head h1 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
}

.print-head p {
    margin: 2px 0 0;
    font-size: 11px;
    color: #333;
}

.print-signoff-head {
    margin: 2px 0 0;
    font-size: 11px;
}

.print-signoff {
    margin-top: 10px;
    border-top: 1px solid #000;
    padding-top: 6px;
    font-size: 11px;
}

.print-signoff-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2px 24px;
}

.print-signoff-grid p {
    margin: 0;
}

/* The signature sits on the same line as the name it belongs to and never grows tall
   enough to push the footer onto a second page. */
.print-signature {
    display: block;
    height: 34px;
    margin-top: 2px;
    max-width: 220px;
    object-fit: contain;
}

.print-attribution {
    margin-top: 0.35rem;
    font-size: 8pt;
    color: #000;
}

.print-signoff-stamp {
    margin: 6px 0 0;
    font-size: 10px;
}

.print-empty {
    margin-top: 24px;
    text-align: center;
    color: #555;
}

.print-table {
    width: 100%;
    border-collapse: collapse;
}

.print-table th,
.print-table td {
    border: 1px solid #000;
    padding: 3px 5px;
    text-align: left;
    vertical-align: top;
}

.print-table thead th {
    background: #eee;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

/* Restore list markers inside the handover rich text (and ONLY that).
   Tailwind's preflight resets `ol,ul,menu{list-style:none}` and zeroes list padding
   globally, so a handover written as a numbered plan would print as flat unmarked lines —
   the ordering, which is the clinical content of an ordered list, lost on the sheet
   handed to the next shift. `.prose-handover` fixes this on screen; these rules are the
   print-sheet equivalent and deliberately mirror those values.

   `:deep()` is required, not stylistic: the handover cells render through `v-html`, and
   Vue does not stamp the scoped data attribute onto nodes inserted that way, so a plain
   `.print-table ul` selector would match nothing here. */
.print-table :deep(ul),
.print-table :deep(ol) {
    margin: 0.25rem 0;
    padding-left: 1.25rem;
}

/* Longhands, not the `list-style` shorthand, on purpose: the CSS minifier rewrites
   `list-style: disc outside` to `list-style: outside` (dropping the "redundant" default),
   which only still means disc because the shorthand resets omitted longhands. Spelling out
   list-style-type keeps the marker explicit in the shipped bundle and greppable by the
   build test. */
.print-table :deep(ul) {
    list-style-type: disc;
    list-style-position: outside;
}

.print-table :deep(ol) {
    list-style-type: decimal;
    list-style-position: outside;
}

.print-table :deep(li) {
    margin: 0.1rem 0;
}

@page {
    size: A4 landscape;
    margin: 8mm;
}

@media print {
    .print-sheet {
        padding: 0;
    }
}
</style>
