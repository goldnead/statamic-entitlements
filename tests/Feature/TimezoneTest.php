<?php

use Carbon\CarbonImmutable;
use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\StateResolver;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Facades\DB;

/**
 * Every timestamp on a grant decides access, so a timezone slip is not a
 * formatting bug — it is hours of access nobody paid for, or hours of a
 * locked-out customer.
 *
 * The suite runs with `app.timezone = America/Chicago`, which is neither UTC nor
 * any zone used below. That is deliberate: with the application timezone set to
 * UTC, a correct conversion and a missing one produce identical output and this
 * whole file would pass against broken code.
 */
beforeEach(function () {
    $this->subject = new SubjectReference('user', '42');
});

it('stores a zoned datetime as the UTC instant it actually names', function () {
    // 19:00 in Berlin in January is 18:00 UTC. Laravel's own `datetime` cast
    // writes the string "19:00" here and reads it back as 19:00 UTC — an hour
    // out, silently, and only for callers who bothered to state a timezone.
    $berlin = CarbonImmutable::parse('2026-01-15 19:00:00', 'Europe/Berlin');

    $grant = Entitlements::grant($this->subject, 'course-a', 'manual', expiresAt: $berlin);

    expect(DB::table('entitlements')->value('expires_at'))->toStartWith('2026-01-15 18:00:00');

    expect($grant->fresh()->expires_at->format('Y-m-d H:i'))->toBe('2026-01-15 18:00');
});

it('hands back an immutable UTC Carbon, not whatever was assigned', function () {
    // Eloquent's object caching returns the assigned instance for a class cast,
    // mutable Carbon and original timezone included — which is how a mutable
    // Carbon leaks into a CarbonImmutable typehint on an event. The attribute
    // used here asks for no cache, so the accessor runs against the stored
    // string every time and there is one answer.
    $grant = new Entitlement;
    $grant->expires_at = CarbonImmutable::parse('2026-01-15 19:00:00', 'Europe/Berlin')->toMutable();

    expect($grant->expires_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($grant->expires_at->timezoneName)->toBe('UTC')
        ->and($grant->expires_at->format('H:i'))->toBe('18:00');
});

it('reads a bare string from the database as UTC and not as the app timezone', function () {
    // A string is the shape a database row arrives in and the column is
    // documented as UTC. Guessing the application timezone would make the same
    // literal mean different instants on two servers.
    $grant = Entitlement::factory()->create();

    DB::table('entitlements')->where('id', $grant->getKey())->update(['expires_at' => '2026-06-01 12:00:00']);

    expect($grant->fresh()->expires_at->timezoneName)->toBe('UTC')
        ->and($grant->fresh()->expires_at->format('H:i'))->toBe('12:00');
});

it('decides expiry against the real instant, not the app timezone reading of it', function () {
    // 00:30 UTC on the 2nd is still the 1st in Chicago. A comparison done in the
    // application timezone would call this grant live for another six hours.
    $this->travelTo(CarbonImmutable::parse('2026-06-02 00:30:00', 'UTC'));

    $grant = Entitlement::factory()->create([
        'expires_at' => CarbonImmutable::parse('2026-06-02 00:00:00', 'UTC'),
    ]);

    expect($grant->state())->toBe(EntitlementState::Expired)
        ->and(Entitlements::allows(
            new SubjectReference($grant->subject_type, $grant->subject_id),
            $grant->product_slug
        ))->toBeFalse();
});

it('agrees between the PHP resolution and the SQL projection across a zone boundary', function () {
    // The SQL comparison is a string comparison against a formatted instant. If
    // the formatting used the application timezone while the PHP branch used
    // UTC, the two would disagree by exactly the offset — and only for grants
    // sitting inside it.
    $this->travelTo(CarbonImmutable::parse('2026-06-02 00:30:00', 'UTC'));

    Entitlement::factory()->create([
        'product_slug' => 'just-expired',
        'expires_at' => CarbonImmutable::parse('2026-06-02 00:00:00', 'UTC'),
    ]);

    Entitlement::factory()->create([
        'product_slug' => 'still-live',
        'expires_at' => CarbonImmutable::parse('2026-06-02 06:00:00', 'UTC'),
    ]);

    $accessible = StateResolver::constrainToAccess(Entitlement::query())
        ->pluck('product_slug')
        ->all();

    expect($accessible)->toBe(['still-live']);
});
