<?php

namespace Goldnead\Entitlements\Bridges;

use Goldnead\Activity\Facades\Activity;
use Goldnead\Entitlements\Events\EntitlementExpired;
use Goldnead\Entitlements\Events\EntitlementGranted;
use Goldnead\Entitlements\Events\EntitlementPending;
use Goldnead\Entitlements\Events\EntitlementRevoked;
use Goldnead\Entitlements\Models\Entitlement;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;

/**
 * Writes this package's four domain events into `statamic-activity`'s ledger, if
 * that addon happens to be installed.
 *
 * Never a Composer requirement. A site that sells one course has to be able to
 * install entitlements without also installing an audit trail, and a test proves
 * the whole package works with no bridge present at all. The direction is one
 * way and stays that way: entitlements knows the name of activity's facade,
 * activity knows nothing about entitlements.
 *
 * On the boundary: activity is the ledger of what *happened*, so what crosses is
 * that access was granted, parked, taken away or ran out. The editing — the
 * forms, the listing, the filters — never does.
 *
 * ## Why attaching is harder than it looks
 *
 * Statamic calls `bootAddon()` from inside a `Statamic::booted()` callback,
 * which itself runs inside the application's boot phase. Nesting another
 * `$app->booted()` there does not defer anything: `Application::booted()` fires
 * its callback immediately once the app is booted, so a "later" registration
 * written that way runs at once — before whatever it was waiting for.
 *
 * So instead of trusting one moment, `attach()` is called from several and is
 * idempotent: the first call that finds the sibling present wins, every later
 * one returns immediately. It never caches a *negative* answer, because the
 * whole point is that an earlier attempt may have been too early.
 *
 * ## Why `class_exists` and not `method_exists`
 *
 * The check is `class_exists()` on the facade class, which asks whether the
 * package is installed. `method_exists()` on a facade always answers false —
 * a facade forwards through `__callStatic` and declares none of the methods it
 * appears to have — so a guard written that way disables itself permanently and
 * silently. When the question is really about a method, the answer is
 * `Facade::getFacadeRoot()` and an inspection of the instance.
 */
class ActivityBridge
{
    /**
     * True once the listeners are on the dispatcher.
     *
     * Static because the provider, the retry hooks and any test bed all reach
     * for the same bridge, and a per-instance flag would let each of them attach
     * its own copy — which is how one revocation ends up in the ledger three
     * times.
     */
    private static bool $attached = false;

    /**
     * Attaches once and reports whether the listeners are now in place.
     *
     * Returns false — without recording anything — when the sibling addon is not
     * installed or the bridge is switched off. A later call can still succeed.
     */
    public static function attach(?Application $app = null): bool
    {
        if (self::$attached) {
            return true;
        }

        if (! config('entitlements.bridges.activity', true)) {
            return false;
        }

        if (! self::siblingIsPresent()) {
            return false;
        }

        Event::listen(EntitlementGranted::class, [self::class, 'handleGranted']);
        Event::listen(EntitlementPending::class, [self::class, 'handlePending']);
        Event::listen(EntitlementRevoked::class, [self::class, 'handleRevoked']);
        Event::listen(EntitlementExpired::class, [self::class, 'handleExpired']);

        self::$attached = true;

        return true;
    }

    public static function isAttached(): bool
    {
        return self::$attached;
    }

    /**
     * Test seam. Production never detaches — an addon that can be uninstalled at
     * runtime is not a thing.
     *
     * @internal
     */
    public static function forget(): void
    {
        self::$attached = false;
    }

    private static function siblingIsPresent(): bool
    {
        return class_exists(Activity::class);
    }

    public static function handleGranted(EntitlementGranted $event): void
    {
        self::record('entitlements.granted', $event->entitlement, [
            'previous_state' => $event->previousState?->value,
            'actor' => $event->actor?->jsonSerialize(),
        // The dedupe key carries the state it came from, so a grant that goes
        // Pending -> Active and a grant written straight to Active are two
        // distinct facts rather than one that overwrites the other.
        ], 'entitlements.granted:'.$event->entitlement->getKey().':'.($event->previousState?->value ?? 'new'));
    }

    public static function handlePending(EntitlementPending $event): void
    {
        self::record('entitlements.pending', $event->entitlement, [
            'actor' => $event->actor?->jsonSerialize(),
        ], 'entitlements.pending:'.$event->entitlement->getKey());
    }

    public static function handleRevoked(EntitlementRevoked $event): void
    {
        self::record('entitlements.revoked', $event->entitlement, [
            'reason' => $event->reason,
            'previous_state' => $event->previousState?->value,
            'actor' => $event->actor?->jsonSerialize(),
        ], 'entitlements.revoked:'.$event->entitlement->getKey());
    }

    public static function handleExpired(EntitlementExpired $event): void
    {
        self::record('entitlements.expired', $event->entitlement, [
            'granted_access_until' => $event->grantedAccessUntil?->toIso8601String(),
        ], 'entitlements.expired:'.$event->entitlement->getKey());
    }

    /**
     * A failing ledger must not fail the write that produced the fact.
     *
     * Granting access after a successful payment has to succeed even when the
     * optional addon next door is misconfigured, so anything thrown on the way
     * into the ledger is reported and swallowed. This is the one place in the
     * package where that is the right trade.
     *
     * `brand_id` is handed over explicitly rather than left to the ambient
     * context: a grant written by a webhook or a console command has no current
     * brand, and a fact filed under the wrong brand is worse than no fact.
     *
     * @param  array<string, mixed>  $properties
     */
    private static function record(string $type, Entitlement $entitlement, array $properties, string $dedupeKey): void
    {
        try {
            Activity::record($type, [
                'subject' => $entitlement,
                'brand_id' => $entitlement->getAttribute('brand_id'),
                'dedupe_key' => $dedupeKey,
                'properties' => array_filter([
                    'product_slug' => $entitlement->product_slug,
                    'source' => $entitlement->source,
                    'source_ref' => $entitlement->hasSourceRef() ? $entitlement->source_ref : null,
                    'subject_key' => $entitlement->subjectKey(),
                    'state' => $entitlement->state()->value,
                    ...$properties,
                ], fn ($value) => $value !== null),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
