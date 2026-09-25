<?php

namespace Goldnead\Entitlements\Limits;

use Goldnead\Entitlements\Models\ProductLimit;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * What each product allows, from two places.
 *
 * `config('entitlements.limits.products')` for plans that live in code and
 * ship with a deploy, and the `entitlement_limits` table for what an operator
 * sets in the Control Panel. Per key, the stored row wins: a number typed into
 * the Control Panel is the more recent decision.
 *
 * The addon still does not know what a product is. A limit hangs off a product
 * slug the way a grant does, and a slug nobody grants simply never applies.
 */
class LimitCatalog
{
    public const PERIODS = ['month', 'year'];

    /** Whether the limits tables exist yet — false on an install that has not migrated. */
    public function ready(): bool
    {
        // Only a yes is remembered: a migration can run while a worker lives,
        // and a remembered no would outlast it.
        return $this->ready = $this->ready
            || (Schema::hasTable('entitlement_limits') && Schema::hasTable('entitlement_usages'));
    }

    private bool $ready = false;

    /**
     * @return array<string, array{value: int|null, period: string|null}>
     */
    public function forProduct(string $productSlug): array
    {
        $limits = [];

        foreach ((array) config('entitlements.limits.products.'.$productSlug, []) as $key => $definition) {
            $limits[(string) $key] = $this->normalise((string) $key, $definition);
        }

        if ($this->ready()) {
            foreach (ProductLimit::query()->where('product_slug', $productSlug)->get() as $row) {
                $limits[$row->limit_key] = [
                    'value' => $row->value,
                    'period' => $this->period($row->period),
                ];
            }
        }

        ksort($limits);

        return $limits;
    }

    /**
     * Limits for several products in one query, keyed by product slug.
     *
     * @param  list<string>  $productSlugs
     * @return array<string, array<string, array{value: int|null, period: string|null}>>
     */
    public function forProducts(array $productSlugs): array
    {
        $result = [];

        foreach (array_unique($productSlugs) as $slug) {
            foreach ((array) config('entitlements.limits.products.'.$slug, []) as $key => $definition) {
                $result[$slug][(string) $key] = $this->normalise((string) $key, $definition);
            }
        }

        if ($productSlugs !== [] && $this->ready()) {
            foreach (ProductLimit::query()->whereIn('product_slug', array_unique($productSlugs))->get() as $row) {
                $result[$row->product_slug][$row->limit_key] = [
                    'value' => $row->value,
                    'period' => $this->period($row->period),
                ];
            }
        }

        return $result;
    }

    /**
     * Every product slug that carries at least one limit.
     *
     * @return list<string>
     */
    public function products(): array
    {
        $slugs = array_map('strval', array_keys((array) config('entitlements.limits.products', [])));

        if ($this->ready()) {
            $slugs = [...$slugs, ...ProductLimit::query()->distinct()->pluck('product_slug')->all()];
        }

        $slugs = array_values(array_unique($slugs));
        sort($slugs);

        return $slugs;
    }

    /**
     * Every limit key anybody has declared or used, with its label.
     *
     * @return array<string, array{label: string, period: string|null}>
     */
    public function keys(): array
    {
        $keys = [];

        foreach ((array) config('entitlements.limits.keys', []) as $key => $definition) {
            $keys[(string) $key] = [
                'label' => (string) (is_array($definition) ? ($definition['label'] ?? $key) : $definition),
                'period' => $this->defaultPeriod((string) $key),
            ];
        }

        foreach ((array) config('entitlements.limits.products', []) as $limits) {
            foreach (array_keys((array) $limits) as $key) {
                $keys[(string) $key] ??= ['label' => (string) $key, 'period' => $this->defaultPeriod((string) $key)];
            }
        }

        if ($this->ready()) {
            foreach (ProductLimit::query()->distinct()->pluck('limit_key') as $key) {
                $keys[(string) $key] ??= ['label' => (string) $key, 'period' => $this->defaultPeriod((string) $key)];
            }
        }

        ksort($keys);

        return $keys;
    }

    public function label(string $key): string
    {
        $definition = config('entitlements.limits.keys.'.$key);

        return (string) (is_array($definition) ? ($definition['label'] ?? $key) : ($definition ?: $key));
    }

    /**
     * Replace the stored limits of one product. Keys left out are deleted.
     *
     * @param  array<string, int|null|array{value?: int|null, period?: string|null}>  $limits
     */
    public function store(string $productSlug, array $limits): void
    {
        $productSlug = trim($productSlug);

        if ($productSlug === '') {
            throw new InvalidArgumentException('A limit needs a product slug.');
        }

        $normalised = [];

        foreach ($limits as $key => $definition) {
            $key = trim((string) $key);

            if ($key === '') {
                continue;
            }

            if (! preg_match('/^[a-z0-9_.-]{1,64}$/', $key)) {
                throw new InvalidArgumentException('A limit key may only contain a-z, 0-9, dot, dash and underscore: ['.$key.'].');
            }

            $normalised[$key] = $this->normalise($key, $definition);
        }

        ProductLimit::query()
            ->where('product_slug', $productSlug)
            ->whereNotIn('limit_key', array_keys($normalised) ?: [''])
            ->delete();

        foreach ($normalised as $key => $definition) {
            $row = ProductLimit::query()
                ->where('product_slug', $productSlug)
                ->where('limit_key', $key)
                ->first() ?? new ProductLimit(['product_slug' => $productSlug, 'limit_key' => $key]);

            $row->forceFill($definition)->save();
        }
    }

    /**
     * @return array{value: int|null, period: string|null}
     */
    private function normalise(string $key, mixed $definition): array
    {
        if (is_array($definition)) {
            $value = $definition['value'] ?? null;
            $period = array_key_exists('period', $definition)
                ? $this->period($definition['period'])
                : $this->defaultPeriod($key);
        } else {
            $value = $definition;
            $period = $this->defaultPeriod($key);
        }

        if ($value !== null && (! is_numeric($value) || (int) $value < 0)) {
            throw new InvalidArgumentException('A limit is a whole number of at least 0, or null for unlimited: ['.$key.'].');
        }

        return [
            'value' => $value === null ? null : (int) $value,
            'period' => $period,
        ];
    }

    private function defaultPeriod(string $key): ?string
    {
        $definition = config('entitlements.limits.keys.'.$key);

        return is_array($definition) ? $this->period($definition['period'] ?? null) : null;
    }

    private function period(mixed $period): ?string
    {
        return in_array($period, self::PERIODS, true) ? $period : null;
    }
}
