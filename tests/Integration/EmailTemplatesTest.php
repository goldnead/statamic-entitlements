<?php

use Goldnead\EmailTemplates\EmailTemplatesServiceProvider;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Goldnead\Entitlements\Integrations\EmailTemplates\MailTemplates;
use Goldnead\Entitlements\Integrations\EmailTemplates\TemplateSource;
use Statamic\Facades\Entry;

/**
 * Der Weg über statamic-email-templates, mit dem echten Addon.
 */
beforeEach(function () {
    $this->app->getProvider(EmailTemplatesServiceProvider::class)->bootAddon();

    // Entries are files in the testbench app and outlive a test.
    Entry::query()->where('collection', MailTemplates::COLLECTION)->get()->each->delete();

    app()->setLocale('de');
});

it('offers the default mail to email-templates:import', function () {
    $sources = iterator_to_array(app()->tagged('email-templates.sources'));

    $ours = collect($sources)->first(fn ($s) => $s instanceof TemplateSource);

    expect($ours)->not->toBeNull()
        ->and($ours->all()[0]->slug)->toBe('entitlements-limit-reached')
        ->and($ours->all()[0]->subject)->toBe('{{ limit_label }}: Grenze erreicht');
});

it('renders the default through email-templates until an editor has changed it', function () {
    $rendered = MailTemplates::render('limit_reached', ['limit_label' => 'Analysen', 'used' => 3, 'limit' => 3, 'name' => 'Anna', 'period_note' => '', 'app_name' => 'ChoirLive']);

    expect($rendered['via'])->toBe('default')
        ->and($rendered['subject'])->toBe('Analysen: Grenze erreicht')
        ->and($rendered['html'])->toContain('3 von 3');
});

it('sends what the editor wrote once the template is an entry', function () {
    app(EmailTemplateCollectionManager::class)->upsert(EmailTemplateData::fromArray([
        'slug' => 'entitlements-limit-reached',
        'title' => 'Grenze erreicht',
        'subject' => 'Voll: {{ limit_label }}',
        'body' => '<p>Eigener Text für {{ name }}.</p>',
    ]));

    $rendered = MailTemplates::render('limit_reached', ['limit_label' => 'Analysen', 'name' => 'Anna']);

    expect($rendered['via'])->toBe('entry')
        ->and($rendered['subject'])->toBe('Voll: Analysen')
        ->and($rendered['html'])->toContain('Eigener Text für Anna.')
        ->and(MailTemplates::editUrl('entitlements-limit-reached'))->toContain('/collections/et_templates/entries/');
});
