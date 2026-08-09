<?php

namespace App\Support\Roster;

use RuntimeException;

/**
 * A roster file that cannot be read at all — wrong encoding, or absurdly large. Distinct from a
 * ROW-level or FILE-level VALIDATION error (which `RosterImport` reports per row, or as one of
 * `file_errors`, without throwing): this is thrown by the reader itself, before a single row can
 * be produced, so the caller has nothing to build a report from.
 */
class RosterFormatException extends RuntimeException {}
