<?php

return [

    'nav' => 'Berechtigungen',
    'title' => 'Berechtigungen',

    'permission_view' => 'Berechtigungen ansehen',
    'permission_grant' => 'Berechtigungen vergeben',
    'permission_revoke' => 'Berechtigungen widerrufen',

    'states' => 'Zustände',
    'state_pending' => 'Ausstehend',
    'state_scheduled' => 'Geplant',
    'state_active' => 'Aktiv',
    'state_grace_period' => 'Kulanzfrist',
    'state_expired' => 'Abgelaufen',
    'state_revoked' => 'Widerrufen',

    'col_product' => 'Produkt',
    'col_subject' => 'Subjekt',
    'col_state' => 'Zustand',
    'col_source' => 'Quelle',
    'col_expires' => 'Läuft ab',

    'filter_state' => 'Zustand',
    'filter_source' => 'Quelle',
    'filter_product' => 'Produkt-Slug',
    'filter_product_placeholder' => 'Exakter Slug',
    'filter_any' => 'Alle',

    'create_grant' => 'Zugriff vergeben',
    'view' => 'Ansehen',
    'back_to_entitlements' => 'Alle Berechtigungen',

    'empty_heading' => 'Es wurde noch nichts vergeben. Eine Berechtigung hält fest, dass ein Subjekt ein Produkt nutzen darf, woher diese Erlaubnis kommt und wie lange sie gilt.',
    'empty_create_description' => 'Zugriff von Hand vergeben, mit Quelle und nachvollziehbarer Spur.',
    'empty_docs_heading' => 'Dokumentation lesen',
    'empty_docs_description' => 'Die Zustandslogik, die vier Events und die Erweiterungspunkte.',

    'tab_grant' => 'Vergabe',
    'tab_window' => 'Zeitraum',
    'tab_revoke' => 'Widerruf',

    'section_subject' => 'Subjekt',
    'section_subject_instructions' => 'Wem diese Berechtigung gehört. Der Typ ist ein Morph-Alias wie `user` oder `contact`, die ID der Schlüssel des Datensatzes.',
    'section_grant' => 'Was vergeben wird',
    'section_window_instructions' => 'Beide Felder leer lassen für Zugriff, der sofort beginnt und nicht endet.',
    'section_revoke_instructions' => 'Ein Widerruf entzieht den Zugriff sofort und hält fest, wer ihn wann und warum ausgesprochen hat. Er lässt sich zurücknehmen, der Eintrag bleibt.',

    'field_subject_type' => 'Subjekt-Typ',
    'field_subject_type_instructions' => 'Ein Morph-Alias, z. B. `user`.',
    'field_subject_id' => 'Subjekt-ID',
    'field_subject_id_instructions' => 'Der Primärschlüssel des Datensatzes.',
    'field_product_slug' => 'Produkt-Slug',
    'field_product_slug_instructions' => 'Ein freier Bezeichner. Dieses Paket führt keinen Produktkatalog; der Slug ist das, was deine Anwendung darunter versteht.',
    'field_source_ref' => 'Quellenreferenz',
    'field_source_ref_instructions' => 'Optional. Eine Bestellnummer, eine Zahlungs-ID — irgendetwas, das diese Vergabe eindeutig macht. Zwei Berechtigungen mit gleichem Subjekt, Produkt, Quelle und Referenz sind dieselbe Berechtigung.',
    'field_starts_at' => 'Beginn',
    'field_starts_at_instructions' => 'Ein Datum in der Zukunft macht daraus eine geplante Berechtigung. Sie gewährt bis dahin nichts und wird von selbst aktiv.',
    'field_expires_at' => 'Ende',
    'field_expires_at_instructions' => 'Zu diesem Zeitpunkt endet der Zugriff.',
    'field_reason' => 'Grund',
    'field_reason_instructions' => 'Pflichtfeld. In sechs Monaten ist das das Einzige, was den Widerruf noch erklärt.',

    'revoke_title' => 'Zugriff auf :product widerrufen',
    'revoke' => 'Zugriff widerrufen',
    'restore' => 'Zugriff wiederherstellen',
    'restore_confirm_title' => 'Zugriff wiederherstellen?',
    'restore_confirm_body' => 'Diese Berechtigung wird wieder aktiv. Der Widerruf bleibt im Datensatz.',

    'grant_details' => 'Berechtigung',
    'timeline' => 'Verlauf',
    'timeline_created' => 'Angelegt',
    'timeline_starts' => 'Beginn',
    'timeline_expires' => 'Ende',
    'timeline_grace' => 'Kulanz bis',
    'timeline_revoked' => 'Widerrufen',

    'subject' => 'Subjekt',
    'product' => 'Produkt',
    'source' => 'Quelle',
    'source_ref' => 'Quellenreferenz',
    'stored_status' => 'Gespeicherter Status',
    'stored_status_note' => 'Der Zustand oben wird aus diesem Status und den Daten abgeleitet. Geplant und abgelaufen werden nie gespeichert.',
    'revocation_reason' => 'Grund des Widerrufs',
    'grants_access_yes' => 'Gewährt gerade Zugriff',
    'grants_access_no' => 'Gewährt keinen Zugriff',
    'no_dates' => 'Keine Daten hinterlegt.',

];
