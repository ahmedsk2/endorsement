<?php

namespace App\Support\Rota;

use RuntimeException;

/**
 * The confirm arrived against a plan the rota has moved on from (P1d-2 Task 8).
 *
 * Its own type, rather than a string in `RotaFill`'s `errors` list, because the controller has to
 * tell this refusal apart from every other one to put the message on the right field — and picking
 * an HTTP field by matching an error message's text is exactly the drift this codebase keeps
 * removing (`AuditChain::canonical()`, `SignoffPickers`, `PeriodGenerator::assertMonthAligned()`:
 * one definition, two consumers).
 *
 * Thrown from INSIDE `RotaFill::apply()`'s transaction, so the refusal rolls back whatever a future
 * edit might have written above it rather than relying on a `return` that is only safe today.
 */
class StaleFillPlanException extends RuntimeException {}
