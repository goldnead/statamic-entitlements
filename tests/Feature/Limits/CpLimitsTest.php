<?php

use Goldnead\Entitlements\Events\UsageReset;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Facades\Event;

/**
 * Grenzen am Produkt pflegen, Verbrauch am Subjekt sehen, Verdrahtung zeigen.
 */
beforeEach(function () {
    // Two CP users in one test: Solo refuses the second one.
    config()->set('statamic.editions.pro', true);

    $this->manager = cpUserWith(['access cp', 'view entitlements', 'manage entitlements limits']);
    $this->reader = cpUserWith(['access cp', 'view entitlements']);
    $this->anna = new SubjectReference('user', '1');
});

it('lists products with limits and products somebody holds, with an edit link only for managers', function () {
    Entitlements::setLimits('chor', ['analyses' => ['value' => 50, 'period' => 'year']]);
    Entitlements::grant($this->anna, 'solo', 'manual');

    $this->actingAs($this->manager)
        ->get(cp_route('entitlements.limits.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('entitlements::Limits/Index')
            ->has('rows', 2)
            ->where('rows.0.product_slug', 'chor')
            ->where('rows.0.limits.0.key', 'analyses')
            ->where('rows.1.product_slug', 'solo')
            ->where('rows.1.grants', 1)
            ->where('canManage', true)
            ->where('rows.0.edit_url', cp_route('entitlements.limits.edit', ['product' => 'chor']))
        );

    $this->actingAs($this->reader)
        ->get(cp_route('entitlements.limits.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManage', false)->where('rows.0.edit_url', null));
});

it('keeps the forms and writes behind the limits permission', function () {
    $this->actingAs($this->reader)->get(cp_route('entitlements.limits.create'))->assertForbidden();
    $this->actingAs($this->reader)->get(cp_route('entitlements.limits.edit', ['product' => 'chor']))->assertForbidden();
    $this->actingAs($this->reader)->post(cp_route('entitlements.limits.store'), ['product_slug' => 'x'])->assertForbidden();
    $this->actingAs($this->reader)->patch(cp_route('entitlements.limits.update', ['product' => 'chor']), [])->assertForbidden();
    $this->actingAs($this->reader)->post(cp_route('entitlements.usage.reset'), [])->assertForbidden();
});

it('stores limits from the form, unlimited as its own toggle', function () {
    $this->actingAs($this->manager)
        ->post(cp_route('entitlements.limits.store'), [
            'product_slug' => 'chor',
            'limits' => [
                ['limit_key' => 'analyses', 'value' => 50, 'unlimited' => false, 'period' => 'year'],
                ['limit_key' => 'arrangements', 'value' => null, 'unlimited' => true, 'period' => 'stock'],
            ],
        ])
        ->assertOk()
        ->assertJson(['saved' => true]);

    expect(Entitlements::limitsFor('chor'))->toBe([
        'analyses' => ['value' => 50, 'period' => 'year'],
        'arrangements' => ['value' => null, 'period' => null],
    ]);
});

it('refuses an empty number that is not marked unlimited, rather than guessing', function () {
    $this->actingAs($this->manager)
        ->postJson(cp_route('entitlements.limits.store'), [
            'product_slug' => 'chor',
            'limits' => [['limit_key' => 'analyses', 'value' => null, 'unlimited' => false, 'period' => 'year']],
        ])
        ->assertStatus(422);

    expect(Entitlements::limitsFor('chor'))->toBe([]);
});

it('edits and removes limits of a product', function () {
    Entitlements::setLimits('chor', ['analyses' => 50, 'exports' => 5]);

    $this->actingAs($this->manager)->get(cp_route('entitlements.limits.edit', ['product' => 'chor']))->assertOk();

    $this->actingAs($this->manager)
        ->patch(cp_route('entitlements.limits.update', ['product' => 'chor']), [
            'limits' => [['limit_key' => 'analyses', 'value' => 60, 'unlimited' => false, 'period' => 'month']],
        ])
        ->assertOk();

    expect(Entitlements::limitsFor('chor'))->toBe(['analyses' => ['value' => 60, 'period' => 'month']]);
});

it('shows the subject\'s limits on a grant and resets a counter', function () {
    Event::fake([UsageReset::class]);
    Entitlements::setLimits('chor', ['analyses' => ['value' => 3, 'period' => 'year']]);
    $grant = Entitlements::grant($this->anna, 'chor', 'manual');
    Entitlements::consume($this->anna, 'analyses', 3);

    $this->actingAs($this->manager)
        ->get(cp_route('entitlements.show', ['entitlement' => $grant->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('quotas.0.key', 'analyses')
            ->where('quotas.0.used', 3)
            ->where('quotas.0.remaining', 0)
            ->where('quotas.0.can_reset', true)
            ->where('quotas.0.reset', ['subject_type' => 'user', 'subject_id' => '1', 'key' => 'analyses'])
        );

    $this->actingAs($this->manager)
        ->post(cp_route('entitlements.usage.reset'), ['subject_type' => 'user', 'subject_id' => '1', 'key' => 'analyses'])
        ->assertRedirect();

    expect(Entitlements::remaining($this->anna, 'analyses'))->toBe(3);
    Event::assertDispatched(UsageReset::class, fn (UsageReset $e) => $e->actor !== null && $e->reason === 'manual');
});

it('shows the wiring: every event, the mail and whether siblings are there', function () {
    $this->actingAs($this->reader)
        ->get(cp_route('entitlements.wiring'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('entitlements::Wiring')
            ->has('events', 8)
            ->where('events.5.handle', 'entitlements.limit_reached')
            ->where('events.5.mail.slug', 'entitlements-limit-reached')
            ->where('events.5.mail.enabled', false)
            ->where('events.0.automations_owner', 'automations')
            ->has('automations.installed')
            ->has('webhooks.installed')
        );
});
