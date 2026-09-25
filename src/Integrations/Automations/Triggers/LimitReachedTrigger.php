<?php

namespace Goldnead\Entitlements\Integrations\Automations\Triggers;

use Goldnead\Entitlements\Events\LimitReached;

/** A limit is full: the place for an upgrade offer. */
class LimitReachedTrigger extends QuotaTrigger
{
    protected static function eventClass(): string
    {
        return LimitReached::class;
    }

    protected static function moment(): string
    {
        return 'limit_reached';
    }
}
