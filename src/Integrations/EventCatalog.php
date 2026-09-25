<?php

namespace Goldnead\Entitlements\Integrations;

use Goldnead\Entitlements\Events\EntitlementExpired;
use Goldnead\Entitlements\Events\EntitlementGranted;
use Goldnead\Entitlements\Events\EntitlementPending;
use Goldnead\Entitlements\Events\EntitlementRenewed;
use Goldnead\Entitlements\Events\EntitlementRevoked;
use Goldnead\Entitlements\Events\LimitReached;
use Goldnead\Entitlements\Events\UsageConsumed;
use Goldnead\Entitlements\Events\UsageReset;

/**
 * Every event this addon fires, once, for everything that needs the list:
 * the Webhook Manager bridge, the automations bridge and the Control Panel
 * page that shows what is wired to what.
 *
 * The handle is `entitlements.<moment>` everywhere. The five grant events
 * already have automation triggers of that name, registered by
 * statamic-automations itself; this addon registers only the three limit
 * events there, so no handle is ever claimed twice.
 */
final class EventCatalog
{
    public const PREFIX = 'entitlements.';

    /**
     * moment => [event class, who registers the automation trigger].
     *
     * @var array<string, array{0: class-string, 1: 'automations'|'entitlements'}>
     */
    public const MOMENTS = [
        'granted' => [EntitlementGranted::class, 'automations'],
        'pending' => [EntitlementPending::class, 'automations'],
        'renewed' => [EntitlementRenewed::class, 'automations'],
        'revoked' => [EntitlementRevoked::class, 'automations'],
        'expired' => [EntitlementExpired::class, 'automations'],
        'limit_reached' => [LimitReached::class, 'entitlements'],
        'usage_consumed' => [UsageConsumed::class, 'entitlements'],
        'usage_reset' => [UsageReset::class, 'entitlements'],
    ];

    /**
     * The mail this addon sends for a moment: moment => config path of the
     * template slug. Only one so far.
     *
     * @var array<string, string>
     */
    public const MAILS = [
        'limit_reached' => 'entitlements.mail.limit_reached',
    ];

    public static function handle(string $moment): string
    {
        return self::PREFIX.$moment;
    }

    /** @return array<string, string> handle => event class */
    public static function handles(): array
    {
        $handles = [];

        foreach (self::MOMENTS as $moment => [$class]) {
            $handles[self::handle($moment)] = $class;
        }

        return $handles;
    }

    public static function momentOf(object $event): ?string
    {
        foreach (self::MOMENTS as $moment => [$class]) {
            if ($event instanceof $class) {
                return $moment;
            }
        }

        return null;
    }
}
