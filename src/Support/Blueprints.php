<?php

namespace Goldnead\Entitlements\Support;

use Goldnead\Entitlements\Limits\LimitCatalog;
use Statamic\Facades\Blueprint;
use Statamic\Fields\Blueprint as BlueprintInstance;

/**
 * The blueprints behind the two Control Panel forms.
 *
 * Built in PHP rather than shipped as YAML because both option lists are
 * dynamic: the source list comes from a config registry plus whatever the data
 * already contains, and the subject-type list is per install.
 *
 * Both forms are rendered by `Statamic\CP\PublishForm`, which is `Responsable`
 * and renders core's own PublishForm Inertia page. That is the documented
 * zero-Vue path for a blueprint-driven form, and it means these two forms are
 * literally core's form rather than a copy that has to be kept looking like it.
 */
class Blueprints
{
    /**
     * The manual grant form.
     *
     * This form is the reason the Control Panel is in v1 at all. In the source
     * system, a manual grant was an entry appended to a JSON array column on the
     * user row — `manual_resource_grant_slugs` — with no source, no window, no
     * revocation and no history. Nobody could answer who had granted it or when.
     * Everything below exists to make those questions answerable.
     */
    public static function grant(): BlueprintInstance
    {
        return Blueprint::make()->setContents([
            'tabs' => [
                'main' => [
                    'display' => __('entitlements::cp.tab_grant'),
                    'sections' => [
                        [
                            'display' => __('entitlements::cp.section_subject'),
                            'instructions' => __('entitlements::cp.section_subject_instructions'),
                            'fields' => [
                                // A person by default, picked through core's
                                // own user search. Nobody should have to know
                                // that Anna is `App\Models\User:42`; the
                                // reference is derived from the user the same
                                // way every other write path derives it.
                                [
                                    'handle' => 'subject_kind',
                                    'field' => [
                                        'type' => 'button_group',
                                        'display' => __('entitlements::cp.field_subject_kind'),
                                        'options' => [
                                            'user' => __('entitlements::cp.subject_kind_user'),
                                            'other' => __('entitlements::cp.subject_kind_other'),
                                        ],
                                        'default' => 'user',
                                        'validate' => ['required', 'in:user,other'],
                                    ],
                                ],
                                [
                                    'handle' => 'subject_user',
                                    'field' => [
                                        'type' => 'users',
                                        'display' => __('entitlements::cp.field_subject_user'),
                                        'instructions' => __('entitlements::cp.field_subject_user_instructions'),
                                        'max_items' => 1,
                                        'mode' => 'select',
                                        'if' => ['subject_kind' => 'equals user'],
                                        'validate' => ['required_if:subject_kind,user'],
                                    ],
                                ],
                                [
                                    'handle' => 'subject_type',
                                    'field' => self::subjectTypeField(),
                                ],
                                [
                                    'handle' => 'subject_id',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('entitlements::cp.field_subject_id'),
                                        'instructions' => __('entitlements::cp.field_subject_id_instructions'),
                                        'width' => 50,
                                        'if' => ['subject_kind' => 'equals other'],
                                        'validate' => ['required_if:subject_kind,other', 'max:64'],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'display' => __('entitlements::cp.section_grant'),
                            'fields' => [
                                [
                                    'handle' => 'product_slug',
                                    'field' => self::productField(
                                        __('entitlements::cp.field_product_slug_instructions'),
                                    ),
                                ],
                                [
                                    'handle' => 'source_ref',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('entitlements::cp.field_source_ref'),
                                        'instructions' => __('entitlements::cp.field_source_ref_instructions'),
                                        'validate' => ['nullable', 'max:191'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'sidebar' => [
                    'display' => __('entitlements::cp.tab_window'),
                    'sections' => [
                        [
                            'instructions' => __('entitlements::cp.section_window_instructions'),
                            'fields' => [
                                [
                                    'handle' => 'starts_at',
                                    'field' => [
                                        'type' => 'date',
                                        'display' => __('entitlements::cp.field_starts_at'),
                                        // A start date in the future is a Scheduled
                                        // grant, which is a supported thing to
                                        // create and not an error. The source
                                        // system reported those as expired.
                                        'instructions' => __('entitlements::cp.field_starts_at_instructions'),
                                        'time_enabled' => true,
                                    ],
                                ],
                                [
                                    'handle' => 'expires_at',
                                    'field' => [
                                        'type' => 'date',
                                        'display' => __('entitlements::cp.field_expires_at'),
                                        'instructions' => __('entitlements::cp.field_expires_at_instructions'),
                                        'time_enabled' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * The revocation form.
     *
     * A whole form for one field, because that field must not be optional. A
     * revocation without a recorded reason is the one an anxious support person
     * quietly undoes six months later, and the source system could not record
     * one at all — it had no revocation path whatsoever.
     */
    public static function revocation(): BlueprintInstance
    {
        return Blueprint::make()->setContents([
            'tabs' => [
                'main' => [
                    'display' => __('entitlements::cp.tab_revoke'),
                    'sections' => [
                        [
                            'instructions' => __('entitlements::cp.section_revoke_instructions'),
                            'fields' => [
                                [
                                    'handle' => 'reason',
                                    'field' => [
                                        'type' => 'textarea',
                                        'display' => __('entitlements::cp.field_reason'),
                                        'instructions' => __('entitlements::cp.field_reason_instructions'),
                                        'validate' => ['required', 'max:255'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * The limits of one product: a grid of key, number and period.
     *
     * "Unlimited" is its own toggle rather than an empty number: an empty field
     * is what somebody leaves by accident, and reading it as unlimited would
     * give a plan everything because a cell was not filled in. The product slug
     * is only asked for when creating; renaming it would silently detach every
     * grant that carries the old slug.
     */
    public static function limits(bool $creating): BlueprintInstance
    {
        $fields = [];

        if ($creating) {
            $fields[] = [
                'handle' => 'product_slug',
                'field' => self::productField(
                    __('entitlements::cp.limits_product_instructions'),
                ),
            ];
        }

        $keys = collect(app(LimitCatalog::class)->keys())
            ->map(fn (array $key, string $handle) => $key['label'] === $handle ? $handle : $key['label'].' ('.$handle.')')
            ->implode(', ');

        $fields[] = [
            'handle' => 'limits',
            'field' => [
                'type' => 'grid',
                'display' => __('entitlements::cp.limits_title'),
                'instructions' => $keys === ''
                    ? __('entitlements::cp.limits_grid_instructions')
                    : __('entitlements::cp.limits_grid_instructions_keys', ['keys' => $keys]),
                'mode' => 'table',
                'add_row' => __('entitlements::cp.limits_add_row'),
                'reorderable' => false,
                'fields' => [
                    [
                        'handle' => 'limit_key',
                        'field' => [
                            'type' => 'text',
                            'display' => __('entitlements::cp.limits_col_key'),
                            'validate' => ['required', 'max:64', 'regex:/^[a-z0-9_.-]+$/'],
                        ],
                    ],
                    [
                        'handle' => 'value',
                        'field' => [
                            'type' => 'integer',
                            'display' => __('entitlements::cp.limits_col_value'),
                            'validate' => ['nullable', 'integer', 'min:0'],
                        ],
                    ],
                    [
                        'handle' => 'unlimited',
                        'field' => [
                            'type' => 'toggle',
                            'display' => __('entitlements::cp.limits_col_unlimited'),
                        ],
                    ],
                    [
                        'handle' => 'period',
                        'field' => [
                            'type' => 'select',
                            'display' => __('entitlements::cp.limits_col_period'),
                            'options' => [
                                'stock' => __('entitlements::cp.limits_period_stock'),
                                'month' => __('entitlements::cp.limits_period_month'),
                                'year' => __('entitlements::cp.limits_period_year'),
                            ],
                            'default' => 'stock',
                            'clearable' => false,
                        ],
                    ],
                ],
            ],
        ];

        return Blueprint::make()->setContents([
            'tabs' => [
                'main' => [
                    'display' => __('entitlements::cp.limits_title'),
                    'sections' => [
                        [
                            'instructions' => __('entitlements::cp.limits_section_instructions'),
                            'fields' => $fields,
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * A select when the install has declared its subject types, free text when it
     * has not.
     *
     * Free text is right for a first install — nobody knows their morph aliases
     * on day one — and wrong for a large one, where a typo produces a grant that
     * belongs to nobody and is found only when somebody complains that their
     * access never arrived.
     *
     * @return array<string, mixed>
     */
    private static function subjectTypeField(): array
    {
        $types = collect((array) config('entitlements.manual.subject_types', []))
            ->mapWithKeys(fn ($label, $key) => is_int($key)
                ? [(string) $label => (string) $label]
                : [(string) $key => (string) $label])
            ->all();

        $base = [
            'display' => __('entitlements::cp.field_subject_type'),
            'instructions' => __('entitlements::cp.field_subject_type_instructions'),
            'width' => 50,
            'if' => ['subject_kind' => 'equals other'],
            'validate' => ['required_if:subject_kind,other', 'max:160'],
        ];

        return $types === []
            ? $base + ['type' => 'text']
            : $base + ['type' => 'select', 'options' => $types, 'clearable' => false];
    }

    /**
     * A picker with names when some addon has registered its products, free
     * text when none has.
     *
     * With a catalogue the picker offers its entries and nothing else, not
     * even slugs already granted (design review, 03.10.2026): new grants are
     * made by name. The server still accepts any slug, because the catalogue
     * names products and never decides which may be granted; an integration
     * posting an old slug keeps working, and the grant is flagged on its page.
     *
     * The catalogue is read once: a source may query.
     *
     * @return array<string, mixed>
     */
    public static function productField(string $instructions): array
    {
        $base = ['validate' => ['required', 'max:191']];

        $products = app(ProductCatalog::class)->all();

        if ($products === []) {
            return $base + [
                'type' => 'text',
                'display' => __('entitlements::cp.field_product_slug'),
                'instructions' => $instructions,
            ];
        }

        return $base + [
            'type' => 'select',
            'display' => __('entitlements::cp.field_product'),
            'instructions' => __('entitlements::cp.field_product_instructions'),
            'options' => app(ProductCatalog::class)->options($products),
            'searchable' => true,
            'clearable' => false,
            'placeholder' => __('entitlements::cp.field_product_placeholder'),
        ];
    }
}
