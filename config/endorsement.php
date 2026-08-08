<?php

return [

    /*
     * The unit handover times (Asia/Riyadh wall clock). Shared by all four units — the
     * legacy quick-picks '7:30 Am' / '15:30' are the display forms of these same shifts.
     */
    'handover_times' => ['07:30', '15:30'],

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
    /*
     * Require a second factor for accounts holding privileged capabilities. Production
     * default (see App\Http\Middleware\EnforceTwoFactor); overridable per environment.
     */
    'require_2fa' => env('REQUIRE_2FA_PRIVILEGED', env('APP_ENV') === 'production'),

    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@example.org'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    /*
     * Names this deployment in artefacts that leave it: the backup archive, its prune glob,
     * the off-host object prefix, and the host scripts' config/log/state paths. Unset, it is
     * derived from APP_NAME. One customer per database (D11) — this is how an operator tells
     * two customers' ciphertext apart.
     */
    'instance' => [
        'slug' => env('INSTANCE_SLUG'),
    ],

    /*
     * The customer this deployment belongs to. D11 makes the database the isolation boundary,
     * so there is exactly one of these per deployment and it is provenance, not a filter.
     * Defaults preserve the first deployment's identity.
     */
    'institution' => [
        'code' => env('INSTITUTION_CODE', 'QCH'),
        'name' => env('INSTITUTION_NAME', 'Qatif Central Hospital'),
    ],

];
