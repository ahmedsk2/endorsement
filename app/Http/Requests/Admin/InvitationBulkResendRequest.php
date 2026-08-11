<?php

namespace App\Http\Requests\Admin;

use App\Support\Invitations\BulkResend;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * LV-02's bulk resend (P1c-2 Task 4). ONE request class behind BOTH routes — the preview and the
 * confirm take the same body, which is what makes "the commit is the plan you saw" a property of
 * one shape rather than of two that have to be kept in step (`RotaFillRequest`'s reasoning).
 *
 * THE MAIL REFUSAL LIVES HERE, not in the controller, so it fails before anything is resolved,
 * authorized or written (Decision D property 1). The single-invite path may legitimately proceed
 * with mail off — it flashes the one-time link on screen as the delivery — but a bulk path has
 * nowhere to surface fifty one-time bearer credentials, and a bulk resend that silently mails
 * nothing is worse than one that refuses. It is checked on the PREVIEW too: an operator who
 * previews forty-seven sends and is refused only at the confirm has been given the same
 * information one click too late.
 *
 * `config('mail.default') === 'smtp'` is the question this codebase already asks in three places
 * (`OpsAlert::recipient()`, `SettingsController::sendTestEmail()`,
 * `InvitationController::deliver()`), and it is deliberately about which TRANSPORT is selected
 * rather than whether an SMTP host is set — `config/mail.php` defaults that host to 127.0.0.1, so
 * a host check is true on every deployment that has never configured mail at all.
 *
 * `person_ids.*` carries a BARE `Rule::exists('people', 'id')`, matching `PersonBulkRequest`: a
 * selection made with "select all filtered" legitimately contains retired people, people who have
 * already claimed, and people with no address, and 422-ing the whole submission because three of
 * fifty are done would make the feature unusable. Each is reported with its own outcome by
 * `BulkResend`, from the analysis rather than from a guess the screen made. AUTHORIZATION is the
 * one thing that is NOT a skip — `InvitationController` asserts it over the whole set before the
 * transaction opens, because "a Chief Resident tried to act on a Consultant" is a security event
 * with an audit row, not a row to step past.
 */
class InvitationBulkResendRequest extends FormRequest
{
    /**
     * The invitation endpoints are this codebase's one deliberate exception to a `cap:` middleware
     * (invariant 8): the rule is two-tier and position-dependent, so it is applied in-controller by
     * `App\Support\ManagerScope` and asserted over the router by `InvitationResendTest`.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'person_ids' => ['required', 'array', 'min:1', 'max:'.BulkResend::CAP],
            'person_ids.*' => ['integer', Rule::exists('people', 'id')],
            // Required on the CONFIRM only — the preview is what produces it. sha256, hex: a
            // wrong-length digest is a client bug and is named as one rather than reaching
            // `hash_equals` to come back as "something changed", which would send the operator to
            // re-preview a roster that never moved.
            'digest' => [
                $this->routeIs('admin.invitations.bulk-resend') ? 'required' : 'nullable',
                'string',
                'size:64',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // The cap is NAMED. A silent truncation to fifty would leave the operator believing the
            // other people were mailed, with nothing on screen to say otherwise — and the whole
            // point of a bulk credential operation is that the operator can see who got one.
            'person_ids.max' => 'Resend to at most '.BulkResend::CAP.' people at a time. '
                .'Narrow the selection and run it again — each batch reports who was sent to.',
            'person_ids.required' => 'Select at least one person to resend to.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (config('mail.default') !== 'smtp') {
                $validator->errors()->add(
                    'person_ids',
                    'Configure SMTP under Settings before resending invitations in bulk. '
                    .'A bulk resend has nowhere to show you the one-time links, so it will not '
                    .'create them without a way to deliver them.',
                );
            }
        });
    }
}
