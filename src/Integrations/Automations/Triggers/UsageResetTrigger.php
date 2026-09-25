<?php

namespace Goldnead\Entitlements\Integrations\Automations\Triggers;

use Goldnead\Entitlements\Events\UsageReset;

/** A usage counter starts from zero: a new period, or reset by hand. */
class UsageResetTrigger extends QuotaTrigger
{
    protected static function eventClass(): string
    {
        return UsageReset::class;
    }

    protected static function moment(): string
    {
        return 'usage_reset';
    }

    public static function outputSchema(): array
    {
        return [
            'subject' => ['type' => 'string', 'id' => 'string'],
            'holder' => ['type' => 'string', 'id' => 'string'],
            'reset' => [
                'key' => 'string', 'label' => 'string', 'previous' => 'integer', 'reason' => 'string',
                'period' => 'string', 'period_start' => 'datetime', 'period_end' => 'datetime',
            ],
        ];
    }
}
