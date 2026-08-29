<?php

/**
 * Die Beschriftungen der Kennzahlen, die dieses Addon an `statamic-insights`
 * meldet.
 *
 * Eigene Datei statt eines Anbaus an `cp.php`: die Kennzahlen erscheinen in
 * einem anderen Addon, nicht im Control Panel dieses Addons, und eine Datei,
 * die man mitnimmt, wenn man die Anbindung wieder ausbaut, ist die ehrlichere
 * Ablage.
 */
return [

    'metric_group' => 'Zugänge',

    'metric_granted' => 'Erteilte Zugänge',
    'metric_granted_description' => 'Zugänge, deren Zugangsfenster im Zeitraum begonnen hat. Ein noch unbestätigter Zugang hat keinen Beginn und zählt erst, wenn er bestätigt wird.',

    'metric_revoked' => 'Entzogene Zugänge',
    'metric_revoked_description' => 'Zugänge, die im Zeitraum entzogen wurden, gezählt am Tag des Entzugs.',

    'metric_expired' => 'Abgelaufene Zugänge',
    'metric_expired_description' => 'Zugänge, deren Ablaufdatum im Zeitraum lag und die vorher nicht entzogen wurden.',

    'metric_active' => 'Aktive Zugänge',
    'metric_active_description' => 'Bestand am Ende des Zeitraums: begonnen, nicht abgelaufen, nicht entzogen. Kein Ereignis, sondern ein Stand.',

    'metric_breakdown_product' => 'Produkt',
    'metric_breakdown_source' => 'Quelle',
    'metric_breakdown_reason' => 'Grund',

    'metric_no_product' => 'Ohne Produkt',
    'metric_no_source' => 'Ohne Quelle',
    'metric_no_reason' => 'Ohne Grund',

];
