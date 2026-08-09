<?php

namespace App\Support\Roster;

/**
 * ST-04's reader, as a PORT (P1c Decision E).
 *
 * There is no spreadsheet package in composer.lock and adding one to a system holding children's
 * PHI is the owner's supply-chain decision, not a developer's. So CSV/TSV ships on PHP core, and
 * xlsx becomes ONE class implementing this interface plus one composer line and an explicit
 * "ext-zip": "*" (zip is already installed in the image at Dockerfile:76, in CI at ci.yml:30 and
 * locally) on the day the owner says so. Nothing in the preview, the validation report or the
 * commit path changes.
 */
interface RosterReader
{
    /** @return list<string> the header row, in file order */
    public function headers(): array;

    /** @return iterable<int, array<string, string>> data rows keyed by header text */
    public function rows(): iterable;
}
