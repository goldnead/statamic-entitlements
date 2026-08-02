<?php

use Goldnead\BrandContext\Models\Brand;
use Goldnead\Entitlements\Contracts\PackageResolver;
use Goldnead\Entitlements\Contracts\SubjectResolver;
use Goldnead\Entitlements\EntitlementManager;
use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\AccessDecision;
use Goldnead\Entitlements\Support\SubjectReference;

beforeEach(function () {
    $this->subject = new SubjectReference('user', '42');
});

it('answers with a machine-readable reason, not just a boolean', function () {
    Entitlements::grant($this->subject, 'course-a', 'manual');

    $decision = Entitlements::decide($this->subject, 'course-a');

    expect($decision->allowed)->toBeTrue()
        ->and($decision->reason)->toBe(AccessDecision::ENTITLED)
        ->and($decision->state)->toBe(EntitlementState::Active)
        ->and($decision->toArray())->toBe([
            'allowed' => true,
            'reason' => 'ENTITLED',
            'state' => 'active',
        ]);
});

it('refuses with no grant at all and says nothing about a state', function () {
    $decision = Entitlements::decide($this->subject, 'course-a');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reason)->toBe(AccessDecision::NOT_ENTITLED)
        ->and($decision->state)->toBeNull();
});

it('explains a refusal with the state of the closest grant', function () {
    // "Your access ran out on the 3rd" is a support conversation. "No" is a
    // ticket.
    Entitlements::grant($this->subject, 'course-a', 'manual', expiresAt: now()->subDay());

    $decision = Entitlements::decide($this->subject, 'course-a');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->state)->toBe(EntitlementState::Expired)
        ->and($decision->entitlement)->not->toBeNull();
});

/**
 * The OR over several grants, which is load-bearing.
 *
 * A customer who bought a product twice and had one purchase refunded still has
 * the other. An expired trial next to a paid licence locks nobody out. The
 * source system's de-duplication migration is only lossless because of this
 * property, so it is asserted rather than assumed.
 */
it('grants access when any one grant allows it, however many do not', function () {
    Entitlements::grant($this->subject, 'course-a', 'trial', 'trial-1', expiresAt: now()->subMonth());
    $refunded = Entitlements::grant($this->subject, 'course-a', 'thrivecart', 'evt_1');
    Entitlements::revoke($refunded, 'Refunded');
    Entitlements::grantPending($this->subject, 'course-a', 'newsletter_optin', 'course-a');
    Entitlements::grant($this->subject, 'course-a', 'mollie', 'tr_9');

    expect(Entitlement::query()->count())->toBe(4)
        ->and(Entitlements::allows($this->subject, 'course-a'))->toBeTrue();
});

it('refuses when every grant is in a state that grants nothing', function () {
    Entitlements::grant($this->subject, 'course-a', 'trial', 't1', expiresAt: now()->subMonth());
    Entitlements::grantPending($this->subject, 'course-a', 'newsletter_optin', 'course-a');
    Entitlements::grant($this->subject, 'course-a', 'presale', 'p1', startsAt: now()->addMonth());

    expect(Entitlements::allows($this->subject, 'course-a'))->toBeFalse();
});

it('keeps two subjects apart', function () {
    $other = new SubjectReference('user', '43');

    Entitlements::grant($this->subject, 'course-a', 'manual');

    expect(Entitlements::allows($this->subject, 'course-a'))->toBeTrue()
        ->and(Entitlements::allows($other, 'course-a'))->toBeFalse();
});

it('keeps the same id apart under two subject types', function () {
    // The pair is the identity, not the id. A user 42 and a contact 42 are two
    // different people, and a package that only compared ids would hand one of
    // them the other's access.
    $user = new SubjectReference('user', '42');
    $contact = new SubjectReference('contact', '42');

    Entitlements::grant($user, 'course-a', 'manual');

    expect(Entitlements::allows($user, 'course-a'))->toBeTrue()
        ->and(Entitlements::allows($contact, 'course-a'))->toBeFalse();
});

it('lists every product a subject can currently reach, and no others', function () {
    Entitlements::grant($this->subject, 'course-a', 'manual');
    Entitlements::grant($this->subject, 'course-b', 'manual', expiresAt: now()->subDay());
    Entitlements::grantPending($this->subject, 'course-c', 'newsletter_optin', 'course-c');
    Entitlements::grant($this->subject, 'course-d', 'manual', startsAt: now()->addWeek());

    expect(Entitlements::activeProductSlugsFor($this->subject))->toBe(['course-a']);
});

/**
 * The bundle extension point. Without one bound, bundles do not exist — which is
 * the correct default for a package that deliberately has no product catalogue.
 */
it('ignores bundles until a package resolver is bound', function () {
    Entitlements::grant($this->subject, 'bundle-all', 'thrivecart', 'evt_1');

    expect(Entitlements::allows($this->subject, 'course-a'))->toBeFalse();

    app()->bind(PackageResolver::class, fn () => new class implements PackageResolver
    {
        public function packagesContaining(string $productSlug): array
        {
            return $productSlug === 'course-a' ? ['bundle-all'] : [];
        }
    });

    // The manager is a singleton holding the previously resolved null object, so
    // a fresh one is what a real request with the binding in place would get.
    app()->forgetInstance(EntitlementManager::class);

    expect(app(EntitlementManager::class)->allows($this->subject, 'course-a'))->toBeTrue();
});

it('accepts an eloquent model as a subject through the morph map', function () {
    $brand = $this->makeBrand('acme');

    Entitlements::grant($brand, 'course-a', 'manual');

    $grant = Entitlement::query()->firstOrFail();

    expect($grant->subject_type)->toBe($brand->getMorphClass())
        ->and($grant->subject_id)->toBe((string) $brand->getKey())
        ->and(Entitlements::allows($brand, 'course-a'))->toBeTrue();
});

it('refuses to grant to an unsaved model rather than writing a grant to nobody', function () {
    $unsaved = new Brand;

    expect(fn () => Entitlements::grant($unsaved, 'course-a', 'manual'))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a subject it cannot make sense of', function () {
    expect(fn () => Entitlements::grant('just-a-string', 'course-a', 'manual'))
        ->toThrow(InvalidArgumentException::class);
});

it('lets a consumer teach it about its own kind of subject', function () {
    app()->bind(SubjectResolver::class, fn () => new class implements SubjectResolver
    {
        public function reference(mixed $subject): SubjectReference
        {
            return new SubjectReference('licence', (string) $subject);
        }

        public function label(SubjectReference $reference): ?string
        {
            return 'Licence '.$reference->id;
        }
    });

    app()->forgetInstance(EntitlementManager::class);

    $manager = app(EntitlementManager::class);

    $manager->grant('LIC-123', 'course-a', 'manual');

    expect($manager->allows('LIC-123', 'course-a'))->toBeTrue()
        ->and($manager->subjectLabel(new SubjectReference('licence', 'LIC-123')))->toBe('Licence LIC-123');
});

it('rejects a grant with no product slug or no source', function () {
    expect(fn () => Entitlements::grant($this->subject, '  ', 'manual'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Entitlements::grant($this->subject, 'course-a', ' '))->toThrow(InvalidArgumentException::class);
});
