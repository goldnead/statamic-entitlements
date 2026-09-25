<?php

return [
    'limit_reached' => [
        'title' => 'Entitlements: Grenze erreicht',
        'description' => 'Geht an die Person, die ein Kontingent ausgeschöpft hat. Platzhalter: name, limit_label, limit, used, product, period_end, period_note, app_name.',
        'subject' => '{{ limit_label }}: Grenze erreicht',
        'preview' => 'Du hast {{ used }} von {{ limit }} genutzt.',
        'body' => '<p>Hallo {{ name }},</p>'
            .'<p>du hast bei <strong>{{ limit_label }}</strong> die Grenze deines Zugangs erreicht: {{ used }} von {{ limit }}.</p>'
            .'<p>{{ period_note }} Wenn du mehr brauchst, antworte auf diese Mail.</p>'
            .'<p>{{ app_name }}</p>',
        'note_usage' => 'Ab dem :date steht dir das Kontingent wieder zur Verfügung.',
        'note_stock' => 'Sobald du etwas entfernst, ist wieder Platz.',
    ],
];
