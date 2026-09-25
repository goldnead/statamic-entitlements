<?php

namespace Goldnead\Entitlements\Integrations\EmailTemplates;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Statamic\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Throwable;

/**
 * The bridge to `goldnead/statamic-email-templates`, and the default texts.
 *
 * Every mail of this addon is a template there: the editor changes it in the
 * Control Panel like any other. Until somebody has, the default below is
 * handed to the sibling as the resolver's fallback, so the mail goes out in
 * the sibling's layout either way. Without the sibling the same default is
 * rendered through the bundled Blade layout.
 *
 * `email-templates:import` copies the defaults into the collection
 * ({@see TemplateSource}), after which they are ordinary, editable entries.
 */
class MailTemplates
{
    public const FACADE = 'Goldnead\\EmailTemplates\\Facades\\EmailTemplates';

    public const DATA = 'Goldnead\\EmailTemplates\\Support\\EmailTemplateData';

    public const MERGE = 'Goldnead\\EmailTemplates\\Support\\MergeVariables';

    public const SOURCE_CONTRACT = 'Goldnead\\EmailTemplates\\Contracts\\EmailTemplateSource';

    public const COLLECTION = 'et_templates';

    /** The bundled layout, used when email-templates is not there. */
    public const LAYOUT = 'entitlements::mail.layout';

    public static function installed(): bool
    {
        return class_exists(self::FACADE) && class_exists(self::DATA);
    }

    public static function available(): bool
    {
        return (bool) config('entitlements.bridges.email_templates', true) && static::installed();
    }

    /**
     * The shipped default for a moment, in the current locale.
     *
     * @return array{slug: string, title: string, subject: string, preview: string, body: string, description: string}
     */
    public static function defaults(string $moment): array
    {
        $slug = (string) config('entitlements.mail.'.$moment.'.template', 'entitlements-'.str_replace('_', '-', $moment));

        return [
            'slug' => $slug,
            'title' => (string) __('entitlements::mail.'.$moment.'.title'),
            'subject' => (string) __('entitlements::mail.'.$moment.'.subject'),
            'preview' => (string) __('entitlements::mail.'.$moment.'.preview'),
            'body' => (string) __('entitlements::mail.'.$moment.'.body'),
            'description' => (string) __('entitlements::mail.'.$moment.'.description'),
        ];
    }

    /**
     * Subject and HTML body for a moment, with the variables put in.
     *
     * @param  array<string, mixed>  $variables
     * @return array{subject: string, html: string, via: string}
     */
    public static function render(string $moment, array $variables): array
    {
        $default = static::defaults($moment);

        if (static::available()) {
            try {
                $facade = self::FACADE;
                $data = self::DATA;
                $resolved = $facade::resolve($default['slug'], fn () => $data::fromArray($default + ['source' => 'entitlements']));

                if ($resolved !== null && is_string($resolved->body) && $resolved->body !== '') {
                    return [
                        'subject' => static::merge((string) $resolved->subject, $variables, escape: false),
                        'html' => static::merge($resolved->body, $variables),
                        'via' => static::entryExists($default['slug']) ? 'entry' : 'default',
                    ];
                }
            } catch (Throwable) {
                // Fall through to the bundled layout. A mail with the default
                // text is better than no mail because a sibling is mid-upgrade.
            }
        }

        return [
            'subject' => static::merge($default['subject'], $variables, escape: false),
            'html' => app(ViewFactory::class)->make(self::LAYOUT, [
                'body' => static::merge($default['body'], $variables),
                'preview' => static::merge($default['preview'], $variables, escape: false),
            ])->render(),
            'via' => 'view',
        ];
    }

    /**
     * `{{ key }}` replaced by the value. Through the sibling's MergeVariables
     * when it is there, so a template here understands the same placeholders
     * as one in an automation; otherwise the plain replacement below.
     *
     * @param  array<string, mixed>  $variables
     */
    public static function merge(string $text, array $variables, bool $escape = true): string
    {
        if (class_exists(self::MERGE)) {
            $merge = self::MERGE;

            return (string) $merge::apply($text, $variables, $escape);
        }

        foreach ($variables as $key => $value) {
            $value = is_scalar($value) || $value === null ? (string) $value : '';
            $text = str_replace(['{{ '.$key.' }}', '{{'.$key.'}}'], $escape ? e($value) : $value, $text);
        }

        return $text;
    }

    public static function entryExists(string $slug): bool
    {
        return self::entry($slug) !== null;
    }

    /** The CP edit link of the template entry, or null when it has not been imported. */
    public static function editUrl(string $slug): ?string
    {
        $entry = self::entry($slug);

        try {
            return $entry?->editUrl();
        } catch (Throwable) {
            return null;
        }
    }

    private static function entry(string $slug): ?EntryContract
    {
        if (! static::installed()) {
            return null;
        }

        try {
            if (! Collection::handleExists(self::COLLECTION)) {
                return null;
            }

            // A template collection holds a handful of entries; reading them
            // is cheaper than a query builder whose contract does not declare
            // `where()`.
            return Entry::whereCollection(self::COLLECTION)
                ->first(fn (EntryContract $entry) => $entry->slug() === $slug);
        } catch (Throwable) {
            return null;
        }
    }
}
