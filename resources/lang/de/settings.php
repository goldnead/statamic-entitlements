<?php

/*
 * Die Beschriftungen der Einstellungsseite.
 *
 * Feldschlüssel sind der Config-Pfad mit flachgelegten Punkten
 * (`cp.per_page` → `cp_per_page`).
 */

return [

    'permission_manage' => 'Entitlement-Einstellungen verwalten',

    'groups' => [

        'cp' => [
            'title' => 'Control Panel',
            'description' => 'Wie sich die Freigabe-Listen und das Formular für eine Freigabe von Hand verhalten. Ob das Control Panel dieses Addons überhaupt erreichbar ist, steht weiterhin in der Datei config/entitlements.php: der Schalter wird beim Registrieren der Routen und beim Aufbau der Navigation gelesen, wirkte hier also erst beim nächsten Deploy.',
        ],

        'bridges' => [
            'title' => 'Nachbar-Addons',
            'description' => 'Was dieses Addon an andere weitergibt. Die Brücke zum Aktivitätsprotokoll steht weiterhin nur in der Config: sie hängt sich beim Booten ein und merkt sich das, ein späteres Abschalten löst die eingehängten Listener nicht wieder.',
        ],

    ],

    'fields' => [

        'cp_per_page' => [
            'label' => 'Zeilen je Seite',
            'description' => 'So viele Freigaben zeigt eine Listenseite, solange niemand im Control Panel etwas anderes wählt. Gilt ab dem nächsten Seitenaufruf.',
        ],

        'manual_subject_types' => [
            'label' => 'Erlaubte Subjekt-Typen',
            'description' => 'Ein Morph-Typ je Zeile. Steht hier etwas, bietet das Formular für eine Freigabe von Hand nur noch diese Typen an. Leer heißt: freier Text, und ein Tippfehler erzeugt dann eine Freigabe, die niemandem gehört und erst auffällt, wenn sich jemand beschwert.',
        ],

        'bridges_insights' => [
            'label' => 'Kennzahlen an Insights geben',
            'description' => 'Aus heißt: die vier Kennzahlen erscheinen im Insights-Dashboard gar nicht mehr. Das ist etwas anderes als eine Null: eine Installation, die Insights für etwas anderes hat, bekommt keine vier Kacheln, um die sie nie gebeten hat.',
        ],

    ],

];
