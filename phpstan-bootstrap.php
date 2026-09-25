<?php

/**
 * Registers this addon's view namespace for Larastan.
 *
 * Larastan checks `->make('entitlements::mail.layout', …)` against the real
 * view finder (`view()->exists($literal)`). In an application the service
 * provider registers the namespace with `loadViewsFrom()`; while this package
 * is analysed on its own no provider runs, and a correct literal counts as a
 * bare string. Same gap and same fix as statamic-email-templates (2706f47): a
 * typo in a view name still fails the run, only now it can.
 *
 * Found on 25.09.2026: CI installs with `composer update` and gets a newer
 * Larastan that checks `view-string`; locally it was green.
 */

use Illuminate\Contracts\View\Factory as ViewFactory;
use Larastan\Larastan\ApplicationResolver;

$app = ApplicationResolver::resolve();

/** @var ViewFactory $views */
$views = $app->make(ViewFactory::class);

$views->getFinder()->addNamespace('entitlements', __DIR__.'/resources/views');
