<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Creates the FIRST administrator on a clean database.
 *
 * A fresh production system has no accounts, and every route that could create one sits
 * behind auth + a capability. Registration only produces a *pending Resident*, and
 * approving that pending Resident needs an administrator — so without this command the
 * system cannot be entered at all. It is the single out-of-band door in, which is why it
 * is deliberately conservative:
 *
 *  - interactive only: no password on the command line, where it would land in the shell
 *    history, in `ps` output, and in the container's audit trail;
 *  - the SAME PasswordPolicy as every in-app path, so the bootstrap door is not the
 *    weakest one;
 *  - refuses an existing username rather than updating, so a careless re-run can never
 *    reset a real administrator's password;
 *  - audited, because "an admin account appeared outside the UI" is exactly what an
 *    incident review asks about.
 */
class CreateAdmin extends Command
{
    protected $signature = 'user:create-admin';

    protected $description = 'Create the first administrator account (interactive)';

    public function handle(): int
    {
        $username = trim((string) $this->ask('Username (used to sign in)'));
        $fullName = trim((string) $this->ask('Full name (shown on printed handovers)'));
        $email = trim((string) $this->ask('Email address'));

        // secret() so the password is never echoed to the terminal or captured by a
        // scrollback buffer someone else can read.
        $password = (string) $this->secret('Password');
        $confirm = (string) $this->secret('Confirm password');

        $validator = Validator::make(
            [
                'member_name' => $username,
                'full_name' => $fullName,
                'member_email' => $email,
                'password' => $password,
                'password_confirmation' => $confirm,
            ],
            [
                'member_name' => ['required', 'string', 'max:50', 'unique:users,member_name'],
                'full_name' => ['required', 'string', 'max:100'],
                'member_email' => [
                    'required', 'email', 'max:150', 'unique:users,member_email',
                    Rule::unique('people', 'email'),
                ],
                'password' => PasswordPolicy::rules(),
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($username, $fullName, $email, $password): User {
            $person = Person::create([
                'full_name' => $fullName,
                'position' => 0,                  // Admin
                'email' => Person::normalizeEmail($email),
                'active' => true,
            ]);

            return User::create([
                'person_id' => $person->id,
                'member_name' => $username,
                'member_email' => $email,
                'password' => $password,          // hashed by the model's `hashed` cast
                'active' => true,
                // Verified on creation: SMTP is configured in-app, AFTER login, so gating the
                // bootstrap admin behind an email would deadlock the system it exists to open.
                'email_verified_at' => now(),
                'pass_exp_date' => now()->addYear()->format('Y-m-d'),
            ]);
        });

        // Identifiers and counts only — never the password, never the hash.
        AuditLog::record('admin_bootstrapped', 'user_id='.$user->id, $user->id, 'console');

        $this->info("Administrator '{$username}' created.");
        $this->line('Sign in, then set up two-step sign-in from your profile — an admin');
        $this->line('account cannot reach the admin screens without it.');

        return self::SUCCESS;
    }
}
