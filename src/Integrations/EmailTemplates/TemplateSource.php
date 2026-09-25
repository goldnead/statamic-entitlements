<?php

namespace Goldnead\Entitlements\Integrations\EmailTemplates;

use Goldnead\EmailTemplates\Contracts\EmailTemplateSource;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Goldnead\Entitlements\Integrations\EventCatalog;

/**
 * Hands this addon's default mails to `php artisan email-templates:import`,
 * which turns them into ordinary entries an editor can change.
 *
 * **Implements a sibling's interface**, so it is only named after
 * `interface_exists()` has been asked by string — see the service provider.
 */
class TemplateSource implements EmailTemplateSource
{
    public function label(): string
    {
        return 'Entitlements';
    }

    public function all(): array
    {
        $templates = [];

        foreach (array_keys(EventCatalog::MAILS) as $moment) {
            $templates[] = EmailTemplateData::fromArray(MailTemplates::defaults($moment) + ['source' => 'entitlements']);
        }

        return $templates;
    }
}
