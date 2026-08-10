<?php

namespace App\Support\Rota;

use RuntimeException;

/**
 * The commit arrived against bytes the previewed file no longer matches (P1d-2 Task 11).
 *
 * The file sibling of `StaleFillPlanException`, and its own type for the same reason: the
 * controller has to tell this refusal apart from every other one to put the 422 on the right
 * field, and choosing an HTTP field by matching an error message's text is exactly the drift this
 * codebase keeps removing (`AuditChain::canonical()`, `SignoffPickers`,
 * `PeriodGenerator::assertMonthAligned()`: one definition, two consumers).
 *
 * Thrown from INSIDE `RotaImport::commit()`'s transaction, before anything is read or written, so
 * the refusal rolls back whatever a future edit might one day place above it rather than resting
 * on a `return` that is only safe today.
 */
class StaleImportFileException extends RuntimeException {}
