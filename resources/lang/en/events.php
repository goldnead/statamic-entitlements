<?php

return [
    'granted' => [
        'label' => 'Entitlements: access granted',
        'description' => 'A grant became active, on creation or after a confirmation.',
    ],
    'pending' => [
        'label' => 'Entitlements: access awaiting confirmation',
        'description' => 'A grant was written and waits for its confirmation (double opt-in).',
    ],
    'renewed' => [
        'label' => 'Entitlements: access renewed',
        'description' => 'The end of a grant moved later.',
    ],
    'revoked' => [
        'label' => 'Entitlements: access revoked',
        'description' => 'A grant was taken away, with a reason.',
    ],
    'expired' => [
        'label' => 'Entitlements: access expired',
        'description' => 'A grant ran out, including after a grace period.',
    ],
    'limit_reached' => [
        'label' => 'Entitlements: limit reached',
        'description' => 'A limit is used up. Once per period, from the booking that reaches it.',
    ],
    'usage_consumed' => [
        'label' => 'Entitlements: usage booked',
        'description' => 'A booking against a limit went through.',
    ],
    'usage_reset' => [
        'label' => 'Entitlements: usage reset',
        'description' => 'A counter starts from zero: a new period, or reset by hand.',
    ],

    'field_key' => 'Limit',
    'field_key_help' => 'Only this key, for example `analyses`. Leave empty for all.',
    'field_product' => 'Product',
    'field_product_help' => 'Only limits from this product (slug). Leave empty for all.',
];
