<?php

use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Statamic\Facades\User;

/**
 * A person, not `user:<uuid>`.
 *
 * Design review, 03.10.2026: from the user page everything worked without an
 * ID, from Users > Entitlements nothing did. The listing, the grant and the
 * revocation named the person by her key, and searching "Clara" matched the
 * wrong row because only the key was searched.
 */
beforeEach(function () {
    config()->set('statamic.editions.pro', true);

    $this->clara = User::make()->email('clara.voss@example.test')->set('name', 'Clara Voss');
    $this->clara->save();

    $this->ben = User::make()->email('ben.albers@example.test')->set('name', 'Ben Albers');
    $this->ben->save();

    $this->admin = cpUserWith(['access cp', 'view entitlements', 'grant entitlements', 'revoke entitlements']);
});

it('names a user subject in the listing, links her page and keeps the key as a side note', function () {
    Entitlements::grant($this->clara, 'kurs', 'manual');

    $row = $this->actingAs($this->admin)
        ->getJson(cp_route('entitlements.index'))
        ->assertOk()
        ->json('data.0');

    expect($row['subject'])->toBe('Clara Voss')
        ->and($row['subject_email'])->toBe('clara.voss@example.test')
        ->and($row['subject_url'])->toBe(cp_route('users.edit', $this->clara->id()))
        ->and($row['subject_key'])->toBe('user:'.$this->clara->id());
});

it('keeps the resolver label for a subject that is not a user', function () {
    Entitlements::grant(new SubjectReference('team', '9'), 'kurs', 'manual');

    $row = $this->actingAs($this->admin)->getJson(cp_route('entitlements.index'))->json('data.0');

    expect($row['subject'])->toBe('team:9')
        ->and($row['subject_url'])->toBeNull();
});

it('finds a grant by the person\'s name and by her email', function () {
    Entitlements::grant($this->clara, 'kurs-c', 'manual');
    Entitlements::grant($this->ben, 'kurs-b', 'manual');

    $byName = $this->actingAs($this->admin)
        ->getJson(cp_route('entitlements.index', ['search' => 'Clara']))
        ->json('data');

    expect(collect($byName)->pluck('product_slug')->all())->toBe(['kurs-c']);

    $byMail = $this->actingAs($this->admin)
        ->getJson(cp_route('entitlements.index', ['search' => 'ben.albers@']))
        ->json('data');

    expect(collect($byMail)->pluck('product_slug')->all())->toBe(['kurs-b']);
});

it('still finds a grant by its product slug', function () {
    Entitlements::grant($this->clara, 'kurs-c', 'manual');
    Entitlements::grant($this->ben, 'anderes', 'manual');

    $rows = $this->actingAs($this->admin)
        ->getJson(cp_route('entitlements.index', ['search' => 'kurs']))
        ->json('data');

    expect(collect($rows)->pluck('product_slug')->all())->toBe(['kurs-c']);
});

it('filters the listing to one subject, and the page says whose grants these are', function () {
    Entitlements::grant($this->clara, 'kurs-c', 'manual');
    Entitlements::grant($this->ben, 'kurs-b', 'manual');

    $key = 'user:'.$this->clara->id();

    $rows = $this->actingAs($this->admin)
        ->getJson(cp_route('entitlements.index', ['subject' => $key]))
        ->json('data');

    expect(collect($rows)->pluck('product_slug')->all())->toBe(['kurs-c']);

    $this->actingAs($this->admin)
        ->get(cp_route('entitlements.index', ['subject' => $key]))
        ->assertInertia(fn ($page) => $page
            ->where('subject.key', $key)
            ->where('subject.label', 'Clara Voss')
        );
});

it('links "all grants" on the user page to the listing filtered to her', function () {
    Entitlements::grant($this->clara, 'kurs-c', 'manual');

    $this->actingAs($this->admin)
        ->getJson(cp_route('entitlements.user', ['userId' => $this->clara->id()]))
        ->assertJsonPath('indexUrl', cp_route('entitlements.index', ['subject' => 'user:'.$this->clara->id()]));
});

it('names the person on the grant', function () {
    $grant = Entitlements::grant($this->clara, 'kurs', 'manual');

    $this->actingAs($this->admin)
        ->get(cp_route('entitlements.show', ['entitlement' => $grant->getKey()]))
        ->assertInertia(fn ($page) => $page
            ->where('entitlement.subject_label', 'Clara Voss')
            ->where('entitlement.subject_email', 'clara.voss@example.test')
            ->where('entitlement.subject_url', cp_route('users.edit', $this->clara->id()))
        );
});

it('renders the revocation as its own page, whose button says what it does', function () {
    // Core's PublishForm page labels its button "Save" and takes no other
    // text; "Speichern" on a page that takes access away undersold it.
    $grant = Entitlements::grant($this->clara, 'kurs', 'manual');

    $this->actingAs($this->admin)
        ->get(cp_route('entitlements.revoke.form', ['entitlement' => $grant->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('entitlements::Entitlements/Revoke')
            ->where('submitUrl', cp_route('entitlements.revoke', ['entitlement' => $grant->getKey()]))
            ->where('cancelUrl', cp_route('entitlements.show', ['entitlement' => $grant->getKey()]))
        );
});

it('dates a revoked grant on the user page by its revocation', function () {
    $grant = Entitlements::grant($this->clara, 'kurs', 'manual');
    Entitlements::revoke($grant, 'Erstattet');

    $row = $this->actingAs($this->admin)
        ->getJson(cp_route('entitlements.user', ['userId' => $this->clara->id()]))
        ->json('rows.0');

    expect($row['revoked_at'])->not->toBeNull();
});

it('names the person on the revocation form', function () {
    $grant = Entitlements::grant($this->clara, 'kurs', 'manual');

    $this->actingAs($this->admin)
        ->get(cp_route('entitlements.revoke.form', ['entitlement' => $grant->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('title', fn ($title) => str_contains($title, 'Clara Voss')));
});
