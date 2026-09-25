<?php

use Goldnead\Entitlements\Contracts\SubjectExpander;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Usage;
use Goldnead\Entitlements\Support\SubjectExtensions;
use Goldnead\Entitlements\Support\SubjectReference;

/**
 * Der Andockpunkt für Teams: der Zugang eines Teams gilt für seine Mitglieder,
 * ohne dass dieses Addon weiss, was ein Team ist. Den Resolver registriert
 * statamic-teams (oder der Host); hier steht er für ein Team mit zwei
 * Mitgliedern.
 */
beforeEach(function () {
    $this->anna = new SubjectReference('user', '1');
    $this->ben = new SubjectReference('user', '2');
    $this->aussen = new SubjectReference('user', '3');
    $this->chor = new SubjectReference('team', '7');

    Entitlements::extendSubjects(function (mixed $subject, SubjectReference $reference): array {
        return in_array($reference->key(), ['user:1', 'user:2'], true) ? [new SubjectReference('team', '7')] : [];
    });

    Entitlements::setLimits('chor', ['analyses' => ['value' => 3, 'period' => 'year']]);
    Entitlements::setLimits('solo', ['analyses' => ['value' => 3, 'period' => 'year']]);
});

afterEach(fn () => app(SubjectExtensions::class)->forget());

it('lets a member use the team\'s grant, and nobody else', function () {
    Entitlements::grant($this->chor, 'chor', 'manual');

    expect(Entitlements::allows($this->anna, 'chor'))->toBeTrue()
        ->and(Entitlements::allows($this->ben, 'chor'))->toBeTrue()
        ->and(Entitlements::allows($this->aussen, 'chor'))->toBeFalse()
        ->and(Entitlements::activeProductSlugsFor($this->anna))->toBe(['chor'])
        ->and(Entitlements::subjectsOf($this->anna))->toEqual([$this->anna, $this->chor]);
});

it('counts a member\'s usage at the team, so the team\'s allowance is shared', function () {
    Entitlements::grant($this->chor, 'chor', 'manual');

    expect(Entitlements::quota($this->anna, 'analyses')->holder->key())->toBe('team:7');

    Entitlements::consume($this->anna, 'analyses', 2);

    expect(Entitlements::remaining($this->ben, 'analyses'))->toBe(1)
        ->and(Entitlements::consume($this->ben, 'analyses', 2))->toBeFalse()
        ->and(Entitlements::consume($this->ben, 'analyses'))->toBeTrue()
        ->and(Usage::query()->pluck('subject_type')->all())->toBe(['team']);
});

it('takes the higher of a person\'s own plan and the team\'s', function () {
    Entitlements::setLimits('chor', ['analyses' => ['value' => 50, 'period' => 'year']]);
    Entitlements::grant($this->chor, 'chor', 'manual');
    Entitlements::grant($this->anna, 'solo', 'manual');

    expect(Entitlements::limit($this->anna, 'analyses'))->toBe(50)
        ->and(Entitlements::quota($this->anna, 'analyses')->holder->key())->toBe('team:7');
});

it('prefers the person\'s own grant at equal height, so their own plan counts for them', function () {
    Entitlements::grant($this->chor, 'chor', 'manual');
    Entitlements::grant($this->anna, 'solo', 'manual');

    expect(Entitlements::quota($this->anna, 'analyses')->holder->key())->toBe('user:1')
        ->and(Entitlements::quota($this->ben, 'analyses')->holder->key())->toBe('team:7');
});

it('drops the team\'s limit with the team\'s grant', function () {
    $grant = Entitlements::grant($this->chor, 'chor', 'manual');

    Entitlements::revoke($grant, 'Abo gekündigt');

    expect(Entitlements::allows($this->anna, 'chor'))->toBeFalse()
        ->and(Entitlements::limit($this->anna, 'analyses'))->toBe(0);
});

it('keeps deciding a person\'s own grant when a resolver throws', function () {
    Entitlements::extendSubjects(fn () => throw new RuntimeException('Mitgliederliste nicht erreichbar'));
    Entitlements::grant($this->anna, 'solo', 'manual');

    expect(Entitlements::allows($this->anna, 'solo'))->toBeTrue();
});

it('takes a SubjectExpander, a duck-typed object like statamic-teams offers, and a tagged class', function () {
    app(SubjectExtensions::class)->forget();

    $grant = fn (string $team) => Entitlements::grant(new SubjectReference('team', $team), 'chor-'.$team, 'manual');
    $grant('10');
    $grant('11');
    $grant('12');

    // The contract.
    Entitlements::extendSubjects(new class implements SubjectExpander
    {
        public function relatedSubjects(SubjectReference $subject): array
        {
            return $subject->key() === 'user:1' ? [new SubjectReference('team', '10')] : [];
        }
    });

    // The shape statamic-teams ships without requiring this package:
    // `relatedSubjects(object $subject, array $userTypes = ['user'])`.
    Entitlements::extendSubjects(new class
    {
        public function relatedSubjects(object $subject, array $userTypes = ['user']): array
        {
            return in_array($subject->type, $userTypes, true) && $subject->id === '1' ? [new SubjectReference('team', '11')] : [];
        }
    });

    // Tagged in the container, found without a call to extendSubjects().
    app()->bind('test.team-12-expander', fn () => new class implements SubjectExpander
    {
        public function relatedSubjects(SubjectReference $subject): array
        {
            return [new SubjectReference('team', '12')];
        }
    });
    app()->tag(['test.team-12-expander'], SubjectExtensions::TAG);

    expect(Entitlements::activeProductSlugsFor($this->anna))->toEqualCanonicalizing(['chor-10', 'chor-11', 'chor-12']);
});

it('leaves forSubject() and renew() on the subject alone, so a refund to a person never touches the team', function () {
    Entitlements::grant($this->chor, 'chor', 'manual', expiresAt: now()->addMonth());

    expect(Entitlements::forSubject($this->anna)->count())->toBe(0)
        ->and(Entitlements::renew($this->anna, 'chor', now()->addYear()))->toBeNull();
});

it('asks resolvers about the subject passed in only, never recursively', function () {
    $asked = [];
    Entitlements::extendSubjects(function (mixed $subject, SubjectReference $reference) use (&$asked) {
        $asked[] = $reference->key();

        return [];
    });

    Entitlements::allows($this->anna, 'chor');

    expect($asked)->toBe(['user:1']);
});
