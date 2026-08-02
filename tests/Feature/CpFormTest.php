<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\SubjectReference;

/**
 * The Control Panel screens, and the reason they are in v1 at all.
 *
 * In the source system a manual grant was an entry appended to a JSON array on
 * the user row — `manual_resource_grant_slugs` — with no source, no window, no
 * revocation, no history and no way to see it. Everything below exists to make
 * "who granted this, when, and why was it taken away" answerable.
 */
beforeEach(function () {
    $this->admin = cpUserWith([
        'access cp', 'view entitlements', 'grant entitlements', 'revoke entitlements',
    ]);

    $this->subject = new SubjectReference('user', '42');
});

it('shows the empty state before anything has been granted', function () {
    $this->actingAs($this->admin)
        ->get(cp_route('entitlements.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('entitlements::Entitlements/Index')
            ->where('hasAny', false)
            ->where('canGrant', true)
        );
});

it('feeds the listing the props core\'s <Listing> needs', function () {
    Entitlement::factory()->count(3)->create();

    $this->actingAs($this->admin)
        ->get(cp_route('entitlements.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('entitlements::Entitlements/Index')
            ->where('hasAny', true)
            ->has('initialColumns')
            ->has('filters')
            ->where('listingUrl', cp_route('entitlements.index'))
        );
});

it('answers the listing endpoint with data and columns on every response', function () {
    // `meta.columns` on every page is not optional: Listing.vue overwrites its
    // local columns from it each time, so a response without them empties the
    // table on page two.
    Entitlement::factory()->count(2)->create();

    $response = $this->actingAs($this->admin)
        ->getJson(cp_route('entitlements.index'))
        ->assertOk()
        ->json();

    expect($response)->toHaveKeys(['data', 'meta'])
        ->and($response['meta'])->toHaveKeys(['columns', 'current_page', 'last_page', 'per_page', 'total'])
        ->and($response['data'][0])->toHaveKeys(['id', 'product_slug', 'subject', 'state', 'state_label']);
});

it('shows the resolved state in the listing, not the stored status', function () {
    // The whole point. `status` never holds "scheduled" — that state exists only
    // as a relationship between starts_at and now — so a listing built on the
    // column would show this row as active.
    Entitlement::factory()->scheduled()->create(['product_slug' => 'presale']);

    $row = collect($this->actingAs($this->admin)->getJson(cp_route('entitlements.index'))->json('data'))
        ->firstWhere('product_slug', 'presale');

    expect($row['state'])->toBe(EntitlementState::Scheduled->value)
        ->and($row['grants_access'])->toBeFalse();
});

it('writes a manual grant from the form, with the manual source rather than one the admin typed', function () {
    $this->actingAs($this->admin)
        ->post(cp_route('entitlements.store'), [
            'subject_type' => 'user',
            'subject_id' => '42',
            'product_slug' => 'course-a',
            // Deliberately offered a source the form does not accept. A manual
            // grant has exactly one honest source; letting an admin type
            // "thrivecart" would put a fabricated purchase into the audit trail.
            'source' => 'thrivecart',
        ])
        ->assertOk()
        ->assertJson(['saved' => true]);

    $grant = Entitlement::query()->firstOrFail();

    expect($grant->source)->toBe('manual')
        ->and($grant->product_slug)->toBe('course-a')
        ->and($grant->subjectKey())->toBe('user:42')
        ->and($grant->state())->toBe(EntitlementState::Active);
});

it('refuses a grant with no subject or no product', function () {
    $this->actingAs($this->admin)
        ->postJson(cp_route('entitlements.store'), ['subject_type' => 'user'])
        ->assertStatus(422);

    expect(Entitlement::query()->count())->toBe(0);
});

it('creates a scheduled grant when the start date is in the future', function () {
    $this->actingAs($this->admin)
        ->post(cp_route('entitlements.store'), [
            'subject_type' => 'user',
            'subject_id' => '42',
            'product_slug' => 'presale',
            'starts_at' => now()->addMonth()->toIso8601ZuluString('millisecond'),
        ])
        ->assertOk();

    expect(Entitlement::query()->firstOrFail()->state())->toBe(EntitlementState::Scheduled);
});

it('refuses a revocation with no reason', function () {
    $grant = Entitlements::grant($this->subject, 'course-a', 'manual');

    $this->actingAs($this->admin)
        ->postJson(cp_route('entitlements.revoke', ['entitlement' => $grant->getKey()]), [])
        ->assertStatus(422);

    expect($grant->fresh()->grantsAccess())->toBeTrue();
});

it('revokes with a reason and records who did it', function () {
    $grant = Entitlements::grant($this->subject, 'course-a', 'manual');

    $this->actingAs($this->admin)
        ->post(cp_route('entitlements.revoke', ['entitlement' => $grant->getKey()]), [
            'reason' => 'Chargeback on order 1234',
        ])
        ->assertOk()
        ->assertJson(['saved' => true]);

    $grant->refresh();

    expect($grant->state())->toBe(EntitlementState::Revoked)
        ->and($grant->revoked_reason)->toBe('Chargeback on order 1234')
        ->and($grant->revoked_at)->not->toBeNull();
});

it('shows the grant, its resolved state and its timeline', function () {
    $grant = Entitlements::grant(
        $this->subject,
        'course-a',
        'manual',
        'ref-1',
        expiresAt: now()->addMonth(),
    );

    $this->actingAs($this->admin)
        ->get(cp_route('entitlements.show', ['entitlement' => $grant->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('entitlements::Entitlements/Show')
            ->where('entitlement.state', 'active')
            ->where('entitlement.grants_access', true)
            ->where('entitlement.source_ref', 'ref-1')
            ->where('canRevoke', true)
            ->where('canRestore', false)
            ->has('timeline')
        );
});

it('offers restore instead of revoke once a grant has been revoked', function () {
    $grant = Entitlements::grant($this->subject, 'course-a', 'manual');
    Entitlements::revoke($grant, 'Refunded');

    $this->actingAs($this->admin)
        ->get(cp_route('entitlements.show', ['entitlement' => $grant->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('entitlement.state', 'revoked')
            ->where('canRevoke', false)
            ->where('canRestore', true)
        );
});

it('hands the browser no configuration at all', function () {
    // A page prop is a page prop: whatever goes in it is in the HTML. Config
    // arrays are how a token ends up there.
    $grant = Entitlement::factory()->create();

    $props = $this->actingAs($this->admin)
        ->get(cp_route('entitlements.show', ['entitlement' => $grant->getKey()]))
        ->viewData('page')['props'];

    $encoded = json_encode($props);

    expect($encoded)->not->toContain('bridges')
        ->and($encoded)->not->toContain('subject_types')
        ->and($encoded)->not->toContain('per_page');
});

it('answers 404 for a grant that does not exist', function () {
    $this->actingAs($this->admin)
        ->get(cp_route('entitlements.show', ['entitlement' => 9999]))
        ->assertNotFound();
});
