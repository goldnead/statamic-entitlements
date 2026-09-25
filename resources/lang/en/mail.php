<?php

return [
    'limit_reached' => [
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
