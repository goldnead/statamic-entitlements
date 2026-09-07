<?php

/*
 * Labels for the settings screen.
 *
 * Field keys are the config path with the dots flattened
 * (`cp.per_page` → `cp_per_page`).
 */

return [

    'permission_manage' => 'Manage entitlement settings',

    'groups' => [

        'cp' => [
            'title' => 'Control Panel',
            'description' => 'How the grant listings and the manual grant form behave. Whether this addon\'s Control Panel is reachable at all stays in config/entitlements.php: that switch is read while the routes are registered and while the navigation is built, so it would only take effect on the next deploy.',
        ],

        'bridges' => [
            'title' => 'Sibling addons',
            'description' => 'What this addon hands on to others. The activity bridge stays in the config alone: it attaches while booting and remembers that it did, so switching it off later does not detach the listeners.',
        ],

    ],

    'fields' => [

        'cp_per_page' => [
            'label' => 'Rows per page',
            'description' => 'How many grants a listing shows until someone picks something else in the Control Panel. Applies from the next page load.',
        ],

        'manual_subject_types' => [
            'label' => 'Allowed subject types',
            'description' => 'One morph type per line. With anything here, the manual grant form offers only these types. Empty means free text, and a typo then produces a grant that belongs to nobody and is only found by somebody complaining.',
        ],

        'bridges_insights' => [
            'label' => 'Hand figures to Insights',
            'description' => 'Off means the four figures are not registered at all, which is different from registering a zero: an installation that has Insights for something else does not get four tiles it never asked for.',
        ],

    ],

];
