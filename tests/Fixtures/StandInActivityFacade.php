<?php

/*
 * A stand-in for statamic-activity's facade.
 *
 * The real addon is a `suggest`, not a requirement — the whole point of the
 * bridge is that a site selling one course installs without an audit trail — so
 * it is not in require-dev either. Declaring the class the bridge probes for is
 * what lets the *attached* path be tested at all.
 *
 * It records into a static array rather than pretending to be a ledger. What is
 * under test is the bridge: which facts cross, under which event type, with
 * which subject, brand and dedupe key. How activity stores them is activity's
 * own suite's problem.
 *
 * Loaded once from tests/Pest.php, before any test runs, so `class_exists()`
 * gives every test in the run the same answer. A fixture that declared itself
 * lazily would make the result depend on file order.
 */

namespace Goldnead\Activity\Facades;

class Activity
{
    /** @var list<array{type: string, attributes: array<string, mixed>}> */
    public static array $recorded = [];

    /** Set to throw, to prove a failing ledger cannot fail the write. */
    public static ?\Throwable $throws = null;

    /** @param  array<string, mixed>  $attributes */
    public static function record(string $eventType, array $attributes = []): void
    {
        if (self::$throws) {
            throw self::$throws;
        }

        self::$recorded[] = ['type' => $eventType, 'attributes' => $attributes];
    }

    public static function forget(): void
    {
        self::$recorded = [];
        self::$throws = null;
    }
}
