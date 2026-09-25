<?php

namespace Goldnead\Entitlements\Http\Controllers\Cp;

use Goldnead\Entitlements\Integrations\Automations\AutomationsBridge;
use Goldnead\Entitlements\Integrations\EmailTemplates\MailTemplates;
use Goldnead\Entitlements\Integrations\EventCatalog;
use Goldnead\Entitlements\Integrations\WebhookManager\WebhookManagerBridge;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Throwable;

/**
 * What is wired to what: every event this addon fires, the mail that goes
 * with it, and how many automations and outbound webhooks listen.
 *
 * The Webhook Manager keeps a catalogue of every registered trigger on its
 * debug page, and this page links there. Automations lists triggers only
 * inside the flow editor's node library. What neither shows is this addon's
 * mail per event and how many flows and hooks listen to each, so that is what
 * this page adds.
 *
 * The counts come from the siblings' tables, read by name and guarded by
 * `Schema::hasTable`: this page must not load a sibling's class to count its
 * rows. They count across brands — a flow or a hook is configured once.
 */
class WiringController extends Controller
{
    public function index()
    {
        Gate::authorize('view entitlements');

        $automations = AutomationsBridge::installed();
        $webhooks = WebhookManagerBridge::installed();
        $flows = $automations ? $this->flowCounts() : [];
        $hooks = $webhooks ? $this->hookCounts() : [];
        $mailOn = (bool) config('entitlements.mail.limit_reached.enabled', false);

        $events = [];

        foreach (EventCatalog::MOMENTS as $moment => [$class, $owner]) {
            $handle = EventCatalog::handle($moment);
            $mail = null;

            if (isset(EventCatalog::MAILS[$moment])) {
                $slug = (string) config(EventCatalog::MAILS[$moment].'.template', MailTemplates::defaults($moment)['slug']);
                $mail = [
                    'slug' => $slug,
                    'enabled' => $mailOn,
                    'source' => MailTemplates::installed()
                        ? (MailTemplates::entryExists($slug) ? 'entry' : 'default')
                        : 'view',
                    'edit_url' => MailTemplates::editUrl($slug),
                ];
            }

            $events[] = [
                'handle' => $handle,
                'label' => __('entitlements::events.'.$moment.'.label'),
                'description' => __('entitlements::events.'.$moment.'.description'),
                'class' => class_basename($class),
                'mail' => $mail,
                'automations_owner' => $owner,
                'flows' => $flows[$handle] ?? 0,
                'hooks' => $hooks[$handle] ?? 0,
                'flows_text' => trans_choice('entitlements::cp.wiring_listening', $flows[$handle] ?? 0),
                'hooks_text' => trans_choice('entitlements::cp.wiring_listening', $hooks[$handle] ?? 0),
            ];
        }

        return Inertia::render('entitlements::Wiring', [
            'events' => $events,
            'automations' => [
                'installed' => $automations,
                'enabled' => AutomationsBridge::available(),
                'url' => $this->route('statamic.cp.statamic-automations.automations.index'),
            ],
            'webhooks' => [
                'installed' => $webhooks,
                'enabled' => WebhookManagerBridge::available(),
                'url' => $this->route('statamic.cp.webhook-manager.outbound.index'),
                'create_url' => $this->route('statamic.cp.webhook-manager.outbound.create'),
                // The manager's own trigger catalogue (its debug page lists
                // every registered trigger); linked, not copied.
                'catalogue_url' => $this->route('statamic.cp.webhook-manager.debug'),
            ],
            'emailTemplates' => [
                'installed' => MailTemplates::installed(),
                'url' => MailTemplates::installed() ? $this->collectionUrl() : null,
            ],
            'settingsUrl' => $this->route('statamic.cp.brand-context.settings.index'),
        ]);
    }

    /** @return array<string, int> handle => enabled automations starting on it */
    private function flowCounts(): array
    {
        try {
            if (! Schema::hasTable('automation_nodes') || ! Schema::hasTable('automations')) {
                return [];
            }

            return DB::table('automation_nodes')
                ->join('automations', 'automations.id', '=', 'automation_nodes.automation_id')
                ->where('automations.enabled', true)
                ->where('automation_nodes.type', 'like', EventCatalog::PREFIX.'%')
                ->selectRaw('automation_nodes.type as handle, count(distinct automations.id) as aggregate')
                ->groupBy('automation_nodes.type')
                ->pluck('aggregate', 'handle')
                ->map(fn ($n) => (int) $n)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, int> handle => enabled outbound hooks on it */
    private function hookCounts(): array
    {
        try {
            if (! Schema::hasTable('webhook_outbounds')) {
                return [];
            }

            return DB::table('webhook_outbounds')
                ->where('enabled', true)
                ->where('trigger_type', 'like', EventCatalog::PREFIX.'%')
                ->selectRaw('trigger_type as handle, count(*) as aggregate')
                ->groupBy('trigger_type')
                ->pluck('aggregate', 'handle')
                ->map(fn ($n) => (int) $n)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function route(string $name): ?string
    {
        return Route::has($name) ? route($name) : null;
    }

    private function collectionUrl(): ?string
    {
        return Route::has('statamic.cp.collections.entries.index')
            ? route('statamic.cp.collections.entries.index', MailTemplates::COLLECTION)
            : null;
    }
}
