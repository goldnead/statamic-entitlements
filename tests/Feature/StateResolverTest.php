<?php

use Carbon\CarbonImmutable;
use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\StateResolver;
use Goldnead\Entitlements\Support\SubjectReference;

/**
 * The state machine, and the proof that there is only one of it.
 *
 * The last test in this file is the important one: it builds every combination
 * of stored status and the four timestamps, resolves each row in PHP, and then
 * asks the database for the same six sets. If the SQL projection and the PHP
 * definition ever disagree about a single row, it fails and names it. That is
 * what keeps `StateResolver::constrain()` a projection rather than the second,
 * competing implementation this package exists to remove.
 */
beforeEach(function () {
    // A fixed instant, so "in the future" means the same thing in the row, in
    // the PHP resolution and in the compiled SQL. Without it the three read the
    // clock at three slightly different times and a row sitting on a boundary
    // flickers.
    $this->freezeTime();
    $this->now = CarbonImmutable::now('UTC');
});

it('resolves a grant with no window at all as active', function () {
    $grant = Entitlement::factory()->create(['starts_at' => null, 'expires_at' => null]);

    expect($grant->state())->toBe(EntitlementState::Active)
        ->and($grant->grantsAccess())->toBeTrue();
});

/*
 * Defect (a) from the extraction analysis.
 *
 * The system this replaces returned Expired for a grant whose start date had not
 * arrived. The access effect was accidentally right — no access either way — but
 * the label reached the customer's own account screen through buildSummary(), so
 * a pre-sale told its buyer their access had run out.
 *
 * Both halves are asserted here. The label, because that is the defect, and the
 * absence of access, because a fix that renamed the state while opening the door
 * would be very much worse than the bug.
 */
it('resolves a grant that has not started yet as scheduled, not expired, and grants nothing', function () {
    $grant = Entitlement::factory()->create([
        'starts_at' => $this->now->addWeek(),
        'expires_at' => null,
    ]);

    expect($grant->state())->toBe(EntitlementState::Scheduled)
        ->and($grant->state())->not->toBe(EntitlementState::Expired)
        ->and($grant->grantsAccess())->toBeFalse();
});

it('turns a scheduled grant active by itself once the clock reaches it', function () {
    $grant = Entitlement::factory()->create(['starts_at' => $this->now->addHour()]);

    expect($grant->state())->toBe(EntitlementState::Scheduled)
        ->and($grant->state()->isProvisional())->toBeTrue();

    $this->travelTo($this->now->addHours(2));

    expect($grant->fresh()->state())->toBe(EntitlementState::Active);
});

it('never turns a pending grant active by itself, because a confirmation is not a clock', function () {
    $grant = Entitlement::factory()->pending()->create();

    expect($grant->state()->isProvisional())->toBeFalse();

    $this->travelTo($this->now->addYear());

    expect($grant->fresh()->state())->toBe(EntitlementState::Pending);
});

/*
 * Defect (b).
 *
 * `pending` is the state a lead magnet sits in between the form being submitted
 * and the double opt-in coming back. One of the two state implementations in the
 * source system did not know about it, so such a grant fell through to the time
 * checks, found an open window, and returned true — handing out a download to an
 * unconfirmed address.
 *
 * "In every grant path" is the requirement, and in this package there is only
 * one path, which is the actual fix. So the assertion is made through every
 * public entry point that could answer the question.
 */
it('grants no access for a pending grant, through every entry point', function () {
    $grant = Entitlement::factory()->pending()->create([
        'starts_at' => null,
        'expires_at' => null,
    ]);

    $subject = new SubjectReference($grant->subject_type, $grant->subject_id);

    expect($grant->state())->toBe(EntitlementState::Pending)
        ->and($grant->state()->grantsAccess())->toBeFalse()
        ->and($grant->grantsAccess())->toBeFalse()
        ->and(Entitlements::allows($subject, $grant->product_slug))->toBeFalse()
        ->and(Entitlements::activeProductSlugsFor($subject))->toBe([])
        ->and(StateResolver::constrainToAccess(Entitlement::query())->count())->toBe(0);
});

/*
 * Defect (c).
 *
 * `revoked_at` existed, was displayed and entered no decision — and no write
 * path ever set it, so the two signals could not disagree because one was never
 * used. Here both are real, which makes a row that disagrees possible for the
 * first time. It must fail closed in both directions.
 */
it('refuses access for a revoked grant even when status and revoked_at contradict each other', function () {
    // Only the timestamp says revoked.
    $timestampOnly = Entitlement::factory()->create([
        'status' => EntitlementState::Active->value,
        'revoked_at' => $this->now->subDay(),
        'expires_at' => null,
    ]);

    // Only the status says revoked.
    $statusOnly = Entitlement::factory()->create([
        'status' => EntitlementState::Revoked->value,
        'revoked_at' => null,
        'expires_at' => null,
    ]);

    expect($timestampOnly->state())->toBe(EntitlementState::Revoked)
        ->and($timestampOnly->grantsAccess())->toBeFalse()
        ->and($statusOnly->state())->toBe(EntitlementState::Revoked)
        ->and($statusOnly->grantsAccess())->toBeFalse()
        ->and(StateResolver::constrainToAccess(Entitlement::query())->count())->toBe(0);
});

it('keeps a grace period granting access past its expiry, and stops when the grace runs out', function () {
    $grant = Entitlement::factory()->inGracePeriod()->create([
        'expires_at' => $this->now->subDay(),
        'grace_until' => $this->now->addDay(),
    ]);

    expect($grant->state())->toBe(EntitlementState::GracePeriod)
        ->and($grant->grantsAccess())->toBeTrue();

    $this->travelTo($this->now->addDays(2));

    expect($grant->fresh()->state())->toBe(EntitlementState::Expired)
        ->and($grant->fresh()->grantsAccess())->toBeFalse();
});

it('treats a grace period with no grace_until as over', function () {
    $grant = Entitlement::factory()->create([
        'status' => EntitlementState::GracePeriod->value,
        'grace_until' => null,
    ]);

    expect($grant->state())->toBe(EntitlementState::Expired);
});

it('expires at the instant of expiry, not after it', function () {
    $grant = Entitlement::factory()->create(['expires_at' => $this->now]);

    expect($grant->state())->toBe(EntitlementState::Expired);
});

it('leaves an unknown stored status to the time checks, exactly as the source system did', function () {
    // Production data in the source system contains a row with source `admin`
    // and a status nothing writes any more. Special-casing unknown statuses to
    // "no access" would look tidy and would revoke access from real customers
    // during an upgrade.
    $grant = Entitlement::factory()->create(['status' => 'admin', 'expires_at' => null]);

    expect($grant->state())->toBe(EntitlementState::Active);
});

/*
 * The pin. Everything above describes single branches; this asserts that the two
 * expressions of the whole machine agree on every row that can exist.
 */
it('resolves identically in PHP and in SQL, across every combination of status and dates', function () {
    $past = $this->now->subWeek();
    $future = $this->now->addWeek();

    $statuses = [...EntitlementState::storable(), 'admin'];
    $dates = [null, $past, $future];

    $expected = [];
    $index = 0;

    foreach ($statuses as $status) {
        foreach ($dates as $startsAt) {
            foreach ($dates as $expiresAt) {
                foreach ($dates as $graceUntil) {
                    foreach ([null, $past] as $revokedAt) {
                        $grant = Entitlement::factory()->create([
                            'product_slug' => 'matrix-'.$index++,
                            'status' => $status,
                            'starts_at' => $startsAt,
                            'expires_at' => $expiresAt,
                            'grace_until' => $graceUntil,
                            'revoked_at' => $revokedAt,
                        ]);

                        $expected[$grant->getKey()] = StateResolver::resolve($grant, $this->now);
                    }
                }
            }
        }
    }

    // 5 statuses x 3 x 3 x 3 dates x 2 revocation states.
    expect($expected)->toHaveCount(270);

    foreach (EntitlementState::cases() as $state) {
        $fromSql = StateResolver::constrain(Entitlement::query(), $state, $this->now)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $fromPhp = collect($expected)
            ->filter(fn (EntitlementState $resolved): bool => $resolved === $state)
            ->keys()
            ->sort()
            ->values()
            ->all();

        expect($fromSql)->toBe(
            $fromPhp,
            "The SQL projection and the PHP resolution disagree about which grants are {$state->value}. ".
            'They are two expressions of one rule and must not drift; see StateResolver.'
        );
    }

    // And every state is actually represented, so a matrix that accidentally
    // stopped covering one cannot pass by comparing two empty sets.
    expect(array_unique(array_map(fn ($s) => $s->value, $expected)))
        ->toHaveCount(count(EntitlementState::cases()));
});

it('agrees with the access query about which grants let somebody in', function () {
    $past = $this->now->subWeek();
    $future = $this->now->addWeek();

    $index = 0;
    $expected = [];

    foreach ([...EntitlementState::storable(), 'admin'] as $status) {
        foreach ([null, $past, $future] as $startsAt) {
            foreach ([null, $past, $future] as $expiresAt) {
                foreach ([null, $future] as $graceUntil) {
                    $grant = Entitlement::factory()->create([
                        'product_slug' => 'access-'.$index++,
                        'status' => $status,
                        'starts_at' => $startsAt,
                        'expires_at' => $expiresAt,
                        'grace_until' => $graceUntil,
                    ]);

                    if (StateResolver::resolve($grant, $this->now)->grantsAccess()) {
                        $expected[] = $grant->getKey();
                    }
                }
            }
        }
    }

    sort($expected);

    $fromSql = StateResolver::constrainToAccess(Entitlement::query(), $this->now)
        ->pluck('id')->sort()->values()->all();

    expect($fromSql)->toBe($expected)->not->toBeEmpty();
});
