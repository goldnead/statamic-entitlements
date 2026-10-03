<?php

namespace Goldnead\Entitlements\Support;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The products a person can be granted, with names, as other addons announce them.
 *
 * This package still does not know what a product is. A grant hangs off a slug
 * and keeps doing so. What it lacked was a way for the Control Panel to offer
 * those slugs by name: an admin was asked to type `choiraccelerator` from memory
 * and to know whether the user she meant was `user:42` or `App\Models\User:42`.
 * A sibling that does know its products (statamic-products) registers them here,
 * and the grant form, the limits form and the user page offer a picker instead.
 *
 *     Entitlements::registerProducts(fn () => [
 *         'choiraccelerator' => 'Choir Accelerator',
 *         'masterclass' => ['label' => 'Masterclass', 'group' => 'Adrian Goldner'],
 *     ]);
 *
 * A source is a callable, an iterable, an object with `grantableProducts()`, or
 * a class tagged `entitlements.product-catalog` in the container. Callables are
 * read when a form asks, never at registration: a sibling's products live in a
 * table that may not exist yet while providers boot.
 *
 * ## Never a whitelist
 *
 * A slug nobody registered still writes, resolves and grants access, exactly as
 * an unregistered source does ({@see SourceRegistry}). The catalogue only adds
 * names. A grant the source system wrote years ago under a slug that no longer
 * exists stays valid; the screens flag it rather than refuse it.
 *
 * ## No catalogue, no picker
 *
 * Until some source answers with at least one product, every field stays the
 * free text it always was. An install without statamic-products sees no change.
 *
 * ## A failing source costs its entries, never the form
 *
 * A source that throws (a missing table before `php artisan migrate`) is logged
 * and skipped, so the grant form still opens, as free text if nothing else
 * answered.
 */
final class ProductCatalog
{
    public const TAG = 'entitlements.product-catalog';

    /** @var list<callable|iterable<mixed>|object> */
    private array $sources = [];

    /**
     * @param  callable(): iterable<mixed>|iterable<mixed>|object|class-string  $source
     */
    public function register(mixed $source): void
    {
        if (is_string($source) && class_exists($source)) {
            $source = app($source);
        }

        if (! is_callable($source) && ! is_iterable($source) && ! (is_object($source) && method_exists($source, 'grantableProducts'))) {
            throw new InvalidArgumentException(
                'A product source is a callable, an iterable or an object with grantableProducts().'
            );
        }

        $this->sources[] = $source;
    }

    public function forget(): void
    {
        $this->sources = [];
    }

    /**
     * Every registered product, keyed by slug, in the order the sources gave them.
     *
     * @return array<string, array{slug: string, label: string, group: string|null}>
     */
    public function all(): array
    {
        $products = [];

        foreach ([...$this->sources, ...$this->tagged()] as $source) {
            try {
                $entries = match (true) {
                    is_object($source) && method_exists($source, 'grantableProducts') => $source->grantableProducts(),
                    is_callable($source) => $source(),
                    default => $source,
                };

                foreach ($entries ?? [] as $key => $entry) {
                    $normalised = $this->normalise($key, $entry);

                    // The first source to name a slug wins. Two addons that
                    // both claim one is a configuration problem; the picker
                    // must not show the same product twice.
                    if ($normalised !== null) {
                        $products[$normalised['slug']] ??= $normalised;
                    }
                }
            } catch (Throwable $e) {
                Log::warning('statamic-entitlements: a product source failed and was skipped.', [
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $products;
    }

    /** Whether any source answers with at least one product. */
    public function available(): bool
    {
        return $this->all() !== [];
    }

    /**
     * The registered name of a slug, or the slug itself.
     *
     * @param  array<string, array{slug: string, label: string, group: string|null}>|null  $products  pass {@see all()} when labelling many rows
     */
    public function label(string $slug, ?array $products = null): string
    {
        return ($products ?? $this->all())[$slug]['label'] ?? $slug;
    }

    /**
     * Whether a slug is unknown to a catalogue that exists. False without one:
     * there is nothing to be unknown to, so nothing is flagged.
     *
     * @param  array<string, array{slug: string, label: string, group: string|null}>|null  $products
     */
    public function isUnknown(string $slug, ?array $products = null): bool
    {
        $products ??= $this->all();

        return $products !== [] && ! array_key_exists($slug, $products);
    }

    /**
     * Select options: every registered product by name, then each slug already
     * in use that no source knows, flagged as such.
     *
     * The flagged ones are offered rather than hidden, because they are real
     * grants: somebody may need to hand out the same old slug once more.
     *
     * @param  iterable<int, string>  $inUse
     * @return array<string, string> slug => label
     */
    public function options(iterable $inUse = []): array
    {
        $products = $this->all();
        $options = [];

        foreach ($products as $slug => $product) {
            $options[$slug] = $product['group'] !== null && $product['group'] !== ''
                ? $product['label'].' · '.$product['group']
                : $product['label'];
        }

        $unknown = [];

        foreach ($inUse as $slug) {
            $slug = (string) $slug;

            if ($slug !== '' && ! array_key_exists($slug, $options)) {
                $unknown[$slug] = (string) __('entitlements::cp.catalog_unknown_option', ['slug' => $slug]);
            }
        }

        ksort($unknown);

        return $options + $unknown;
    }

    /**
     * @return array{slug: string, label: string, group: string|null}|null
     */
    private function normalise(mixed $key, mixed $entry): ?array
    {
        if (is_array($entry)) {
            $slug = (string) ($entry['slug'] ?? (is_string($key) ? $key : ''));
            $label = (string) ($entry['label'] ?? $entry['name'] ?? $slug);
            $group = isset($entry['group']) && is_scalar($entry['group']) ? (string) $entry['group'] : null;
        } elseif (is_string($key)) {
            $slug = $key;
            $label = is_scalar($entry) && (string) $entry !== '' ? (string) $entry : $key;
            $group = null;
        } elseif (is_string($entry)) {
            // A plain list of slugs, no names.
            $slug = $entry;
            $label = $entry;
            $group = null;
        } else {
            return null;
        }

        $slug = trim($slug);

        if ($slug === '') {
            return null;
        }

        return ['slug' => $slug, 'label' => trim($label) !== '' ? trim($label) : $slug, 'group' => $group];
    }

    /** @return list<object> */
    private function tagged(): array
    {
        try {
            return array_values(array_filter(
                iterator_to_array(app()->tagged(self::TAG), false),
                fn ($source) => is_object($source),
            ));
        } catch (Throwable) {
            return [];
        }
    }
}
