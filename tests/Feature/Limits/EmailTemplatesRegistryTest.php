<?php

use Goldnead\Entitlements\Events\LimitReached;
use Goldnead\Entitlements\Integrations\EmailTemplates\MailTemplates;
use Goldnead\Entitlements\Integrations\EmailTemplates\TemplateSource;

/**
 * Die Anmeldung bei email-templates. Ab 2.8 über dessen Registry (Container-
 * Schlüssel `email-templates.registry`), vorher über den Import-Tag. Die
 * Registry steht hier als schlichtes Objekt unter ihrem Schlüssel: sie ist nur
 * über `register(array)` angesprochen, und die installierte Fassung im Dev-
 * Vendor kennt sie noch nicht.
 */
it('announces the limit mail to the registry with trigger, event, placeholders and defaults', function () {
    $registry = new class
    {
        public array $registered = [];

        public function register(array $definition): void
        {
            $this->registered[] = $definition;
        }
    };

    app()->instance(MailTemplates::REGISTRY, $registry);
    app()->setLocale('de');

    // The app booted without a registry (the dev vendor predates it), so the
    // tag is already there once; announcing with a registry must not add one.
    $countTagged = fn () => collect(iterator_to_array(app()->tagged('email-templates.sources'), false))
        ->filter(fn ($s) => $s instanceof TemplateSource)
        ->count();
    $before = $countTagged();

    MailTemplates::announce(app());

    expect($registry->registered)->toHaveCount(1);

    $definition = $registry->registered[0];

    expect($definition['slug'])->toBe('entitlements-limit-reached')
        ->and($definition['addon'])->toBe('Entitlements')
        ->and($definition['event'])->toBe(LimitReached::class)
        ->and(($definition['trigger'])())->toBe('Ein Kontingent ist ausgeschöpft')
        ->and(array_keys($definition['placeholders']))->toContain('limit_label', 'used', 'period_note')
        ->and(($definition['defaults'])()['subject'])->toBe('{{ limit_label }}: Grenze erreicht');

    // Never both: with the registry there, no import tag is added.
    expect($countTagged())->toBe($before);
});

it('registers nothing when the bridge is off', function () {
    config()->set('entitlements.bridges.email_templates', false);

    $registry = new class
    {
        public int $calls = 0;

        public function register(array $definition): void
        {
            $this->calls++;
        }
    };

    app()->instance(MailTemplates::REGISTRY, $registry);

    MailTemplates::announce(app());

    expect($registry->calls)->toBe(0);
});
