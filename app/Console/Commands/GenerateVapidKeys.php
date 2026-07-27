<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Generate the VAPID keypair for web push.
 *
 * The settings screen used to say "generate once with `npx web-push generate-vapid-keys`",
 * which is an instruction the owner cannot follow where it matters: the production image
 * ships no Node (it is built in a throwaway stage and never copied into the runtime). So
 * the one place with a shell on the real system was the one place the documented command
 * would not run.
 *
 * The library already in this application does it in PHP, so this needs no new dependency.
 *
 * NOTE FOR LOCAL RUNS: this needs OpenSSL to generate a P-256 key, and a Windows PHP built
 * without an `openssl.cnf` fails here with "configuration file routines::no such file".
 * That is the environment, not this command — it was verified working inside the production
 * container, which is where it is meant to be run anyway.
 *
 * The private key is a SECRET — it authorises pushes to every subscriber. It is printed
 * once, here, and is stored encrypted at rest once pasted into Admin -> Settings. Treat it
 * like the SMTP password: paste it, then clear your scrollback.
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'push:vapid-keys';

    protected $description = 'Generate a VAPID keypair for web push notifications';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->newLine();
        $this->info('VAPID keypair generated. Paste these into Admin → Settings → Push notifications.');
        $this->newLine();

        $this->line('  Public key   (safe to expose — it is sent to every browser)');
        $this->line('  '.$keys['publicKey']);
        $this->newLine();
        $this->line('  Private key  (SECRET — stored encrypted; never commit or paste it anywhere else)');
        $this->line('  '.$keys['privateKey']);
        $this->newLine();

        $this->comment('Subject: a mailto: address you control, e.g. mailto:you@example.org.');
        $this->comment('Push stays off until all three are saved. Nothing else changes if you skip it.');
        $this->newLine();

        // Deliberately NOT written to the database from here. Settings are owner-managed
        // through the UI, where the private key lands in the encrypted column; a command
        // that wrote them would need the same encryption path for no benefit, and would
        // make it easy to overwrite a working key by re-running this to "have a look".
        return self::SUCCESS;
    }
}
