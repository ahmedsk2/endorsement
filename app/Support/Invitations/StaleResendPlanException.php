<?php

namespace App\Support\Invitations;

use RuntimeException;

/**
 * The confirm arrived against a plan the world has moved on from (P1c-2 Task 4, Decision D).
 *
 * Its own type, rather than a string in an `errors` list, for the reason `StaleFillPlanException`
 * gives: the controller has to tell this refusal apart from every other one to put the message on
 * the right field, and picking an HTTP field by matching an error message's text is exactly the
 * drift this codebase keeps removing.
 *
 * Thrown from INSIDE `BulkResend::commit()`'s transaction and BEFORE its first write, so a stale
 * plan is not a partial send — it is no send at all. Thrown rather than returned so the refusal
 * cannot be walked past by a later edit: a `return` is only safe while nothing above it has
 * written, and that is a property somebody has to keep true.
 */
class StaleResendPlanException extends RuntimeException {}
