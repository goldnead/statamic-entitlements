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
    | Limits
    |--------------------------------------------------------------------------
    |
    | A grant says yes or no. A limit says how many: 50 analyses per year, 10
    | arrangements at a time. Limits hang off a product slug, like grants.
    |
    | `keys` declares the limits a site knows, with a label and a period:
    | `month` or `year` for a usage limit this addon counts (consume()), none
    | for a stock limit the caller counts (withinLimit()).
    |
    | `products` sets limits in code: slug => [key => number|null]. null is
    | unlimited, 0 is none. What is stored in the Control Panel wins per key.
    |
    | `fallback_product` applies to everybody without a grant that carries the
    | key: a free plan. Null means no grant, no limit (0).
    |
    | `period_anchor`: `grant` starts a yearly period on the day the plan was
    | bought, `calendar` on 1 January (application timezone).
    |
    */

    'limits' => [
        'keys' => [
            // 'analyses' => ['label' => 'Analyses', 'period' => 'year'],
            // 'arrangements' => ['label' => 'Active arrangements'],
        ],
        'products' => [
            // 'free' => ['analyses' => 10, 'arrangements' => 3],
        ],
        'fallback_product' => null,
        'period_anchor' => 'grant',
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail
    |--------------------------------------------------------------------------
    |
    | Off by default: this addon decided who may access what and sent nothing,
    | and an update must not start mailing customers on its own. Switch it on
    | per brand on the settings page. The text is an email-templates template
    | (`template`) when that addon is installed, the bundled view otherwise.
    |
    */

    'mail' => [
        'limit_reached' => [
            'enabled' => false,
            'template' => 'entitlements-limit-reached',
        ],
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

        // Offers four figures — grants begun, withdrawn, expired, and the live
        // holding — to goldnead/statamic-insights. Off means the figures are
        // not registered at all, which is different from registering a zero:
        // an installation that has the analytics addon for something else does
        // not get four tiles it never asked for.
        'insights' => true,

        // Registers the limit and usage events as automation triggers. The
        // grant events are registered by statamic-automations itself.
        'automations' => true,

        // Registers all eight events as Webhook Manager triggers.
        'webhook_manager' => true,

        // Renders mails from statamic-email-templates when it is installed.
        'email_templates' => true,
    ],

];
