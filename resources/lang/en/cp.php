<?php

return [

    'nav' => 'Entitlements',
    'title' => 'Entitlements',

    'permission_view' => 'View entitlements',
    'permission_grant' => 'Grant entitlements',
    'permission_revoke' => 'Revoke entitlements',

    'states' => 'States',
    'state_pending' => 'Pending',
    'state_scheduled' => 'Scheduled',
    'state_active' => 'Active',
    'state_grace_period' => 'Grace period',
    'state_expired' => 'Expired',
    'state_revoked' => 'Revoked',

    'col_product' => 'Product',
    'col_subject' => 'Subject',
    'col_state' => 'State',
    'col_source' => 'Source',
    'col_expires' => 'Expires',

    'filter_state' => 'State',
    'filter_source' => 'Source',
    'filter_product' => 'Product slug',
    'filter_product_placeholder' => 'Exact slug',
    'filter_any' => 'Any',

    'create_grant' => 'Grant access',
    'view' => 'View',
    'back_to_entitlements' => 'All entitlements',

    'empty_heading' => 'Nothing has been granted yet. An entitlement records that one subject may use one product, where that permission came from and for how long.',
    'empty_create_description' => 'Grant access to a product by hand, with a source and an audit trail.',
    'setup_required_heading' => 'This page needs its database tables, and they are not there yet.',
    'setup_required_description' => 'Run `php artisan migrate` and the page loads as usual. The reason is in the log as well.',

    'empty_docs_heading' => 'Read the documentation',
    'empty_docs_description' => 'The state machine, the four events and the extension points.',

    'tab_grant' => 'Grant',
    'tab_window' => 'Window',
    'tab_revoke' => 'Revocation',

    'section_subject' => 'Subject',
    'section_subject_instructions' => 'Who this grant belongs to. The type is a morph alias such as `user` or `contact`; the id is that record’s key.',
    'section_grant' => 'What is being granted',
    'section_window_instructions' => 'Leave both empty for access that starts now and never ends.',
    'section_revoke_instructions' => 'Revoking takes access away immediately and records who did it and why. It can be undone, but the record stays.',

    'field_subject_type' => 'Subject type',
    'field_subject_type_instructions' => 'A morph alias, e.g. `user`.',
    'field_subject_id' => 'Subject id',
    'field_subject_id_instructions' => 'The record’s primary key.',
    'field_product_slug' => 'Product slug',
    'field_product_slug_instructions' => 'A free identifier. This package has no product catalogue; the slug is whatever your application calls the thing.',
    'field_source_ref' => 'Source reference',
    'field_source_ref_instructions' => 'Optional. An order id, a payment id — anything that identifies where this grant came from. Two grants that agree on subject, product, source and reference are the same grant.',
    'field_starts_at' => 'Starts',
    'field_starts_at_instructions' => 'A date in the future makes this a scheduled grant. It grants nothing until then, and becomes active by itself.',
    'field_expires_at' => 'Expires',
    'field_expires_at_instructions' => 'Access ends at this instant.',
    'field_reason' => 'Reason',
    'field_reason_instructions' => 'Required. Six months from now this is the only thing that explains the revocation.',

    'revoke_title' => 'Revoke access to :product',
    'revoke' => 'Revoke access',
    'restore' => 'Restore access',
    'restore_confirm_title' => 'Restore access?',
    'restore_confirm_body' => 'This grant becomes active again. The revocation stays in the record.',

    'grant_details' => 'Grant',
    'timeline' => 'Timeline',
    'timeline_created' => 'Created',
    'timeline_starts' => 'Starts',
    'timeline_expires' => 'Expires',
    'timeline_grace' => 'Grace until',
    'timeline_revoked' => 'Revoked',

    'subject' => 'Subject',
    'product' => 'Product',
    'source' => 'Source',
    'source_ref' => 'Source reference',
    'stored_status' => 'Stored status',
    'stored_status_note' => 'The state above is resolved from this status and the dates. Scheduled and expired are never stored.',
    'revocation_reason' => 'Reason for revocation',
    'grants_access_yes' => 'Grants access right now',
    'grants_access_no' => 'Grants no access',
    'no_dates' => 'No dates recorded.',

];
