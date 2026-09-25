<?php

namespace Goldnead\Entitlements\Http\Controllers\Cp;

use Goldnead\Entitlements\Limits\LimitCatalog;
use Goldnead\Entitlements\Limits\QuotaManager;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\Blueprints;
use Goldnead\Entitlements\Support\Setup;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;
use Statamic\CP\Column;
use Statamic\CP\PublishForm;

/**
 * Limits on products: what a plan allows.
 *
 * The overview lists every product that carries a limit and every product
 * somebody holds a grant for, so a plan without limits yet is one click away
 * rather than a slug to remember. The forms are core PublishForms; no Vue.
 *
 * Reading is `view entitlements`, changing is `manage entitlements limits`:
 * a limit decides what a paying customer can do, which is a commercial
 * decision like granting.
 */
class LimitController extends Controller
{
    public function __construct(private readonly LimitCatalog $catalog) {}

    public function index()
    {
        Gate::authorize('view entitlements');

        if ($setup = Setup::guard(__('entitlements::cp.limits_title'), 'entitlements', 'entitlement_limits', 'entitlement_usages', 'entitlement_usage_receipts')) {
            return $setup;
        }

        $withLimits = $this->catalog->products();
        $counts = Entitlement::query()
            ->selectRaw('product_slug, count(*) as aggregate')
            ->groupBy('product_slug')
            ->pluck('aggregate', 'product_slug')
            ->all();
        $slugs = array_values(array_unique([...$withLimits, ...array_map('strval', array_keys($counts))]));
        sort($slugs);

        $limits = $this->catalog->forProducts($slugs);
        $keys = $this->catalog->keys();
        $canManage = Gate::allows('manage entitlements limits');

        $rows = array_map(function (string $slug) use ($limits, $keys, $canManage, $counts) {
            $own = $limits[$slug] ?? [];

            return [
                'id' => $slug,
                'product_slug' => $slug,
                'limits' => array_values(array_map(fn (string $key, array $limit) => [
                    'key' => $key,
                    'label' => $keys[$key]['label'] ?? $key,
                    'text' => $this->describe($limit),
                ], array_keys($own), $own)),
                'grants' => (int) ($counts[$slug] ?? 0),
                'edit_url' => $canManage ? cp_route('entitlements.limits.edit', ['product' => $slug]) : null,
            ];
        }, $slugs);

        return Inertia::render('entitlements::Limits/Index', [
            'rows' => $rows,
            'columns' => [
                Column::make('product_slug')->label(__('entitlements::cp.col_product'))->toArray(),
                Column::make('limits')->label(__('entitlements::cp.limits_title'))->sortable(false)->toArray(),
                Column::make('grants')->label(__('entitlements::cp.limits_col_grants'))->toArray(),
            ],
            'createUrl' => cp_route('entitlements.limits.create'),
            'canManage' => $canManage,
            'fallbackProduct' => app(QuotaManager::class)->fallbackProduct(),
            'fallbackByType' => collect((array) config('entitlements.limits.fallback_products', []))
                ->map(fn ($slug, $type) => __('entitlements::cp.limits_fallback_type', [
                    'type' => $type,
                    'product' => is_string($slug) && $slug !== '' ? $slug : __('entitlements::cp.limits_fallback_none_value'),
                ]))
                ->values()
                ->all(),
        ]);
    }

    public function create(Request $request)
    {
        Gate::authorize('manage entitlements limits');

        return PublishForm::make(Blueprints::limits(creating: true))
            ->title(__('entitlements::cp.limits_create'))
            ->icon('key')
            ->values(['product_slug' => (string) $request->query('product', ''), 'limits' => []])
            ->submittingTo(cp_route('entitlements.limits.store'), 'POST');
    }

    public function store(Request $request)
    {
        Gate::authorize('manage entitlements limits');

        $values = PublishForm::make(Blueprints::limits(creating: true))->submit($request->all());
        $product = trim((string) ($values['product_slug'] ?? ''));

        $this->save($product, $values['limits'] ?? []);

        return ['saved' => true, 'redirect' => cp_route('entitlements.limits.index')];
    }

    public function edit(string $product)
    {
        Gate::authorize('manage entitlements limits');

        $rows = [];

        foreach ($this->catalog->forProduct($product) as $key => $limit) {
            $rows[] = [
                'limit_key' => $key,
                'value' => $limit['value'],
                'unlimited' => $limit['value'] === null,
                'period' => $limit['period'] ?? 'stock',
            ];
        }

        return PublishForm::make(Blueprints::limits(creating: false))
            ->title(__('entitlements::cp.limits_edit', ['product' => $product]))
            ->icon('key')
            ->values(['limits' => $rows])
            ->submittingTo(cp_route('entitlements.limits.update', ['product' => $product]), 'PATCH');
    }

    public function update(Request $request, string $product)
    {
        Gate::authorize('manage entitlements limits');

        $values = PublishForm::make(Blueprints::limits(creating: false))->submit($request->all());

        $this->save($product, $values['limits'] ?? []);

        return ['saved' => true, 'redirect' => cp_route('entitlements.limits.index')];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function save(string $product, array $rows): void
    {
        $limits = [];

        foreach ($rows as $index => $row) {
            $key = trim((string) ($row['limit_key'] ?? ''));

            if ($key === '') {
                continue;
            }

            $unlimited = (bool) ($row['unlimited'] ?? false);
            $value = $row['value'] ?? null;

            // Not unlimited and no number is a cell somebody forgot, not an
            // answer. Refused rather than guessed in either direction.
            if (! $unlimited && ($value === null || $value === '')) {
                throw ValidationException::withMessages([
                    "limits.{$index}.value" => __('entitlements::cp.limits_value_required'),
                ]);
            }

            if (isset($limits[$key])) {
                throw ValidationException::withMessages([
                    "limits.{$index}.limit_key" => __('entitlements::cp.limits_key_twice', ['key' => $key]),
                ]);
            }

            $period = in_array($row['period'] ?? null, LimitCatalog::PERIODS, true) ? $row['period'] : null;

            $limits[$key] = ['value' => $unlimited ? null : (int) $value, 'period' => $period];
        }

        try {
            $this->catalog->store($product, $limits);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['limits' => $e->getMessage()]);
        }
    }

    /** @param  array{value: int|null, period: string|null}  $limit */
    private function describe(array $limit): string
    {
        if ($limit['value'] === null) {
            return (string) __('entitlements::cp.limits_unlimited');
        }

        $value = (string) $limit['value'];

        return match ($limit['period']) {
            'month' => __('entitlements::cp.limits_per_month', ['value' => $value]),
            'year' => __('entitlements::cp.limits_per_year', ['value' => $value]),
            default => __('entitlements::cp.limits_at_once', ['value' => $value]),
        };
    }
}
