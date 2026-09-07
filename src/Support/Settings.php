<?php

namespace Goldnead\Entitlements\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;

/**
 * Die Betriebswerte, die ein Betreiber im Control Panel ändern darf.
 *
 * Nur die Feldliste. Seite, Formular, Validierung, Speicher und Rechteprüfung
 * kommen aus `goldnead/statamic-brand-context` — siehe {@see ProvidesSettings}.
 *
 * **Was nicht hier steht, und warum.**
 *
 * - `cp.enabled`. Wird beim Registrieren der Routen gelesen
 *   (`routes/cp.php:8`) und beim Aufbau der Navigation
 *   (`ServiceProvider::bootNavigation()`), also bevor
 *   `SettingsManager::apply()` aus `app->booted()` läuft.
 * - `bridges.activity`. Ebenfalls beim Booten gelesen, und schlimmer als nur
 *   zu spät: `ActivityBridge::attach()` merkt sich in einer statischen
 *   Eigenschaft, dass es angehängt hat, und der Provider ruft es aus
 *   `bootAddon()`, `app->booted()` und `Statamic::booted()` an. Ist es einmal
 *   angehängt, löst ein späteres „aus" die Listener nicht wieder. Ein
 *   Schalter, der nur in eine Richtung und nur bis zum nächsten Deploy wirkt,
 *   gehört nicht auf einen Bildschirm.
 * - `sources`. Eine Abbildung Handle → Anzeigename, für die der Vertrag dieser
 *   Schicht keinen Typ kennt. Sie ist außerdem keine Whitelist: eine nicht
 *   eingetragene Quelle schreibt und gewährt genauso, sie zeigt nur ihr
 *   rohes Handle.
 * - `manual.source`. Steht als Wert in der `source`-Spalte jeder von Hand
 *   geschriebenen Zeile. Ihn zu ändern, ändert nichts an den bestehenden
 *   Zeilen und trennt die neuen von ihnen ab.
 */
class Settings implements ProvidesSettings
{
    /**
     * Bleibt für immer stehen: der Wert steht in `brand_settings.namespace` in
     * jeder Zeile, ein neuer Name verwaist jede gespeicherte Änderung.
     */
    public static function settingsNamespace(): string
    {
        return 'entitlements';
    }

    public static function settingsConfigPath(): string
    {
        return 'entitlements';
    }

    public static function settingsPermission(): string
    {
        return 'manage entitlements settings';
    }

    /**
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('entitlements::settings.groups.cp.title'),
                'description' => __('entitlements::settings.groups.cp.description'),
                'fields' => [
                    static::field('cp.per_page', 'integer', ['min' => 1]),
                    static::field('manual.subject_types', 'list'),
                ],
            ],
            [
                'title' => __('entitlements::settings.groups.bridges.title'),
                'description' => __('entitlements::settings.groups.bridges.description'),
                'fields' => [
                    static::field('bridges.insights', 'boolean'),
                ],
            ],
        ];
    }

    /**
     * Ein Feld, mit Beschriftung und Beschreibung aus den Sprachdateien.
     *
     * Der Übersetzungsschlüssel ist der Config-Pfad mit flachgelegten Punkten:
     * ein Punkt im Sprachschlüssel ist für den Übersetzer ein Pfadtrenner.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function field(string $key, string $type, array $extra = []): array
    {
        $handle = str_replace('.', '_', $key);

        return array_merge([
            'key' => $key,
            'type' => $type,
            'label' => __("entitlements::settings.fields.{$handle}.label"),
            'description' => __("entitlements::settings.fields.{$handle}.description"),
            'nullable' => false,
        ], $extra);
    }
}
