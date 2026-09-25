<?php

return [
    'placeholders' => [
        'name' => 'Name of the person who reached the limit',
        'limit_label' => 'Name of the limit',
        'limit' => 'The limit',
        'used' => 'How much is used',
        'product' => 'Product the limit comes from',
        'period_end' => 'End of the period (empty for a stock limit)',
        'period_note' => 'One sentence on when there is room again',
        'app_name' => 'Name of the site',
    ],

    'limit_reached' => [
        'trigger' => 'A limit is used up',
        'title' => 'Entitlements: limit reached',
        'description' => 'Sent to the person who used up a limit. Placeholders: name, limit_label, limit, used, product, period_end, period_note, app_name.',
        'subject' => '{{ limit_label }}: limit reached',
        'preview' => 'You have used {{ used }} of {{ limit }}.',
        'body' => '<p>Hi {{ name }},</p>'
            .'<p>you have reached the limit of your plan for <strong>{{ limit_label }}</strong>: {{ used }} of {{ limit }}.</p>'
            .'<p>{{ period_note }} If you need more, reply to this email.</p>'
            .'<p>{{ app_name }}</p>',
        'note_usage' => 'It is available again from :date.',
        'note_stock' => 'As soon as you remove something, there is room again.',
    ],
];
