<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Events\EntitlementGranted;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Fieldtypes\UserEntitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\Blueprints;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\Blueprint;
use Statamic\Facades\User;
use Statamic\Fields\Field;

/**
 * Granting to a person without knowing her ID.
 *
 * Adrian, 03.10.2026: "dafür muss ich ja die ganzen slugs und IDs auswendig
 * kennen". The grant form asked for a subject type and a primary key as free
 * text. It now picks a person through core's own user search, and the user
 * page carries her grants and a way to add one. Both write through the one
 * manual-grant path, so a grant from the user page is indistinguishable from
 * one made under Users > Entitlements.
 */
beforeEach(function () {
    // Two users in one test (the admin and the person granted to) need Pro.
    config()->set('statamic.editions.pro', true);
    config()->set('statamic.users.elevated_sessions_enabled', false);

    $this->person = User::make()->email('anna@example.test')->set('name', 'Anna Beispiel');
    $this->person->save();
});

function grantFormField(string $handle): ?array
{
    foreach (Blueprints::grant()->contents()['tabs'] as $tab) {
        foreach ($tab['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['handle'] === $handle) {
                    return $field['field'];
                }
            }
        }
    }

    return null;
}

it('asks for a person through core\'s user search by default', function () {
    $kind = grantFormField('subject_kind');
    $user = grantFormField('subject_user');

    expect($kind['default'])->toBe('user')
        ->and($user['type'])->toBe('users')
        ->and($user['max_items'])->toBe(1)
        // Type and ID remain for every subject that is not a user.
        ->and(grantFormField('subject_type')['if'] ?? null)->toBe(['subject_kind' => 'equals other'])
        ->and(grantFormField('subject_id')['if'] ?? null)->toBe(['subject_kind' => 'equals other']);
});

it('grants to the picked person under the reference every other path writes', function () {
    $admin = cpUserWith(['access cp', 'view entitlements', 'grant entitlements']);

    $this->actingAs($admin)
        ->post(cp_route('entitlements.store'), [
            'subject_kind' => 'user',
            'subject_user' => [$this->person->id()],
            'product_slug' => 'kurs',
        ])
        ->assertOk()
        ->assertJson(['saved' => true]);

    $grant = Entitlement::query()->firstOrFail();

    expect($grant->subjectKey())->toBe(Entitlements::reference($this->person)->key())
        ->and($grant->source)->toBe('manual')
        ->and(Entitlements::allows($this->person, 'kurs'))->toBeTrue();
});

it('refuses a person kind without a person, and a person who does not exist', function () {
    $admin = cpUserWith(['access cp', 'view entitlements', 'grant entitlements']);

    $this->actingAs($admin)
        ->postJson(cp_route('entitlements.store'), ['subject_kind' => 'user', 'product_slug' => 'kurs'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('subject_user');

    $this->actingAs($admin)
        ->postJson(cp_route('entitlements.store'), [
            'subject_kind' => 'user',
            'subject_user' => ['does-not-exist'],
            'product_slug' => 'kurs',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('subject_user');

    expect(Entitlement::query()->count())->toBe(0);
});

it('still takes type and ID for a subject that is not a user', function () {
    $admin = cpUserWith(['access cp', 'view entitlements', 'grant entitlements']);

    $this->actingAs($admin)
        ->post(cp_route('entitlements.store'), [
            'subject_kind' => 'other',
            'subject_type' => 'team',
            'subject_id' => '9',
            'product_slug' => 'kurs',
        ])
        ->assertOk();

    expect(Entitlement::query()->firstOrFail()->subjectKey())->toBe('team:9');
});

it('writes from the user page exactly what the manual grant writes', function () {
    $admin = cpUserWith(['access cp', 'view entitlements', 'grant entitlements']);
    $reference = Entitlements::reference($this->person);
    $starts = now()->addDay()->startOfMinute()->toIso8601ZuluString('millisecond');
    $expires = now()->addYear()->startOfMinute()->toIso8601ZuluString('millisecond');

    // The form under Users > Entitlements, typed by hand.
    $this->actingAs($admin)->post(cp_route('entitlements.store'), [
        'subject_kind' => 'other',
        'subject_type' => $reference->type,
        'subject_id' => $reference->id,
        'product_slug' => 'per-hand',
        'starts_at' => $starts,
        'expires_at' => $expires,
    ])->assertOk();

    // The user page's grant action: the same request shape it sends.
    $this->actingAs($admin)->post(cp_route('entitlements.store'), [
        'subject_kind' => 'user',
        'subject_user' => [$this->person->id()],
        'product_slug' => 'von-der-userseite',
        'starts_at' => $starts,
        'expires_at' => $expires,
    ])->assertOk();

    $hand = Entitlement::query()->where('product_slug', 'per-hand')->firstOrFail();
    $page = Entitlement::query()->where('product_slug', 'von-der-userseite')->firstOrFail();

    $comparable = fn (Entitlement $e) => collect($e->getAttributes())
        ->except(['id', 'product_slug', 'created_at', 'updated_at'])
        ->all();

    expect($comparable($page))->toBe($comparable($hand))
        ->and($page->source)->toBe('manual')
        ->and($page->state())->toBe(EntitlementState::Scheduled);
});

it('names the acting admin on a grant from the user page', function () {
    $admin = cpUserWith(['access cp', 'view entitlements', 'grant entitlements']);

    Event::fake([EntitlementGranted::class]);

    $this->actingAs($admin)->post(cp_route('entitlements.store'), [
        'subject_kind' => 'user',
        'subject_user' => [$this->person->id()],
        'product_slug' => 'kurs',
    ])->assertOk();

    Event::assertDispatched(EntitlementGranted::class, fn ($event) => $event->actor?->id === (string) $admin->id());
});

it('lists a person\'s grants for the user page, with names, state and links', function () {
    Entitlements::registerProducts(['kurs' => 'Der Kurs']);

    $grant = Entitlements::grant($this->person, 'kurs', 'manual', expiresAt: now()->addMonth());
    Entitlements::grant($this->person, 'alt', 'thrivecart', 'order-1');
    // Somebody else's grant must not show up.
    Entitlements::grant(new SubjectReference('user', 'jemand-anders'), 'kurs', 'manual');

    $admin = cpUserWith(['access cp', 'view entitlements', 'grant entitlements', 'revoke entitlements']);

    $response = $this->actingAs($admin)
        ->getJson(cp_route('entitlements.user', ['userId' => $this->person->id()]))
        ->assertOk()
        ->assertJsonPath('canGrant', true)
        ->assertJsonPath('canRevoke', true)
        ->json();

    expect($response['rows'])->toHaveCount(2)
        ->and(collect($response['products'])->firstWhere('value', 'kurs'))
        ->toBe(['value' => 'kurs', 'label' => 'Der Kurs', 'unknown' => false])
        ->and(collect($response['products'])->firstWhere('value', 'alt')['unknown'])->toBeTrue();

    $row = collect($response['rows'])->firstWhere('product_slug', 'kurs');

    expect($row['product_label'])->toBe('Der Kurs')
        ->and($row['product_unknown'])->toBeFalse()
        ->and($row['state'])->toBe('active')
        ->and($row['source'])->not->toBe('')
        ->and($row['expires_at'])->not->toBeNull()
        ->and($row['show_url'])->toBe(cp_route('entitlements.show', ['entitlement' => $grant->getKey()]))
        ->and($row['revoke_url'])->toBe(cp_route('entitlements.revoke', ['entitlement' => $grant->getKey()]))
        ->and($row['can_revoke'])->toBeTrue();

    expect(collect($response['rows'])->firstWhere('product_slug', 'alt')['product_unknown'])->toBeTrue();
});

it('gates the user page on the four permissions', function () {
    $grant = Entitlements::grant($this->person, 'kurs', 'manual');
    $url = cp_route('entitlements.user', ['userId' => $this->person->id()]);

    // No view: nothing, not even that grants exist.
    $this->actingAs(cpUserWith(['access cp']))->getJson($url)->assertForbidden();
});

it('lets a read-only user see the grants but offer neither action', function () {
    Entitlements::grant($this->person, 'kurs', 'manual');

    $this->actingAs(cpUserWith(['access cp', 'view entitlements']))
        ->getJson(cp_route('entitlements.user', ['userId' => $this->person->id()]))
        ->assertOk()
        ->assertJsonPath('canGrant', false)
        ->assertJsonPath('canRevoke', false)
        ->assertJsonPath('rows.0.can_revoke', false);
});

it('offers granting without revoking, and the reverse', function () {
    Entitlements::grant($this->person, 'kurs', 'manual');
    $url = cp_route('entitlements.user', ['userId' => $this->person->id()]);

    $this->actingAs(cpUserWith(['access cp', 'view entitlements', 'grant entitlements']))
        ->getJson($url)
        ->assertJsonPath('canGrant', true)
        ->assertJsonPath('canRevoke', false);
});

it('offers revoking without granting', function () {
    Entitlements::grant($this->person, 'kurs', 'manual');

    $this->actingAs(cpUserWith(['access cp', 'view entitlements', 'revoke entitlements']))
        ->getJson(cp_route('entitlements.user', ['userId' => $this->person->id()]))
        ->assertJsonPath('canGrant', false)
        ->assertJsonPath('canRevoke', true)
        ->assertJsonPath('rows.0.can_revoke', true);
});

it('answers 404 for a user that does not exist', function () {
    $this->actingAs(cpUserWith(['access cp', 'view entitlements']))
        ->getJson(cp_route('entitlements.user', ['userId' => 'niemand']))
        ->assertNotFound();
});

it('does not offer revoking a grant that is already revoked', function () {
    $grant = Entitlements::grant($this->person, 'kurs', 'manual');
    Entitlements::revoke($grant, 'Erstattet');

    $this->actingAs(cpUserWith(['access cp', 'view entitlements', 'revoke entitlements']))
        ->getJson(cp_route('entitlements.user', ['userId' => $this->person->id()]))
        ->assertJsonPath('rows.0.state', 'revoked')
        ->assertJsonPath('rows.0.can_revoke', false);
});

it('hands the user page section where to read, once it knows the person', function () {
    $this->actingAs(cpUserWith(['access cp', 'view entitlements', 'grant entitlements']));

    $meta = (new Field('zugaenge', ['type' => 'user_entitlements']))
        ->setParent($this->person)
        ->fieldtype()
        ->preload();

    expect($meta['canView'])->toBeTrue()
        ->and($meta['rowsUrl'])->toBe(cp_route('entitlements.user', ['userId' => $this->person->id()]))
        ->and($meta['storeUrl'])->toBe(cp_route('entitlements.store'))
        ->and($meta['userId'])->toBe((string) $this->person->id());
});

it('hands the section nothing to read without the view permission', function () {
    $this->actingAs(cpUserWith(['access cp']));

    $meta = (new Field('zugaenge', ['type' => 'user_entitlements']))
        ->setParent($this->person)
        ->fieldtype()
        ->preload();

    expect($meta['canView'])->toBeFalse()
        ->and($meta['rowsUrl'])->toBeNull();
});

it('finds the person from the user edit route when core sets no parent', function () {
    // Core's user edit screen builds its fields without a parent
    // (ExtractsFromUserFields), so the route is the only place that names her.
    $admin = cpUserWith(['access cp', 'view entitlements']);
    $admin->makeSuper()->save();

    Blueprint::make('user')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
        ['handle' => 'name', 'field' => ['type' => 'text']],
        ['handle' => 'zugaenge', 'field' => ['type' => 'user_entitlements']],
    ]]]]]])->save();

    try {
        $this->actingAs($admin)
            ->get(cp_route('users.edit', $this->person->id()))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('meta.zugaenge.userId', (string) $this->person->id())
                ->where('meta.zugaenge.rowsUrl', cp_route('entitlements.user', ['userId' => $this->person->id()]))
            );
    } finally {
        Blueprint::find('user')?->delete();
    }
});

it('stores nothing on the user', function () {
    // The section reads grants, it does not hold them. A value written to the
    // user here would be a second, stale copy of what the table says.
    $fieldtype = (new Field('zugaenge', ['type' => 'user_entitlements']))->fieldtype();

    expect($fieldtype)->toBeInstanceOf(UserEntitlements::class)
        ->and($fieldtype->process(['anything']))->toBeNull()
        ->and($fieldtype->preProcess('stale'))->toBeNull();
});
