<?php

/**
 * Labels for the figures this addon offers `statamic-insights`.
 *
 * A file of its own rather than an annex to `cp.php`: these appear on another
 * addon's screen, not in this one's Control Panel, and a file you delete when
 * you remove the coupling is the more honest place for them.
 */
return [

    'metric_group' => 'Entitlements',

    'metric_granted' => 'Access granted',
    'metric_granted_description' => 'Grants whose access window began in the period. A grant still awaiting confirmation has no beginning and counts once it is confirmed.',

    'metric_revoked' => 'Access revoked',
    'metric_revoked_description' => 'Grants withdrawn in the period, counted on the day of the revocation.',

    'metric_expired' => 'Access expired',
    'metric_expired_description' => 'Grants whose expiry fell inside the period and which were not revoked before it.',

    'metric_active' => 'Active grants',
    'metric_active_description' => 'The holding at the end of the period: begun, not expired, not revoked. A stock, not an event.',

    'metric_breakdown_product' => 'Product',
    'metric_breakdown_source' => 'Source',
    'metric_breakdown_reason' => 'Reason',

    'metric_no_product' => 'No product',
    'metric_no_source' => 'No source',
    'metric_no_reason' => 'No reason given',

];
