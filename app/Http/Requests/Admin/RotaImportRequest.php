<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\DetectsOversizedUpload;
use App\Support\Rota\RotaImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * MR-06's import upload (P1d-2 Task 12). ONE request class behind BOTH routes — the preview and
 * the commit take the same body, plus a commit-only `digest`, which is what makes "the commit is
 * the file you previewed" a property of one shape rather than of two that have to be kept in step.
 *
 * `kind` IS AN ENUM AND IS NEVER SNIFFED FROM THE HEADERS. The two files share every column they
 * have in common — `short_name`, `full_name`, `starts_on`, `ends_on` — so a sniff would read a
 * leave file as an assignments file missing four required columns, or worse, the other way round
 * and write leave rows from a rota. A file whose shape is guessed is a file that gets misread, so
 * the operator says which file this is on the screen and the server validates that they said one
 * of the two.
 *
 * THERE IS NO MAPPING HERE, deliberately (Decision H difference 1). `RosterImportRequest` carries
 * an operator `$mapping` because a hospital HR spreadsheet has arbitrary headers. A rota file
 * round-trips from this system's OWN export, so the headers are fixed names and a missing one is
 * a file error naming it — a mapping screen would be ceremony wrapped around a drift surface.
 *
 * `digest` IS REQUIRED ON THE COMMIT ROUTE ONLY, because the preview is what produces it. The
 * refusal itself lives in `RotaImport::commit()` rather than here (Task 11's amendment: one
 * definition of the pin, used by both entry points) — this rule only rejects the shapes that
 * could never be a digest, so a client bug is named as one instead of arriving at `hash_equals()`
 * to come back as "the file changed", which would send the operator to re-export a file that
 * never moved.
 */
class RotaImportRequest extends FormRequest
{
    /** Finding 9's `post_max_size` detection — one definition, shared with `RosterImportRequest`. */
    use DetectsOversizedUpload;

    public const MAX_KILOBYTES = 4096;

    /** The route middleware (`cap:rota.manage`) is the gate; nothing extra here. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // FIRST — see the trait's docblock. There is no `merge()` in this class today, and the
        // call still leads: the ordering constraint is a property of the trait, not of whether
        // this particular request happens to merge anything yet.
        $this->detectOversizedUpload();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // finding 9: when the empty-POST shape is detected, 'file' is NOT marked required —
        // withValidator() supplies the one, correct error instead of a second confusing
        // "the file field is required" beside it.
        $fileRule = $this->uploadWasOversized() ? 'nullable' : 'required';

        return [
            'kind' => ['required', 'string', Rule::in([RotaImport::KIND_ASSIGNMENTS, RotaImport::KIND_VACATIONS])],
            'file' => [$fileRule, 'file', 'mimes:csv,txt,tsv', 'max:'.self::MAX_KILOBYTES],
            'digest' => [
                $this->routeIs('admin.rota.import.commit') ? 'required' : 'nullable',
                'string',
                // sha256, hex.
                'size:64',
            ],
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
