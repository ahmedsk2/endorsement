/**
 * `preset:scfhs` — CG-08's *"empty SCFHS/local preset slot"*, present and empty.
 *
 * ## Present, and empty, are two claims and this file makes both
 *
 * CG-08 asks for *"an empty SCFHS/local preset slot to be encoded when the official numeric policy
 * arrives"*. An empty array on its own is indistinguishable from a failed load, from a bundle
 * somebody deleted the contents of, and from nobody having written it yet — three states, one
 * appearance, and only one of them safe to build on. So the slot is a well-formed record with zero
 * conditions and a MANDATORY {@link Preset.pending} block naming what is awaited, who supplies it
 * and when somebody last asked, and `manifest.ts` declares the state `empty` a second time so the
 * emptiness is compared rather than assumed.
 *
 * ## What is actually awaited
 *
 * SPEC §37 still owes *"the SCFHS/local duty-hour policy in numeric form"*, and §38's second
 * unvalidated assumption is that it maps onto the CG-07 catalog at all. That is the whole reason
 * this bundle cannot be written today, and it is worth being precise about which half is missing:
 * **every predicate ships** — all 22 implemented type keys carry an evaluator, a preview and a
 * params schema — and the numbers do not. A department enabling `rolling_hours_max` in P3 will find
 * the type present and the local figure absent. That is the correct state.
 *
 * `limitations` is empty rather than absent: a bundle with no rows has stated no clause it cannot
 * implement, which is a different thing from having forgotten to look.
 */

import type { Preset } from './types';

export const SCFHS: Preset = {
    key: 'preset:scfhs',
    title: 'SCFHS / local policy',
    describes:
        'The slot the local duty-hour policy lands in, deliberately holding nothing until that ' +
        'policy exists in numeric form. Present and empty, which is not the same as absent.',
    conditions: [],
    drafts: [],
    limitations: [],
    pending: {
        awaits:
            'The SCFHS or other local duty-hour policy in numeric form (SPEC §37). Which catalog ' +
            'types it maps onto is itself unvalidated (SPEC §38), so the type list is awaited with ' +
            'the numbers rather than ahead of them — unlike the residency bundle, whose shape SPEC ' +
            "Appendix A's requirements line already names.",
        from: 'The department owner, from the published SCFHS or local regulatory policy.',
        lastCheckedOn: '2026-08-20',
    },
};
