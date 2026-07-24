<?php

return [

    /*
     * The unit handover times (Asia/Riyadh wall clock). Shared by all four units — the
     * legacy quick-picks '7:30 Am' / '13:30' are the display forms of these same shifts.
     */
    'handover_times' => ['07:30', '13:30'],

    /*
     * Minutes after a handover time before the reminder fires: at handover_time + delay,
     * a unit with no SIGNED endorsement for today gets its opted-in users pushed.
     */
    'remind_delay_minutes' => (int) env('ENDORSEMENT_REMIND_DELAY', 10),

    /*
     * Web-push VAPID keys. OWNER-MANAGED: generated once (e.g. `npx web-push
     * generate-vapid-keys`), set in the environment, never committed. With no keys the
     * reminder command runs but skips sending, so deploys never break on this.
     */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@example.org'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

];
