<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kontingente: wie viel ein Zugang erlaubt, und wie viel davon verbraucht ist.
 *
 * ZWEI TABELLEN, EINE FRAGE JE TABELLE
 * ------------------------------------
 * `entitlement_limits` sagt, was ein Produkt erlaubt: `analyses` = 50 im Jahr,
 * `arrangements` = 10 zugleich. Das Addon kennt weiterhin kein Produkt; die
 * Zeile hängt am `product_slug` wie jede Freigabe auch, ohne Fremdschlüssel.
 *
 * `entitlement_usages` zählt, was ein Subject verbraucht hat, je Schlüssel und
 * Zeitraum. Gezählt wird am Subject, das den Zugang **hält**: nutzt ein
 * Teammitglied den Zugang seines Teams, steigt der Zähler des Teams.
 *
 * WARUM `period_key` EINE ZEICHENKETTE IST
 * -----------------------------------
 * Der Unique-Index über (Marke, Subject, Schlüssel, Zeitraum) ist das, was zwei
 * gleichzeitige erste Buchungen auf eine Zeile zwingt. Ein nullbarer Zeitraum
 * (für Bestandsgrenzen, die keinen haben) hebelte ihn aus: NULLs kollidieren in
 * keinem Unique-Index, auf keiner der drei Engines. Und ein `timestamp` mit einem
 * Platzhalterdatum scheitert an MySQLs Untergrenze 1970-01-01 00:00:01 UTC.
 * Also ein Schlüssel als Text: `stock` oder der Beginn des Zeitraums in ISO 8601.
 *
 * SCHLÜSSELLÄNGE
 * --------------
 * 160 + 64 + 64 + 32 Zeichen zu je vier Byte plus 8 für die Marke: 1288 von
 * InnoDBs 3072 Byte. Kein Präfix nötig.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlement_limits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->index();
            $table->string('product_slug', 191);
            $table->string('limit_key', 64);

            // NULL heisst unbegrenzt. 0 heisst: dieses Produkt erlaubt nichts
            // davon. Die beiden sind verschiedene Aussagen, und ChoirLives
            // `-1` für „unbegrenzt" war eine Zahl, mit der jemand rechnen konnte.
            $table->unsignedBigInteger('value')->nullable();

            // NULL heisst Bestand (der Aufrufer zählt, etwa aktive Projekte).
            // `month` oder `year` heisst Verbrauch je Zeitraum, gezählt hier.
            $table->string('period', 16)->nullable();

            $table->timestamps();

            $table->unique(['brand_id', 'product_slug', 'limit_key'], 'ent_limits_product_key_unique');
        });

        Schema::create('entitlement_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->index();
            $table->string('subject_type', 160);
            $table->string('subject_id', 64);
            $table->string('limit_key', 64);
            // Nicht `window`: das ist in MySQL 8 ein reserviertes Wort.
            $table->string('period_key', 32);

            $table->unsignedBigInteger('used')->default(0);

            // Die Grenze, die beim letzten Buchen galt. Nur zur Anzeige und für
            // die Nutzlast der Ereignisse; entschieden wird immer neu.
            $table->unsignedBigInteger('limit_value')->nullable();

            $table->dateTime('period_start')->nullable();
            $table->dateTime('period_end')->nullable();

            // Gesetzt von der einen Buchung, die die Grenze erreicht. Ein
            // bedingtes UPDATE darauf ist, was `LimitReached` genau einmal feuern
            // lässt, auch wenn zwei Prozesse gleichzeitig am letzten Platz sind.
            $table->dateTime('reached_at')->nullable();

            // Gesetzt, wenn der Zeitraum abgelaufen und das angekündigt ist.
            $table->dateTime('rolled_over_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['brand_id', 'subject_type', 'subject_id', 'limit_key', 'period_key'],
                'ent_usages_subject_key_window_unique',
            );
            $table->index(['period_end', 'rolled_over_at'], 'ent_usages_rollover_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_usages');
        Schema::dropIfExists('entitlement_limits');
    }
};
