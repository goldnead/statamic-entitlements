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
                    // `manual.subject_types` gehoert NICHT auf die Seite.
                    //
                    // Der Wert darf beides sein: eine flache Liste von
                    // Morph-Typen ODER eine Zuordnung Typ => Beschriftung
                    // (Blueprints::subjectTypeField() liest beide). Die
                    // Einstellungs-Schicht kennt keinen Typ fuer eine
                    // Zuordnung, und als `list` deklariert reichte sie auf
                    // einer Site mit Zuordnung ein Objekt an die Seite: das
                    // `join()` dort starb, und mit ihm die
                    // Einstellungsseite ALLER Addons (weiss, nur ein Fehler
                    // in der Browserkonsole). Gemessen 22.09.2026 auf
                    // staging.adriangoldner.com.
                    //
                    // Zuordnungen bleiben in `config/`, so entschieden am
                    // 07.09.2026 fuer `invoices.tax.zones` und drei weitere.
                    // Die Seite nennt den Config-Pfad ohnehin.
                ],
            ],
            [
                // Alle vier werden bei jedem Zugriff gelesen, nie beim Booten,
                // wirken also ab dem nächsten Aufruf. Die Grenzen selbst stehen
                // nicht hier, sondern am Produkt (Entitlements > Grenzen).
                'title' => __('entitlements::settings.groups.limits.title'),
                'description' => __('entitlements::settings.groups.limits.description'),
                'fields' => [
                    static::field('limits.fallback_product', 'string', ['nullable' => true]),
                    static::field('limits.period_anchor', 'select', [
                        'options' => [
                            'grant' => __('entitlements::settings.options.period_anchor.grant'),
                            'calendar' => __('entitlements::settings.options.period_anchor.calendar'),
                        ],
                    ]),
                    static::field('mail.limit_reached.enabled', 'boolean'),
                    static::field('mail.limit_reached.template', 'string'),
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
