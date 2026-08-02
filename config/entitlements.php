<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Control Panel
    |--------------------------------------------------------------------------
    |
    | The kill switch bites on the nav entry *and* on the routes — see
    | routes/cp.php. Hiding the entry while leaving the screens reachable by URL
    | is not a disabled Control Panel.
    |
    */

    'cp' => [
        'enabled' => true,
        'per_page' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Grant sources
    |--------------------------------------------------------------------------
    |
    | Display names only. The `source` column is a free string and this list is
    | never a whitelist: an unregistered source writes, resolves and grants
    | access exactly like a registered one, it just shows its raw handle.
    |
    | An enum was considered and rejected. Sources differ per project — one
    | install has ThriveCart and Mollie, the next has neither — and an enum in a
    | shared package forces every consumer to extend it before they can record
    | their own purchase.
    |
    | `manual` is the source the Control Panel writes. Keep it.
    |
    */

    'sources' => [
        'manual' => 'Manual grant',
    ],

    /*
    |--------------------------------------------------------------------------
    | The manual grant
    |--------------------------------------------------------------------------
    |
    | `subject_types` is the list of morph types the Control Panel form offers.
    | Empty means the form accepts any type as free text, which is right for a
    | first install and wrong for a large one — a typo in a morph type produces
    | a grant that belongs to nobody and is only found by somebody complaining.
    |
    */

    'manual' => [
        'source' => 'manual',
        'subject_types' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional sibling bridges
    |--------------------------------------------------------------------------
    |
    | Never Composer requirements — each one attaches only when the sibling
    | addon is actually installed (class_exists). This package must be fully
    | functional with none of them present, and a test proves it.
    |
    */

    'bridges' => [
        'activity' => true,
    ],

];
