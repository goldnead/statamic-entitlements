<?php

/*
 * Boots the addon the way a site without the Webhook Manager, the automations
 * addon and email-templates does. Run in its own PHP process by
 * BootWithoutSiblingsTest: this repository has the first two as dev
 * dependencies, so they are hidden from the autoloader here. A provider or
 * bridge that touches one of their classes before checking the name dies with
 * "Interface not found" / "Class not found".
 *
 * Prints the bridge state on success; a fatal error ends the process with its
 * message.
 */

$loader = require __DIR__.'/../../vendor/autoload.php';

$hidden = ['Goldnead\\WebhookManager\\', 'Goldnead\\StatamicAutomations\\', 'Goldnead\\EmailTemplates\\'];

$loader->unregister();
spl_autoload_register(function (string $class) use ($loader, $hidden): void {
    foreach ($hidden as $prefix) {
        if (str_starts_with($class, $prefix)) {
            return;
        }
    }

    $loader->loadClass($class);
}, true, true);

foreach ([
    'Goldnead\\WebhookManager\\Facades\\WebhookManager',
    'Goldnead\\WebhookManager\\Contracts\\TriggerInterface',
    'Goldnead\\StatamicAutomations\\Facades\\Automations',
    'Goldnead\\StatamicAutomations\\Contracts\\AutomationTrigger',
] as $sibling) {
    if (class_exists($sibling) || interface_exists($sibling)) {
        fwrite(STDERR, "precondition: {$sibling} is reachable, this check proves nothing\n");
        exit(2);
    }
}

use Goldnead\BrandContext\ServiceProvider;
use Goldnead\Entitlements\Events\LimitReached;
use Goldnead\Entitlements\Integrations\Automations\AutomationsBridge;
use Goldnead\Entitlements\Integrations\EmailTemplates\MailTemplates;
use Goldnead\Entitlements\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Entitlements\Support\SubjectReference;
use Orchestra\Testbench\Foundation\Application;

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

$app->register(ServiceProvider::class);
$app->register(Goldnead\IdentityContracts\ServiceProvider::class);
$app->register(Goldnead\Entitlements\ServiceProvider::class);

// The moment the bridges would hear. Nothing may reach for a sibling.
$app['events']->dispatch(new LimitReached(
    new SubjectReference('user', '1'), new SubjectReference('user', '1'), 'analyses', 1, 1, 'usage',
));

echo 'booted'
    .', webhook bridge '.($app->make(WebhookManagerBridge::class)->booted() ? 'on' : 'off')
    .', automations bridge '.($app->make(AutomationsBridge::class)->registered() ? 'on' : 'off')
    .', email templates '.(MailTemplates::installed() ? 'yes' : 'no')."\n";
