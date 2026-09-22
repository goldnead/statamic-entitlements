<?php

use Goldnead\BrandContext\Facades\BrandSettings;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\Entitlements\Support\Blueprints;
use Goldnead\Entitlements\Support\Settings;

/**
 * Die Einstellungen dieses Addons an der geteilten Schicht.
 *
 * Geprüft wird nicht, dass die Schicht funktioniert — das gehört in deren
 * eigene Suite — sondern dass dieses Addon richtig daran hängt, und dass ein
 * gespeicherter Wert bis zum Leser durchkommt. Der Leser ist hier das
 * Blueprint für die Freigabe von Hand: es entscheidet anhand des Werts, ob
 * das Feld ein Auswahlfeld oder ein Textfeld wird.
 */
it('registers itself with the shared settings layer', function () {
    $registry = app(SettingsRegistry::class);

    expect($registry->has('entitlements'))->toBeTrue('boot() hat die Einstellungen nicht angemeldet.')
        ->and($registry->provider('entitlements'))->toBe(Settings::class)
        ->and($registry->configPath('entitlements'))->toBe('entitlements')
        ->and($registry->permission('entitlements'))->toBe('manage entitlements settings');
});

it('turns the manual grant form into a select from the config, not from the settings screen', function () {
    // Vorher: freies Textfeld, weil nichts eingetragen ist.
    expect(Blueprints::grant()->field('subject_type')->config()['type'])->toBe('text');

    // Der Wert wohnt in `config/`, in beiden Formen: flache Liste ODER
    // Zuordnung Typ => Beschriftung.
    config()->set('entitlements.manual.subject_types', [
        'user' => 'Mitglied',
        'contact' => 'Kontakt',
    ]);

    $field = Blueprints::grant()->field('subject_type')->config();

    expect($field['type'])->toBe('select')
        ->and(array_keys($field['options']))->toBe(['user', 'contact']);
});

it('does not offer the subject types on the settings screen', function () {
    // Weil der Wert eine Zuordnung sein darf und die Schicht dafuer keinen Typ
    // hat: als `list` deklariert reichte er ein Objekt an die Seite, das
    // `join()` dort starb, und die Einstellungsseite ALLER Addons blieb weiss.
    // Gemessen 22.09.2026 auf staging.adriangoldner.com.
    $keys = collect(Settings::settingsGroups())
        ->flatMap(fn (array $group) => array_column($group['fields'], 'key'));

    expect($keys)->not->toContain('manual.subject_types');

    // Und eine Zeile aus einer aelteren Version darf den Wert nicht mehr
    // setzen — die Schicht wendet nur an, was das Addon heute anbietet.
    BrandSettings::for('entitlements')->save(['manual.subject_types' => ['user']]);

    expect(config('entitlements.manual.subject_types'))->toBe([]);
});

it('puts a saved page size onto the config the listing reads', function () {
    BrandSettings::for('entitlements')->save(['cp.per_page' => 25]);

    expect(config('entitlements.cp.per_page'))->toBe(25);
});

it('does not offer a key that is read while booting', function () {
    $offered = array_keys(app(SettingsRegistry::class)->fields('entitlements'));

    // `cp.enabled` beim Registrieren der Routen und der Navigation;
    // `bridges.activity` haengt sich beim Booten ein und merkt sich das, ein
    // spaeteres "aus" loest die Listener nicht wieder.
    expect($offered)->not->toContain('cp.enabled')
        ->and($offered)->not->toContain('bridges.activity')
        // Abbildungen.
        ->and($offered)->not->toContain('sources')
        ->and($offered)->not->toContain('manual.source');
});
