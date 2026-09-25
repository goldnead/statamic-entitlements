<?php

namespace Goldnead\Entitlements\Integrations\Automations\Triggers;

use Goldnead\Entitlements\Events\UsageConsumed;

/** A booking against a usage limit went through. */
class UsageConsumedTrigger extends QuotaTrigger
{
    protected static function eventClass(): string
    {
        return UsageConsumed::class;
    }

    protected static function moment(): string
    {
        return 'usage_consumed';
    }
}
