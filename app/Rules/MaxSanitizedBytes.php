<?php

namespace App\Rules;

use App\Casts\SanitizedHtml;
use App\Support\RichTextSanitizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The HTTP-boundary half of the fix for SPC-RPT-058's byte-vs-character gap (see
 * `SanitizedHtml::MAX_PLAINTEXT_BYTES` for the full derivation). Wired onto the four
 * rich-text handover fields in `EndorsementController::validateRow()` in place of the old
 * `max:20000`, which counted CHARACTERS of the RAW submitted value — the database (and
 * `SanitizedHtml::set()`, which this rule mirrors) cares about BYTES of the SANITIZED value.
 * Those two disagree for any script wider than ASCII, and disagreed by enough that Arabic
 * clinical text could silently fail to save well under the old rule's stated allowance.
 *
 * Deliberately re-runs `RichTextSanitizer::clean()` here rather than trusting the raw
 * value's byte length: sanitization can only ever GROW a value in this allow-list (entity-
 * encoding `&`/`<`/`>` in text content), never shrink it below what the model will actually
 * store, so validating the raw bytes would let content through that then failed silently
 * later at the cast. `RichTextSanitizer::clean()` is pure and idempotent (see its own and
 * `SanitizedHtml::get()`'s docblocks), so sanitizing twice — once here, once in the cast on
 * save — is safe and produces byte-for-byte the same output.
 */
class MaxSanitizedBytes implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $bytes = strlen(RichTextSanitizer::clean($value));

        if ($bytes > SanitizedHtml::MAX_PLAINTEXT_BYTES) {
            $fail('The :attribute is too long once formatting is applied ('
                .number_format($bytes).' of '.number_format(SanitizedHtml::MAX_PLAINTEXT_BYTES)
                .' bytes allowed).');
        }
    }
}
