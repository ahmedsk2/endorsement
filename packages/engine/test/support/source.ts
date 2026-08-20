/**
 * `SourceScanner::withoutComments()`'s non-PHP path, in TypeScript.
 *
 * Lifted out of `conditions.test.ts` at P2-2 Task 21 rather than copied into a second suite: a
 * stripper written twice is two definitions of what counts as a comment, and the two would agree
 * until the day one of them was taught something the other was not. This file is NOT a `.test.ts`,
 * so no runner collects it and importing it does not re-register another file's cases.
 *
 * Block comments first, then lines whose first non-space characters are `//`. Conservative on
 * purpose and for the reason `SourceScanner` records — leaving a comment behind is a noisy false
 * positive, eating code is a silent false negative. Strings are NOT stripped: an exception message
 * carrying a forbidden word is code a user can see.
 *
 * It exists because **a docblock is scanned source**, which this phase has now paid for nine times:
 * `eligibility.ts`'s docblock names the identifier whose absence its own scan asserts, and the
 * weekday scan bites the test that proves a weekday name is refused.
 */
export function withoutComments(source: string): string {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
}
