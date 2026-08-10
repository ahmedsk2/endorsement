<?php

namespace App\Support\Rota;

use RuntimeException;

/**
 * The commit arrived against a rota that has moved since the preview was rendered (P1d-2 Task 11,
 * added by the slice review — see `StatePin` for what the pin covers and why re-deriving the
 * analysis is not on its own enough).
 *
 * ITS OWN TYPE, AND NOT A VARIANT OF `StaleImportFileException`. The two refusals name two
 * different operator actions with two different fixes: a file that changed is re-exported, and a
 * rota that changed is re-previewed. Re-exporting a rota that moved fixes nothing, and re-previewing
 * a file that moved reads the wrong file — so a controller has to be able to tell them apart by
 * TYPE rather than by matching an error message's text, which is exactly the drift this codebase
 * keeps removing (`AuditChain::canonical()`, `SignoffPickers`,
 * `PeriodGenerator::assertMonthAligned()`).
 *
 * The rota sibling of `StaleFillPlanException`, which says the same thing about a bulk fill.
 *
 * Thrown from INSIDE `RotaImport::commit()`'s transaction, so the refusal rolls back whatever a
 * future edit might one day place above it rather than resting on a `return` that is only safe
 * today.
 */
class StaleRotaStateException extends RuntimeException {}
