<?php

use Goldnead\BrandContext\Contracts\ProvidesSimPersons;
use Goldnead\BrandContext\Simulation\PersonSources;
use Goldnead\Entitlements\Integrations\SimPersonSource;
use Goldnead\Entitlements\Models\Entitlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\User;

/*
 * The access a real person holds, offered to the dry run as a person source. Read only: nothing is written, and the
 * e-mail address never goes to a log. The CRM finds the person; this source only answers for the identity it is given.
 */

const SIM_MAIL = 'lena@example.test';

function simSource(): SimPersonSource
{
    return app(SimPersonSource::class);
}

it('offers its access under its own name and finds nobody', function () {
    expect(simSource()->personSourceId())->toBe('entitlements')
        ->and(simSource()->searchPersons('Lena', 8))->toBe([])
        ->and(simSource()->identifyPerson('7'))->toBeNull();
});

it('offers the access of the e-mail subject and of the user with that e-mail, and only what gives access now', function () {
    $user = User::make()->email(SIM_MAIL);
    $user->save();

    Entitlement::factory()->create(['subject_type' => 'email', 'subject_id' => SIM_MAIL, 'product_slug' => 'kurs-mail']);
    Entitlement::factory()->create(['subject_type' => 'user', 'subject_id' => (string) $user->id(), 'product_slug' => 'kurs-user']);

    expect(simSource()->personFacet(['email' => SIM_MAIL, 'id' => 7])['access'])
        ->toEqualCanonicalizing(['kurs-mail', 'kurs-user']);
});

it('leaves out revoked, expired, scheduled and pending grants', function () {
    Entitlement::factory()->create(['subject_type' => 'email', 'subject_id' => SIM_MAIL, 'product_slug' => 'aktiv']);
    Entitlement::factory()->create(['subject_type' => 'email', 'subject_id' => SIM_MAIL, 'product_slug' => 'widerrufen', 'revoked_at' => now(), 'status' => 'revoked']);
    Entitlement::factory()->expired()->create(['subject_type' => 'email', 'subject_id' => SIM_MAIL, 'product_slug' => 'abgelaufen']);
    Entitlement::factory()->scheduled()->create(['subject_type' => 'email', 'subject_id' => SIM_MAIL, 'product_slug' => 'geplant']);
    Entitlement::factory()->pending()->create(['subject_type' => 'email', 'subject_id' => SIM_MAIL, 'product_slug' => 'wartend']);

    expect(simSource()->personFacet(['email' => SIM_MAIL])['access'])->toBe(['aktiv']);
});

it('keeps a grant in its grace period', function () {
    Entitlement::factory()->inGracePeriod()->create(['subject_type' => 'email', 'subject_id' => SIM_MAIL, 'product_slug' => 'gnadenfrist']);

    expect(simSource()->personFacet(['email' => SIM_MAIL])['access'])->toBe(['gnadenfrist']);
});

it('leaves out the grants of somebody else', function () {
    Entitlement::factory()->create(['subject_type' => 'email', 'subject_id' => 'anna@example.test', 'product_slug' => 'fremd']);

    expect(simSource()->personFacet(['email' => SIM_MAIL])['access'])->toBe([]);
});

it('names each slug once, and hands over at most fifty', function () {
    // Two grants of one product (a renewal and a refund, say): the unique key needs a different source_ref.
    Entitlement::factory()->create(['subject_type' => 'email', 'subject_id' => SIM_MAIL, 'product_slug' => 'doppelt', 'source_ref' => 'tr_1']);
    Entitlement::factory()->create(['subject_type' => 'email', 'subject_id' => SIM_MAIL, 'product_slug' => 'doppelt', 'source_ref' => 'tr_2']);

    for ($i = 0; $i < 55; $i++) {
        Entitlement::factory()->create(['subject_type' => 'email', 'subject_id' => SIM_MAIL, 'product_slug' => "kurs-{$i}"]);
    }

    $access = simSource()->personFacet(['email' => SIM_MAIL])['access'];

    expect($access)->toHaveCount(50)
        ->and(array_count_values($access)['doppelt'] ?? 0)->toBe(1);
});

it('hands over nothing without an e-mail', function () {
    Entitlement::factory()->create(['subject_type' => 'email', 'subject_id' => SIM_MAIL, 'product_slug' => 'aktiv']);

    expect(simSource()->personFacet(['id' => 7]))->toBe([])
        ->and(simSource()->personFacet([]))->toBe([]);
});

it('allows the access listing to the user who may open it, and nobody else', function () {
    expect(simSource()->personsAuthorized(null))->toBeFalse()
        ->and(simSource()->personsAuthorized(cpUserWith(['grant entitlements'])))->toBeFalse()
        ->and(simSource()->personsAuthorized(cpUserWith(['view entitlements'])))->toBeTrue();
});

it('writes nothing and logs nothing about the person', function () {
    $user = User::make()->email(SIM_MAIL);
    $user->save();
    Entitlement::factory()->create(['subject_type' => 'email', 'subject_id' => SIM_MAIL, 'product_slug' => 'aktiv']);
    Entitlement::factory()->create(['subject_type' => 'user', 'subject_id' => (string) $user->id(), 'product_slug' => 'kurs-user']);

    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (! preg_match('/^\s*(select|pragma)\b/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });

    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        $logged[] = $message->message.' '.json_encode($message->context);
    });

    simSource()->personFacet(['email' => SIM_MAIL, 'id' => 7]);

    expect($writes)->toBe([])
        ->and(implode("\n", $logged))->not->toContain(SIM_MAIL);
});

it('is found by the dry run next to the CRM, and copies its access into the person', function () {
    // A stand-in CRM: it finds the person and gives her identity; the access comes from the source above.
    $crm = new class implements ProvidesSimPersons
    {
        public function personSourceId(): string
        {
            return 'crm';
        }

        public function personsAuthorized($user): bool
        {
            return $user !== null;
        }

        public function searchPersons(string $query, int $limit): array
        {
            return [];
        }

        public function identifyPerson(string $key): ?array
        {
            return $key === '7' ? ['email' => SIM_MAIL, 'id' => 7] : null;
        }

        public function personFacet(array $identity): array
        {
            return [];
        }
    };

    Entitlement::factory()->create(['subject_type' => 'email', 'subject_id' => SIM_MAIL, 'product_slug' => 'kurs-stimme']);

    $sources = app(PersonSources::class);
    $sources->register($crm::class);

    $person = $sources->state('crm|7', cpUserWith(['view entitlements']));

    expect($person->isKnown())->toBeTrue()
        ->and($person->access)->toBe(['kurs-stimme']);
})->skip(! class_exists(PersonSources::class), 'statamic-brand-context has no person sources.');
