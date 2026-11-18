<?php

namespace Goldnead\Entitlements\Support;

use Goldnead\Entitlements\Models\Entitlement;
use RuntimeException;
use Throwable;

/**
 * A listener or a sibling bridge threw while this package was announcing
 * something, and the package chose to carry on.
 *
 * Carrying on is right (a mail that fails must not cost a grant or a booking),
 * but it must not be silent. This wraps the original exception so `report()`
 * sends it, stack trace intact, to whatever the application reports to, with
 * the ids somebody needs to find the row again: {@see context()} is merged into
 * the log entry by Laravel's exception handler.
 */
final class ListenerFailed extends RuntimeException
{
    /** @param array<string, scalar|null> $context */
    private function __construct(string $message, private readonly array $context, Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  string  $where  what this package was doing, in a few words
     * @param  array<string, scalar|null>  $ids  further ids the caller knows
     */
    public static function while(string $where, ?object $event, Throwable $previous, array $ids = []): self
    {
        $context = ['where' => $where];

        if ($event !== null) {
            $context['event'] = $event::class;

            if (property_exists($event, 'entitlement') && $event->entitlement instanceof Entitlement) {
                $context['entitlement_id'] = $event->entitlement->getKey();
                $context['brand_id'] = $event->entitlement->brand_id;
            }

            if (property_exists($event, 'brandId') && is_int($event->brandId)) {
                $context['brand_id'] = $event->brandId;
            }

            if (property_exists($event, 'holder') && $event->holder instanceof SubjectReference) {
                $context['holder'] = $event->holder->key();
            }

            if (property_exists($event, 'key') && is_string($event->key)) {
                $context['limit_key'] = $event->key;
            }
        }

        $context = [...$context, ...$ids];

        return new self(
            sprintf('statamic-entitlements: %s failed: %s', $where, $previous->getMessage()),
            $context,
            $previous,
        );
    }

    /** @return array<string, scalar|null> */
    public function context(): array
    {
        return $this->context;
    }
}
