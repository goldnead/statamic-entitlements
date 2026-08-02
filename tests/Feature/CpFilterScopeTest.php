<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Query\Scopes\Filters\EntitlementFilter;
use Goldnead\Entitlements\Query\Scopes\Filters\Product;
use Goldnead\Entitlements\Query\Scopes\Filters\Source;
use Goldnead\Entitlements\Query\Scopes\Filters\State;
use Goldnead\Entitlements\ServiceProvider;
use Statamic\Facades\Scope;

it('offers exactly the filters the listing page expects', function () {
    // Pins the list, so a filter that is added to the folder but never registered
    // — or registered and never shown — fails here rather than going quietly
    // missing from the panel.
    $handles = collect(Scope::filters(EntitlementFilter::LISTING_KEY))
        ->map(fn ($filter) => $filter->handle())
        ->sort()
        ->values()
        ->all();

    expect($handles)->toBe(['entitlements_product', 'entitlements_source', 'entitlements_state']);
});

it('registers every filter class the provider names', function () {
    expect(ServiceProvider::LISTING_FILTERS)->toHaveCount(3);

    foreach (ServiceProvider::LISTING_FILTERS as $class) {
        expect(class_exists($class))->toBeTrue()
            ->and(is_subclass_of($class, EntitlementFilter::class))->toBeTrue();
    }
});

it('shows none of them on any other listing', function () {
    // Statamic registers scopes globally and Filter::visibleTo() defaults to
    // true, so a filter that does not answer the question turns up on the
    // Entries, Assets and Users listings as well — a "State" dropdown on the
    // Entries screen that filters entries by a column they do not have.
    foreach (ServiceProvider::LISTING_FILTERS as $class) {
        $filter = new $class;

        expect($filter->visibleTo(EntitlementFilter::LISTING_KEY))->toBeTrue()
            ->and($filter->visibleTo('entries'))->toBeFalse()
            ->and($filter->visibleTo('users'))->toBeFalse()
            ->and($filter->visibleTo('assets'))->toBeFalse();
    }
});

/**
 * The state filter asks the resolver, not the `status` column. Three of the six
 * states are never stored, so a filter built on the column would offer "Expired"
 * and return nothing at all.
 */
it('filters by resolved state rather than by the stored status', function () {
    Entitlement::factory()->create(['product_slug' => 'active-one']);
    Entitlement::factory()->scheduled()->create(['product_slug' => 'scheduled-one']);
    Entitlement::factory()->expired()->create(['product_slug' => 'expired-one']);
    Entitlement::factory()->revoked()->create(['product_slug' => 'revoked-one']);

    $filter = new State;

    foreach ([
        EntitlementState::Active->value => 'active-one',
        EntitlementState::Scheduled->value => 'scheduled-one',
        EntitlementState::Expired->value => 'expired-one',
        EntitlementState::Revoked->value => 'revoked-one',
    ] as $state => $expected) {
        $query = Entitlement::query();
        $filter->apply($query, ['state' => $state]);

        expect($query->pluck('product_slug')->all())->toBe([$expected]);
    }
});

it('offers every state as an option, including the ones that are never stored', function () {
    $options = (new State)->fieldItems()['state']['options'];

    expect(array_keys($options))->toBe(array_map(fn ($case) => $case->value, EntitlementState::cases()));
});

it('ignores an unknown state instead of returning nothing', function () {
    Entitlement::factory()->count(3)->create();

    $query = Entitlement::query();
    (new State)->apply($query, ['state' => 'nonsense']);

    expect($query->count())->toBe(3);
});

it('offers every source in use, registered or not', function () {
    Entitlement::factory()->create(['source' => 'mollie']);
    Entitlement::factory()->create(['source' => 'a-source-nobody-registered']);

    $options = (new Source)->fieldItems()['source']['options'];

    // A dropdown built from config alone would hide the grant somebody is
    // actually looking for — which is the only reason to open the filter.
    expect($options)->toHaveKey('a-source-nobody-registered')
        ->and($options)->toHaveKey('manual');
});

it('filters by an exact product slug', function () {
    Entitlement::factory()->create(['product_slug' => 'course-a']);
    Entitlement::factory()->create(['product_slug' => 'course-a-extra']);

    $query = Entitlement::query();
    (new Product)->apply($query, ['product_slug' => 'course-a']);

    expect($query->pluck('product_slug')->all())->toBe(['course-a']);
});

it('ignores an empty product filter', function () {
    Entitlement::factory()->count(2)->create();

    $query = Entitlement::query();
    (new Product)->apply($query, ['product_slug' => '  ']);

    expect($query->count())->toBe(2);
});
