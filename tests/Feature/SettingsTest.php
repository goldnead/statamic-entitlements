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

it('turns the manual grant form into a select once subject types are saved', function () {
    // Vorher: freies Textfeld, weil nichts eingetragen ist.
    $field = Blueprints::grant()->field('subject_type')->config();

    expect($field['type'])->toBe('text');

    BrandSettings::for('entitlements')->save(['manual.subject_types' => ['user', 'contact']]);

    // Nachher: Auswahlfeld mit genau diesen beiden Typen. Das ist die Grenze,
    // die zaehlt — die Config allein belegt nur den halben Weg.
    $field = Blueprints::grant()->field('subject_type')->config();

    expect($field['type'])->toBe('select')
        ->and(array_keys($field['options']))->toBe(['user', 'contact']);
});

it('keeps a saved list of subject types as strings', function () {
    // Die Schicht wandelt Listeneintraege sonst nicht, und ein "42" statt 42
    // waere hier egal — ein 42 statt "42" nicht: der Morph-Typ ist ein
    // Schluessel in `options` und wuerde zur Zahl.
    BrandSettings::for('entitlements')->save(['manual.subject_types' => ['user']]);

    expect(config('entitlements.manual.subject_types'))->toBe(['user']);
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
