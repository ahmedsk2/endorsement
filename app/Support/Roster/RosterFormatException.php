<?php

namespace App\Support\Roster;

use RuntimeException;

/**
 * A file that cannot be read at all — wrong encoding, or absurdly large. Distinct from a ROW-level
 * or FILE-level VALIDATION error (which `RosterImport` and `RotaImport` report per row, or as one
 * of `file_errors`, without throwing): this is thrown by the READER, so the caller has nothing to
 * build a report from and the whole import is refused.
 *
 * IT IS NOT ALWAYS THROWN BEFORE THE FIRST ROW, and a caller that assumes so gets a 500. The
 * encoding check is in the constructor and does precede everything; the ROW CAP cannot be, because
 * rows are not counted until they are read — and `CsvRosterReader::rows()` is a GENERATOR, so the
 * cap fires mid-iteration, inside whatever `foreach` the importer is running, long after any
 * try/catch wrapped around *building* the reader has returned. That is exactly how both rota import
 * routes shipped as an uncaught `RuntimeException`. A catch for this belongs around the IMPORT
 * call, not around the constructor.
 */
class RosterFormatException extends RuntimeException {}
