<?php

use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\Blueprints;
use Goldnead\Entitlements\Support\ProductCatalog;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Facades\Log;

/**
 * The catalogue: names for the slugs other addons know, so nobody has to type
 * one from memory. Without a catalogue every field stays the free text it was.
 */
function productField(array $blueprint): ?array
{
    foreach ($blueprint['tabs'] as $tab) {
        foreach ($tab['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['handle'] === 'product_slug') {
                    return $field['field'];
                }
            }
        }
    }

    return null;
}

it('keeps the product field free text when nothing is registered', function () {
    expect(productField(Blueprints::grant()->contents())['type'])->toBe('text')
        ->and(productField(Blueprints::limits(creating: true)->contents())['type'])->toBe('text');
});

it('keeps it free text when the registered sources answer with nothing', function () {
    // statamic-products installed, no access created yet: there is nothing
    // to pick, and an empty picker would make granting impossible.
    Entitlements::registerProducts(fn () => []);

    expect(productField(Blueprints::grant()->contents())['type'])->toBe('text');
});

it('offers a picker with names once a source registers products', function () {
    Entitlements::registerProducts(fn () => [
        'choiraccelerator' => 'Choir Accelerator',
        'masterclass' => ['label' => 'Masterclass', 'group' => 'Adrian Goldner'],
    ]);

    foreach ([Blueprints::grant(), Blueprints::limits(creating: true)] as $blueprint) {
        $field = productField($blueprint->contents());

        expect($field['type'])->toBe('select')
            // Unknown slugs stay possible: the catalogue names, it does not decide.
            ->and($field['taggable'])->toBeTrue()
            ->and($field['options'])->toMatchArray([
                'choiraccelerator' => 'Choir Accelerator',
                'masterclass' => 'Masterclass · Adrian Goldner',
            ]);
    }
});

it('offers a slug already in use that no source knows, flagged', function () {
    Entitlements::registerProducts(['kurs-neu' => 'Neuer Kurs']);
    Entitlement::factory()->create(['product_slug' => 'legacy-slug']);

    $options = productField(Blueprints::grant()->contents())['options'];

    expect($options)->toHaveKey('kurs-neu')
        ->and($options)->toHaveKey('legacy-slug')
        ->and($options['legacy-slug'])->not->toBe('legacy-slug')
        ->and($options['legacy-slug'])->toContain('legacy-slug');
});

it('reads a closure only when asked, never at registration', function () {
    // A sibling's products live in a table that may not exist while providers
    // boot. Reading at registration would be a crash before `migrate`.
    $read = 0;

    Entitlements::registerProducts(function () use (&$read) {
        $read++;

        return ['a' => 'A'];
    });

    expect($read)->toBe(0);

    Entitlements::grantableProducts();

    expect($read)->toBe(1);
});

it('skips a source that throws, logs it, and keeps the others', function () {
    Log::spy();

    Entitlements::registerProducts(fn () => throw new RuntimeException('no such table: product_accesses'));
    Entitlements::registerProducts(['b' => 'B']);

    expect(array_keys(Entitlements::grantableProducts()))->toBe(['b']);

    Log::shouldHaveReceived('warning')->once();
});

it('lets the first source win when two name the same slug', function () {
    Entitlements::registerProducts(['a' => 'First']);
    Entitlements::registerProducts(['a' => 'Second']);

    expect(Entitlements::grantableProducts()['a']['label'])->toBe('First');
});

it('takes an object with grantableProducts() and a tagged class', function () {
    $source = new class
    {
        public function grantableProducts(): array
        {
            return ['x' => 'X'];
        }
    };

    Entitlements::registerProducts($source);

    app()->bind('tagged-source', fn () => new class
    {
        public function grantableProducts(): array
        {
            return ['y' => 'Y'];
        }
    });
    app()->tag('tagged-source', ProductCatalog::TAG);

    expect(array_keys(Entitlements::grantableProducts()))->toBe(['x', 'y']);
});

it('refuses something that is no source at all', function () {
    Entitlements::registerProducts(42);
})->throws(InvalidArgumentException::class);

it('labels a slug, and flags it only when a catalogue exists', function () {
    $catalog = app(ProductCatalog::class);

    expect($catalog->label('kurs'))->toBe('kurs')
        ->and($catalog->isUnknown('kurs'))->toBeFalse();

    Entitlements::registerProducts(['kurs' => 'Der Kurs']);

    expect($catalog->label('kurs'))->toBe('Der Kurs')
        ->and($catalog->isUnknown('kurs'))->toBeFalse()
        ->and($catalog->isUnknown('alt'))->toBeTrue();
});

it('still grants a slug nobody registered', function () {
    // Names, never a whitelist.
    Entitlements::registerProducts(['kurs' => 'Der Kurs']);

    $admin = cpUserWith(['access cp', 'view entitlements', 'grant entitlements']);

    $this->actingAs($admin)
        ->post(cp_route('entitlements.store'), [
            'subject_kind' => 'other',
            'subject_type' => 'contact',
            'subject_id' => '7',
            'product_slug' => 'nirgends-registriert',
        ])
        ->assertOk();

    expect(Entitlement::query()->firstOrFail()->product_slug)->toBe('nirgends-registriert');
});

it('shows the product name on the grant and flags an unknown slug', function () {
    Entitlements::registerProducts(['kurs' => 'Der Kurs']);

    $known = Entitlements::grant(new SubjectReference('contact', '1'), 'kurs', 'manual');
    $unknown = Entitlements::grant(new SubjectReference('contact', '1'), 'alt', 'manual');

    $admin = cpUserWith(['access cp', 'view entitlements']);

    $this->actingAs($admin)
        ->get(cp_route('entitlements.show', ['entitlement' => $known->getKey()]))
        ->assertInertia(fn ($page) => $page
            ->where('entitlement.product_label', 'Der Kurs')
            ->where('entitlement.product_unknown', false)
        );

    $this->actingAs($admin)
        ->get(cp_route('entitlements.show', ['entitlement' => $unknown->getKey()]))
        ->assertInertia(fn ($page) => $page
            ->where('entitlement.product_label', 'alt')
            ->where('entitlement.product_unknown', true)
        );
});
