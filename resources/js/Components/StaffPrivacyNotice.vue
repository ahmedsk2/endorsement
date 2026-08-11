<script setup>
/*
 * What this system records about the CLINICIAN, shown where they will actually read it.
 *
 * PDPL requires that a person be informed about processing concerning them. "Activity on
 * this system is recorded" on the sign-in page is a warning, not a notice — it says
 * something is happening without saying what, or for how long, or who to ask.
 *
 * ONE component, used on the invitation-acceptance page and in first-login setup, because a
 * notice that exists in two hand-maintained copies is a notice that will eventually say two
 * different things — and the one nobody updated is the one somebody relied on.
 *
 * The framing is deliberate: the audit trail protects the clinician as much as the patient.
 * That is not spin. The reason a handover can be shown to be accurate and attributable is
 * the same reason a clinician can show what they did and when.
 */
defineProps({
    // 'invitation' is read before an account exists; 'setup' after signing in for the first
    // time. Same content, different framing of the moment.
    context: { type: String, default: 'setup' },
});
</script>

<template>
    <section class="rounded-md border border-line bg-ground-deep p-4 text-sm" data-testid="staff-privacy-notice">
        <h2 class="text-sm font-semibold text-ink">What this system records about you</h2>

        <p class="mt-2 text-muted">
            {{ context === 'invitation'
                ? 'Before you create your account, so you know what you are agreeing to:'
                : 'So you know what is kept, and why:' }}
        </p>

        <ul class="mt-2 space-y-1.5 text-muted">
            <li>
                <span class="font-medium text-ink">Your name on the handover.</span>
                If you sign one, your name — and your signature image, where you applied it —
                appears on that record and on printed copies of it.
            </li>
            <li>
                <span class="font-medium text-ink">What you do in the system.</span>
                Viewing, printing, editing, signing and reopening a handover are each written
                to a tamper-evident log, with the time and the network address you acted from.
            </li>
            <li>
                <span class="font-medium text-ink">Kept for at least 12 months</span>, and the
                log cannot be edited or deleted — including by an administrator.
            </li>
        </ul>

        <p class="mt-3 text-muted">
            This exists so a clinical record can be shown to be accurate and attributable,
            which protects you as much as it protects the patient. You can ask an
            administrator what is recorded about you.
        </p>
    </section>
</template>
