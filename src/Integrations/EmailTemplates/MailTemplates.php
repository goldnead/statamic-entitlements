<?php

namespace Goldnead\Entitlements\Integrations\EmailTemplates;

use Goldnead\Entitlements\Integrations\EventCatalog;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
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

    public const REGISTRY = 'email-templates.registry';

    /**
     * Tell email-templates which mails this addon sends.
     *
     * Through its registry where there is one (2.8 and later): that is what
     * shows editors "sent on: Entitlements, a limit is reached", the
     * placeholders, the preview examples, and what `email-templates:import
     * --source=Entitlements` writes. Plain arrays and closures only, so no class
     * of the sibling is touched. On an older email-templates the import source
     * is tagged instead, asked for by interface name first because
     * {@see TemplateSource} implements it. Never both: the import would see the
     * same template twice.
     */
    public static function announce(Application $app): void
    {
        if (! config('entitlements.bridges.email_templates', true)) {
            return;
        }

        if ($app->bound(self::REGISTRY)) {
            try {
                foreach (static::definitions() as $definition) {
                    $app->make(self::REGISTRY)->register($definition);
                }
            } catch (Throwable $e) {
                Log::warning('statamic-entitlements: the mails could not be registered with email-templates.', [
                    'exception' => $e->getMessage(),
                ]);
            }

            return;
        }

        if (interface_exists(self::SOURCE_CONTRACT)) {
            $app->tag([TemplateSource::class], 'email-templates.sources');
        }
    }

    /**
     * The registry entries, one per mail.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $definitions = [];

        foreach (EventCatalog::MAILS as $moment => $configPath) {
            $definitions[] = [
                'slug' => static::defaults($moment)['slug'],
                'addon' => 'Entitlements',
                'trigger' => fn () => __('entitlements::mail.'.$moment.'.trigger'),
                'event' => EventCatalog::MOMENTS[$moment][0],
                'placeholders' => static::placeholders($moment),
                'defaults' => function () use ($moment) {
                    $default = static::defaults($moment);

                    return [
                        'title' => $default['title'],
                        'subject' => $default['subject'],
                        'preview' => $default['preview'],
                        'body' => $default['body'],
                        'description' => $default['description'],
                    ];
                },
            ];
        }

        return $definitions;
    }

    /**
     * @return array<string, array{label: string, example: string}>
     */
    public static function placeholders(string $moment): array
    {
        $keys = [
            'name' => 'Anna',
            'limit_label' => 'Analysen',
            'limit' => '50',
            'used' => '50',
            'product' => 'chor-jahr',
            'period_end' => '14. März 2027',
            'period_note' => (string) __('entitlements::mail.limit_reached.note_usage', ['date' => '14. März 2027']),
            'app_name' => (string) config('app.name'),
        ];

        $placeholders = [];

        foreach ($keys as $key => $example) {
            $placeholders[$key] = [
                'label' => (string) __('entitlements::mail.placeholders.'.$key),
                'example' => $example,
            ];
        }

        return $placeholders;
    }

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
            'html' => view(self::LAYOUT, [
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
