<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\DetectsOversizedUpload;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ST-04's upload — shared by BOTH the preview and the commit endpoints, since the two accept the
 * same shape (`file`, `mapping`) plus commit-only extras (`confirmations`, `digest`) that are
 * simply absent on a preview.
 *
 * `mapping`/`confirmations` arrive as JSON-encoded strings (this is a `multipart/form-data`
 * upload carrying a file, not a JSON body) and are decoded here so `rules()` can validate them as
 * arrays.
 *
 * Finding 9: past `post_max_size`, PHP silently empties `$_POST` and `$_FILES` — a plain
 * `required|file` rule reports "the file field is required" for an oversized upload, which sends
 * the operator looking for a missing form field. `withValidator()` detects that EXACT shape (an
 * empty post body alongside a non-zero `Content-Length`) and names the size limit instead.
 */
class RosterImportRequest extends FormRequest
{
    /**
     * Finding 9's `post_max_size` detection, shared with `RotaImportRequest` since P1d-2 Task 12
     * — the ordering constraint it carries (detect BEFORE this class's own `merge()`) is not
     * visible from the call site, so it lives in one place rather than in two hand-written copies.
     */
    use DetectsOversizedUpload;

    public const MAX_KILOBYTES = 4096;

    /** The route middleware (`cap:people.manage`) is the gate; nothing extra here. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // FIRST, before the merge below — see the trait's docblock for why the position is the
        // whole trick and how getting it backwards silently disables the guard.
        $this->detectOversizedUpload();

        $mapping = $this->input('mapping');
        $confirmations = $this->input('confirmations');

        $this->merge([
            'mapping' => is_string($mapping) ? (json_decode($mapping, true) ?? []) : $mapping,
            'confirmations' => is_string($confirmations) ? (json_decode($confirmations, true) ?? []) : $confirmations,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // finding 9: when the empty-POST shape is detected, 'file' is NOT marked required —
        // withValidator() below supplies the one, correct error instead of a confusing SECOND
        // "the file field is required" alongside it.
        $fileRule = $this->uploadWasOversized() ? 'nullable' : 'required';

        return [
            'file' => [$fileRule, 'file', 'mimes:csv,txt,tsv', 'max:'.self::MAX_KILOBYTES],
            // 'full_name'/'position' are NOT `required` here — a first, header-discovery call
            // (Task 12's Vue page posts an empty mapping to learn the real headers before the
            // operator has chosen anything) must reach the controller and get a normal response.
            // Whether the REQUIRED fields are actually mapped is `RosterImport::analyse()`'s own
            // `file_errors` check — one definition of "is this file usable", not two.
            'mapping' => ['required', 'array'],
            'mapping.full_name' => ['nullable', 'string'],
            'mapping.position' => ['nullable', 'string'],
            'mapping.short_name' => ['nullable', 'string'],
            'mapping.email' => ['nullable', 'string'],
            'mapping.phone' => ['nullable', 'string'],
            'mapping.level' => ['nullable', 'string'],
            'mapping.joined_at' => ['nullable', 'string'],
            // Commit-only; simply absent on a preview.
            'confirmations' => ['nullable', 'array'],
            'confirmations.*' => ['boolean'],
            'digest' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->uploadWasOversized()) {
                $validator->errors()->add('file', $this->oversizedUploadMessage(self::MAX_KILOBYTES));
            }
        });
    }
}
