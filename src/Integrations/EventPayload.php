<?php

namespace Goldnead\Entitlements\Integrations;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Entitlements\Events\EntitlementExpired;
use Goldnead\Entitlements\Events\EntitlementGranted;
use Goldnead\Entitlements\Events\EntitlementPending;
use Goldnead\Entitlements\Events\EntitlementRenewed;
use Goldnead\Entitlements\Events\EntitlementRevoked;
use Goldnead\Entitlements\Events\LimitReached;
use Goldnead\Entitlements\Events\UsageConsumed;
use Goldnead\Entitlements\Events\UsageReset;
use Goldnead\Entitlements\Limits\LimitCatalog;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\IdentityContracts\Identity;
use Throwable;

/**
 * What a receiver is told about an entitlements event, and nothing more.
 *
 * A list, never a model's `toArray()`: `meta` on a grant is whatever the
 * writing addon put there (a payment's thank-you token, an opt-in's IP), and a
 * column added next year must not reach every receiver without somebody
 * deciding it should. The actor is its type, id and name — no email: an
 * operator's address is not the customer's business.
 *
 * The shape is the suite's shared one (see statamic-payments):
 *
 *     {
 *       "event": "entitlements.limit_reached",
 *       "event_id": "sha1 of the handle and the moment's own parts",
 *       "occurred_at": "2026-09-25T10:12:03+00:00",
 *       "brand": {"id": 2, "handle": "nordlicht"} | null,
 *       "subject_type": "user", "subject_id": "42",
 *       "<block>": { ... }
 *     }
 *
 * Used by both bridges and loads without either sibling.
 */
final class EventPayload
{
    /** @var array<int, array{id: int, handle: string}|null> */
    private static array $brands = [];

    /**
     * @return array<string, mixed>
     */
    public static function build(string $moment, object $event): array
    {
        [$type, $id] = self::subjectOf($event);
        $at = self::occurredAt($event);

        return array_merge([
            'event' => EventCatalog::handle($moment),
            'event_id' => self::eventId($moment, $event),
            'occurred_at' => self::date($at),
            'brand' => self::brand(self::brandIdOf($event)),
            'subject_type' => $type,
            'subject_id' => $id,
        ], self::body($event));
    }

    /**
     * The same moment delivered twice gets the same id; a receiver that
     * de-duplicates on it drops the repeat. Built from the moment's own parts,
     * never from the time of sending.
     */
    public static function eventId(string $moment, object $event): string
    {
        $parts = match (true) {
            $event instanceof LimitReached, $event instanceof UsageConsumed => [
                $event->holder->key(), $event->key, self::date($event->periodStart) ?? 'stock',
                $event->used, self::date($event->occurredAt),
            ],
            $event instanceof UsageReset => [
                $event->holder->key(), $event->key, self::date($event->periodStart), $event->reason,
                self::date($event->occurredAt),
            ],
            property_exists($event, 'entitlement') && $event->entitlement instanceof Entitlement => [
                'entitlement:'.$event->entitlement->getKey(),
                self::date(self::occurredAt($event)),
            ],
            default => [spl_object_id($event)],
        };

        return sha1(implode('|', [EventCatalog::handle($moment), ...array_map('strval', $parts)]));
    }

    public static function occurredAt(object $event): DateTimeInterface
    {
        if (property_exists($event, 'occurredAt') && $event->occurredAt instanceof DateTimeInterface) {
            return $event->occurredAt;
        }

        if ($event instanceof EntitlementExpired && $event->grantedAccessUntil !== null) {
            return $event->grantedAccessUntil;
        }

        if ($event instanceof EntitlementRevoked && $event->entitlement->revoked_at !== null) {
            return $event->entitlement->revoked_at;
        }

        if (property_exists($event, 'entitlement') && $event->entitlement instanceof Entitlement) {
            $updated = $event->entitlement->getAttribute('updated_at');

            if ($updated !== null) {
                return CarbonImmutable::parse($updated)->utc();
            }
        }

        return CarbonImmutable::now('UTC');
    }

    /** @return array{0: string|null, 1: string|null} */
    public static function subjectOf(object $event): array
    {
        if ($event instanceof LimitReached || $event instanceof UsageConsumed) {
            return [$event->subject->type, $event->subject->id];
        }

        if ($event instanceof UsageReset) {
            return [$event->holder->type, $event->holder->id];
        }

        if (property_exists($event, 'entitlement') && $event->entitlement instanceof Entitlement) {
            return [$event->entitlement->subject_type, $event->entitlement->subject_id];
        }

        return [null, null];
    }

    /** What the webhook manager files the delivery under. */
    public static function referenceOf(object $event): ?string
    {
        if (property_exists($event, 'entitlement') && $event->entitlement instanceof Entitlement) {
            return (string) $event->entitlement->getKey();
        }

        if ($event instanceof LimitReached || $event instanceof UsageConsumed || $event instanceof UsageReset) {
            return $event->holder->key().':'.$event->key;
        }

        return null;
    }

    public static function brandIdOf(object $event): ?int
    {
        if (property_exists($event, 'brandId') && is_int($event->brandId)) {
            return $event->brandId;
        }

        if (property_exists($event, 'entitlement') && $event->entitlement instanceof Entitlement) {
            return (int) $event->entitlement->brand_id ?: null;
        }

        return null;
    }

    /** @return array{id: int, handle: string}|null */
    public static function brand(?int $id): ?array
    {
        if ($id === null || $id < 1) {
            return null;
        }

        if (array_key_exists($id, self::$brands)) {
            return self::$brands[$id];
        }

        try {
            $brand = Brand::query()->find($id);
            $answer = $brand === null ? null : ['id' => (int) $brand->getKey(), 'handle' => (string) $brand->getAttribute('handle')];
        } catch (Throwable) {
            $answer = null;
        }

        return self::$brands[$id] = $answer;
    }

    public static function forgetBrands(): void
    {
        self::$brands = [];
    }

    /**
     * The flat context an automation run starts with. Same blocks as the
     * webhook body, without the envelope.
     *
     * @return array<string, mixed>
     */
    public static function context(object $event): array
    {
        [$type, $id] = self::subjectOf($event);

        return array_merge(
            ['subject' => ['type' => $type, 'id' => $id]],
            self::body($event),
        );
    }

    /** @return array<string, mixed> */
    private static function body(object $event): array
    {
        return match (true) {
            $event instanceof LimitReached => [
                'holder' => ['type' => $event->holder->type, 'id' => $event->holder->id],
                'limit' => self::limitBlock($event->key, $event->kind, $event->limit, $event->used, $event->product, $event->period, $event->periodStart, $event->periodEnd),
            ],
            $event instanceof UsageConsumed => [
                'holder' => ['type' => $event->holder->type, 'id' => $event->holder->id],
                'amount' => $event->amount,
                'limit' => self::limitBlock($event->key, 'usage', $event->limit, $event->used, $event->product, $event->period, $event->periodStart, $event->periodEnd),
            ],
            $event instanceof UsageReset => [
                'holder' => ['type' => $event->holder->type, 'id' => $event->holder->id],
                'reset' => [
                    'key' => $event->key,
                    'label' => self::label($event->key),
                    'previous' => $event->previous,
                    'reason' => $event->reason,
                    'period' => $event->period,
                    'period_start' => self::date($event->periodStart),
                    'period_end' => self::date($event->periodEnd),
                ],
                'actor' => self::actor($event->actor),
            ],
            $event instanceof EntitlementGranted, $event instanceof EntitlementPending => [
                'entitlement' => self::entitlement($event->entitlement),
                'previous_state' => $event->previousState?->value,
                'actor' => self::actor($event->actor),
            ],
            $event instanceof EntitlementRenewed => [
                'entitlement' => self::entitlement($event->entitlement),
                'previous_expires_at' => self::date($event->previousExpiresAt),
                'actor' => self::actor($event->actor),
            ],
            $event instanceof EntitlementRevoked => [
                'entitlement' => self::entitlement($event->entitlement),
                'reason' => $event->reason,
                'previous_state' => $event->previousState?->value,
                'actor' => self::actor($event->actor),
            ],
            $event instanceof EntitlementExpired => [
                'entitlement' => self::entitlement($event->entitlement),
                'access_until' => self::date($event->grantedAccessUntil),
            ],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    public static function entitlement(Entitlement $entitlement): array
    {
        return [
            'id' => $entitlement->getKey(),
            'product' => $entitlement->product_slug,
            'source' => $entitlement->source,
            'source_ref' => $entitlement->hasSourceRef() ? $entitlement->source_ref : null,
            'state' => $entitlement->state()->value,
            'subject_type' => $entitlement->subject_type,
            'subject_id' => $entitlement->subject_id,
            'starts_at' => self::date($entitlement->starts_at),
            'expires_at' => self::date($entitlement->expires_at),
            'grace_until' => self::date($entitlement->grace_until),
            'revoked_at' => self::date($entitlement->revoked_at),
            'revoked_reason' => $entitlement->revoked_reason,
        ];
    }

    /** @return array<string, mixed> */
    private static function limitBlock(string $key, string $kind, ?int $limit, int $used, ?string $product, ?string $period, ?DateTimeInterface $start, ?DateTimeInterface $end): array
    {
        return [
            'key' => $key,
            'label' => self::label($key),
            'kind' => $kind,
            'limit' => $limit,
            'unlimited' => $limit === null,
            'used' => $used,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'product' => $product,
            'period' => $period,
            'period_start' => self::date($start),
            'period_end' => self::date($end),
        ];
    }

    /** @return array{type: string, id: string|null, name: string|null}|null */
    private static function actor(?Identity $actor): ?array
    {
        return $actor === null ? null : ['type' => $actor->type, 'id' => $actor->id, 'name' => $actor->name];
    }

    private static function label(string $key): string
    {
        try {
            return app(LimitCatalog::class)->label($key);
        } catch (Throwable) {
            return $key;
        }
    }

    public static function date(mixed $value): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return CarbonImmutable::instance($value)->utc()->format(DATE_ATOM);
    }
}
