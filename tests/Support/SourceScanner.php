<?php

namespace Tests\Support;

/**
 * ONE comment stripper, shared by every source-scanning guard that needs one.
 *
 * WHY A STRIPPER EXISTS AT ALL. Most guards in this codebase deliberately scan PROSE —
 * `CalendarIsTheOnlyConverterTest`, `CalendarWritersFlushTest` and
 * `ContactFieldsAreProjectedOnceTest` all match docblocks, and their remedy for a false positive is
 * to spell around the forbidden shape in the comment. That remedy is unavailable for the two
 * absence guards: `RotaAccessTest`'s MR-04 scan and `ClinicHooksTest`'s CL-03/CL-04 scan both look
 * for the exact vocabulary that the files' own docblocks use to state the rule being enforced
 * (`ClinicRoster` says "IT SUBTRACTS NO LEAVE AND MUST NEVER BECOME AN ELIGIBILITY COMPUTATION" in
 * those words). A guard that fails the build on the documentation of its own rule teaches people to
 * delete the documentation, so those two scans strip comments first — and only those two.
 *
 * WHY IT LIVES HERE RATHER THAN IN EACH TEST. It was `RotaAccessTest`'s private method until P1e
 * Task 6 needed the same behaviour for clinics. Two comment strippers would be two definitions of
 * one fact, and they would drift the first time one of them learned about a new comment shape —
 * which this whole codebase is organised against. Both guards now calibrate the SAME code, in both
 * directions, against real files of their own choosing.
 *
 * CONSERVATIVE ON PURPOSE. Leaving a comment behind produces a false POSITIVE — noisy, but visible
 * and diagnosable. Eating code produces a false NEGATIVE: every needle misses, the guard is
 * silently disabled, and the run looks exactly like a clean tree. Each caller therefore pins BOTH
 * directions against a real file (`test_the_scan_strips_comments_and_still_sees_the_code`): the
 * prose is gone, and a known code token is still there.
 */
final class SourceScanner
{
    /**
     * PHP through the tokenizer — exact, and it cannot mistake a `//` inside a string literal for a
     * comment. Anything else through three conservative regexes: HTML comments, block comments, and
     * lines whose first non-space characters are `//` (so a `//` inside an attribute value or a URL
     * survives, which is the conservative half of the trade above).
     *
     * STRINGS ARE NOT STRIPPED, by either path, and that is deliberate rather than an omission: an
     * exception message or a rendered label carrying a forbidden word is code the user can see, not
     * documentation about code, and a scan that ignored it would miss a real surface.
     */
    public static function withoutComments(string $path): string
    {
        $source = (string) file_get_contents($path);

        if (str_ends_with($path, '.php')) {
            $code = '';

            foreach (token_get_all($source) as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                        continue;
                    }

                    $code .= $token[1];

                    continue;
                }

                $code .= $token;
            }

            return $code;
        }

        $source = (string) preg_replace('/<!--.*?-->/s', '', $source);
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);

        return (string) preg_replace('#^\s*//.*$#m', '', $source);
    }
}
