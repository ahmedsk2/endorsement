<?php

namespace App\Http\Requests\Concerns;

/**
 * P1c finding 9, extracted to ONE definition when P1d-2 Task 12 added a second upload screen.
 *
 * PAST `post_max_size`, PHP DISCARDS THE WHOLE REQUEST BODY. `$_POST` and `$_FILES` come back
 * empty while `Content-Length` still names the real size the browser sent, so a plain
 * `required|file` rule reports *"the file field is required"* — which sends an operator looking
 * for a form field that is right there on their screen. This detects that exact shape and lets
 * the request name the size limit instead.
 *
 * THE ORDERING IS THE WHOLE TRICK, and it was found empirically rather than by inspection.
 * `detectOversizedUpload()` must run at the very TOP of `prepareForValidation()`, before the
 * request's own `merge()` call: `merge()` writes keys onto the parameter bag even when their
 * decoded value is `null`, which makes `$this->post()` non-empty from that point on. Checking
 * afterwards would therefore never see the empty body, silently disabling the whole guard while
 * looking correct. A direct `Illuminate\Http\Request::create()` with the same server vars showed
 * `post()` correctly empty; the same shape routed through the FormRequest reported the wrong
 * error — the merge, not the detection logic, was the bug.
 *
 * Extracted rather than copied because that ordering constraint is not visible from the call
 * site: a second hand-written copy would have been a second chance to get it backwards.
 */
trait DetectsOversizedUpload
{
    private bool $emptyPostPastUploadLimit = false;

    /** Call FIRST in `prepareForValidation()` — see the trait docblock on why the position matters. */
    protected function detectOversizedUpload(): void
    {
        $contentLength = (int) $this->server('CONTENT_LENGTH', 0);

        // A GET-shaped empty body is not this: the check only fires when the request genuinely
        // claimed to be carrying content and arrived with none of it.
        $this->emptyPostPastUploadLimit = $contentLength > 0
            && $this->post() === []
            && $this->allFiles() === [];
    }

    protected function uploadWasOversized(): bool
    {
        return $this->emptyPostPastUploadLimit;
    }

    /**
     * The one sentence both screens show. Stated in megabytes because that is the unit the
     * operator's own file manager uses.
     */
    protected function oversizedUploadMessage(int $maxKilobytes): string
    {
        return 'That file is larger than the '.round($maxKilobytes / 1024)
            .' MB upload limit — choose a smaller export.';
    }
}
