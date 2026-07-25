<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Settings (cap:settings.manage, Administrator-only by default): the runtime
 * settings editable in the app instead of the .env file — SMTP, push/VAPID, reminder
 * times.
 *
 * SECRETS ARE WRITE-ONLY: the page receives `secrets.{key} = bool` (is one stored?) and
 * NEVER the value; an empty submit keeps the stored value; changes are audited by key
 * name alone. The owner types their own secrets into their own admin page — nothing is
 * ever echoed, logged, or committed.
 */
class SettingsController extends Controller
{
    public function index(): Response
    {
        $all = AppSettings::all();

        $settings = [];
        $secrets = [];

        foreach (AppSettings::KEYS as $key => $isSecret) {
            if ($isSecret) {
                $secrets[$key] = array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '';
            } else {
                $settings[$key] = $all[$key] ?? null;
            }
        }

        return Inertia::render('Admin/Settings', [
            'settings' => $settings,
            'secrets' => $secrets,
            // The deployed fallbacks, so the page can show what applies when unset.
            'defaults' => [
                'remind_delay_minutes' => (int) config('endorsement.remind_delay_minutes'),
                'handover_times' => config('endorsement.handover_times'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mail_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mail_port' => ['sometimes', 'nullable', 'integer', 'between:1,65535'],
            'mail_username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mail_password' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'mail_encryption' => ['sometimes', 'nullable', 'in:tls,ssl,none'],
            'mail_from_address' => ['sometimes', 'nullable', 'email', 'max:255'],
            'mail_from_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'vapid_subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'vapid_public_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'vapid_private_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'remind_delay_minutes' => ['sometimes', 'nullable', 'integer', 'between:0,120'],
            'handover_time_morning' => ['sometimes', 'nullable', 'date_format:H:i'],
            'handover_time_afternoon' => ['sometimes', 'nullable', 'date_format:H:i'],
        ]);

        $changed = [];

        foreach ($data as $key => $value) {
            // WRITE-ONLY secrets: an empty submit means "keep what is stored" — the page
            // cannot re-send a value it was never given.
            if ((AppSettings::KEYS[$key] ?? false) && ($value === null || $value === '')) {
                continue;
            }

            AppSettings::set($key, $value === null ? null : (string) $value);
            $changed[] = $key;
        }

        if ($changed !== []) {
            // Key names ONLY — never a value (mail credentials are secrets, and even a
            // hostname has no business in the audit trail's narrative).
            AuditLog::record(
                'settings_update',
                'keys='.implode(',', $changed),
                $request->user()->getKey(),
                $request->ip(),
            );

            // Make the change live for THIS process too (queued mail, previews).
            AppSettings::applyOverrides();
        }

        return back()->with('status', 'Settings saved.');
    }
}
