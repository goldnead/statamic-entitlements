<?php

namespace Goldnead\Entitlements\Support;

/**
 * Display names for grant sources.
 *
 * A registry, never a whitelist. `source` is a free string on the row because
 * the set differs per project — `thrivecart`, `mollie`, `newsletter_optin`,
 * `manual` in one install, something else entirely in the next — and an enum in
 * a shared package would force every consumer to extend it before they could
 * write their own grant.
 *
 * So an unregistered source is not an error. It writes, it resolves, it grants
 * access; the only thing it lacks is a nicer name in a filter dropdown, and
 * {@see self::label()} falls back to the raw value for exactly that reason. A
 * registry that refuses unknown values is a whitelist with better manners.
 */
class SourceRegistry
{
    /** @return array<string, string> handle => label */
    public function all(): array
    {
        return collect((array) config('entitlements.sources', []))
            ->mapWithKeys(fn ($label, $handle) => [(string) $handle => (string) $label])
            ->all();
    }

    public function label(string $source): string
    {
        $configured = $this->all()[$source] ?? null;

        // The shipped `manual` label is English in the config file. Unless a
        // site gave it a label of its own, the translation wins, so a German
        // CP does not read "Manual grant".
        if ($configured === null || $configured === 'Manual grant') {
            $key = 'entitlements::cp.sources.'.$source;
            $translated = __($key);

            if (is_string($translated) && $translated !== $key) {
                return $translated;
            }
        }

        return $configured ?? $source;
    }

    /**
     * The registry plus anything already in use, so a filter offers what the data
     * actually contains rather than what the config wishes it contained.
     *
     * @param  iterable<int, string>  $inUse
     * @return array<string, string>
     */
    public function optionsIncluding(iterable $inUse): array
    {
        $options = $this->all();

        foreach ($inUse as $source) {
            $source = (string) $source;

            if ($source !== '' && ! array_key_exists($source, $options)) {
                $options[$source] = $source;
            }
        }

        ksort($options);

        return $options;
    }
}
