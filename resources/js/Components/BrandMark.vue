<template>
    <!--
      The "handover trace": a monitor waveform that runs, breaks at a filled node, and is
      picked up again in a lighter tone — the shift change itself, drawn in the instrument
      language the design tokens establish. The vertical strip is the .channel-bar
      signature, carried inside the mark. Colours come from token utilities only; no hex
      ever reaches the markup.
    -->
    <svg viewBox="0 0 40 40" role="img" aria-label="Paediatric Endorsement" xmlns="http://www.w3.org/2000/svg">
        <rect width="40" height="40" rx="8" class="fill-channel" />
        <rect x="6" y="9" width="2.5" height="22" rx="1.25" class="fill-channel-soft" />
        <!-- outgoing shift -->
        <path d="M12 22 H15 L17.5 13 L20 22" fill="none" class="stroke-panel brand-trace brand-trace-out"
              stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" />
        <!-- the handover itself -->
        <circle cx="22.5" cy="22" r="2.6" class="fill-panel brand-node" />
        <!-- incoming shift -->
        <path d="M25 22 L27.5 27 L30 22 H33" fill="none" class="stroke-channel-soft brand-trace brand-trace-in"
              stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
</template>
