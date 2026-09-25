<?php

return [
    'granted' => [
        'label' => 'Entitlements: Zugang erteilt',
        'description' => 'Ein Zugang ist aktiv geworden, beim Anlegen oder nach einer Bestätigung.',
    ],
    'pending' => [
        'label' => 'Entitlements: Zugang wartet auf Bestätigung',
        'description' => 'Ein Zugang ist angelegt und wartet auf die Bestätigung (Double-Opt-in).',
    ],
    'renewed' => [
        'label' => 'Entitlements: Zugang verlängert',
        'description' => 'Das Ende eines Zugangs ist nach hinten gerückt.',
    ],
    'revoked' => [
        'label' => 'Entitlements: Zugang entzogen',
        'description' => 'Ein Zugang wurde mit Begründung entzogen.',
    ],
    'expired' => [
        'label' => 'Entitlements: Zugang abgelaufen',
        'description' => 'Ein Zugang ist abgelaufen, auch nach einer Kulanzfrist.',
    ],
    'limit_reached' => [
        'label' => 'Entitlements: Grenze erreicht',
        'description' => 'Ein Kontingent ist ausgeschöpft. Einmal je Zeitraum, beim Buchen, das die Grenze erreicht.',
    ],
    'usage_consumed' => [
        'label' => 'Entitlements: Verbrauch gebucht',
        'description' => 'Eine Buchung auf ein Kontingent ist durchgegangen.',
    ],
    'usage_reset' => [
        'label' => 'Entitlements: Verbrauch zurückgesetzt',
        'description' => 'Ein Zähler beginnt wieder bei null: neuer Zeitraum oder von Hand zurückgesetzt.',
    ],

    'field_key' => 'Kontingent',
    'field_key_help' => 'Nur für diesen Schlüssel, etwa `analyses`. Leer lassen für alle.',
    'field_product' => 'Produkt',
    'field_product_help' => 'Nur für Grenzen aus diesem Produkt (Slug). Leer lassen für alle.',
];
