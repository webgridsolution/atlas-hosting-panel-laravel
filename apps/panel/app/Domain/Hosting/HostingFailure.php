<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\AgentException;
use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

/**
 * Turns a thrown exception into a sentence a customer can act on.
 *
 * Appending $e->getMessage() to a flash message is fine for the deliberate
 * RuntimeException the managers raise ("The primary domain cannot be removed."),
 * but a QueryException would print raw SQL, table names and column values into
 * a page — both a disclosure and useless to the reader.
 *
 * So: known, intentional failures keep their wording; anything unexpected is
 * logged and replaced with a neutral sentence that tells the truth — the change
 * did not happen — without exposing the internals.
 */
final class HostingFailure
{
    public static function message(Throwable $e, string $context): string
    {
        // Order matters: QueryException is a RuntimeException, so it must be
        // tested before the general RuntimeException branch.
        if ($e instanceof QueryException) {
            report($e);

            return $context.'. The change could not be saved, so nothing was altered. '
                .'Try again, and contact your provider if it keeps happening.';
        }

        if ($e instanceof AgentException) {
            // The agent's own words are written for an operator and are safe.
            report($e);

            return $context.': '.rtrim($e->getMessage(), '.');
        }

        if ($e instanceof RuntimeException) {
            return $context.': '.rtrim($e->getMessage(), '.');
        }

        report($e);

        return $context.'. Something failed on the server, so the change was not applied. '
            .'The panel log has the technical detail.';
    }
}
