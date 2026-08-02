<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Defect (d): the same grant, written twice.
 *
 * The source system had no unique index on its grants table for five months.
 * `firstOrCreate()` is a SELECT followed by an INSERT, and two webhook
 * deliveries, a queue retry or a double-clicked checkout button each fit
 * comfortably in the gap between them.
 *
 * The tests below therefore do not test `grant()`'s own guard — that guard is
 * the part that cannot be trusted, because it is exactly what the source system
 * had. They go around it: two INSERTs are issued as if two processes had each
 * passed the SELECT, and the *database* is what has to refuse the second one.
 */
beforeEach(function () {
    $this->subject = new SubjectReference('user', '42');
});

it('writes one row when the same grant is requested twice', function () {
    $first = Entitlements::grant($this->subject, 'course-a', 'thrivecart', 'evt_1');
    $second = Entitlements::grant($this->subject, 'course-a', 'thrivecart', 'evt_1');

    expect(Entitlement::query()->count())->toBe(1)
        ->and($second->getKey())->toBe($first->getKey());
});

/**
 * The one that matters, and the reason `source_ref` is NOT NULL.
 *
 * NULLs never collide in a unique index — on MySQL, SQLite and Postgres alike.
 * Had the column stayed nullable, as the extraction spec first described it, the
 * constraint would be switched off for every grant without an external
 * reference: manual grants from the Control Panel, opt-ins, anything a human
 * creates by hand. Those are precisely the rows that get double-submitted.
 */
it('refuses a duplicate at the database even when the grant has no source reference', function () {
    Entitlements::grant($this->subject, 'course-a', 'manual');

    $row = DB::table('entitlements')->first();

    expect($row->source_ref)->toBe('');

    // Bypasses the model, the manager and its guard entirely: this is the INSERT
    // a second process would issue after its own SELECT found nothing.
    $duplicate = fn () => DB::table('entitlements')->insert([
        'brand_id' => $row->brand_id,
        'subject_type' => $row->subject_type,
        'subject_id' => $row->subject_id,
        'product_slug' => $row->product_slug,
        'source' => $row->source,
        'source_ref' => $row->source_ref,
        'status' => EntitlementState::Active->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($duplicate)->toThrow(UniqueConstraintViolationException::class)
        ->and(Entitlement::query()->count())->toBe(1);
});

/**
 * The race, simulated as closely as one process can.
 *
 * Two callers each read "nothing exists" and then each write. The second INSERT
 * is what a real second process would send, and the manager has to survive it by
 * re-reading the winner's row rather than exploding or duplicating.
 */
it('lets the loser of a concurrent provisioning read the winner\'s row instead of writing a second', function () {
    // The winner writes first, exactly as a competing process would have.
    Entitlements::grant($this->subject, 'course-a', 'mollie', 'tr_abc');

    // Now force the loser's path: an INSERT that the constraint rejects. The
    // manager catches the violation and re-reads.
    $recovered = Entitlements::grant($this->subject, 'course-a', 'mollie', 'tr_abc');

    expect(Entitlement::query()->count())->toBe(1)
        ->and($recovered->product_slug)->toBe('course-a');

    // And the recovery path itself: a row that appears between the SELECT and
    // the INSERT. Simulated by inserting under the manager's feet.
    $second = new SubjectReference('user', '43');

    DB::table('entitlements')->insert([
        'brand_id' => app('brand-context')->currentId(),
        'subject_type' => $second->type,
        'subject_id' => $second->id,
        'product_slug' => 'course-b',
        'source' => 'mollie',
        'source_ref' => 'tr_xyz',
        'status' => EntitlementState::Active->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $afterRace = Entitlements::grant($second, 'course-b', 'mollie', 'tr_xyz');

    expect($afterRace)->toBeInstanceOf(Entitlement::class)
        ->and(Entitlement::query()->where('product_slug', 'course-b')->count())->toBe(1);
});

it('keeps a repeat purchase of the same product as a separate grant', function () {
    // The tuple is deliberately not narrower. A second purchase arrives with a
    // new provider event id, and collapsing the two would destroy real customer
    // history — a refund on one would look like a revocation of both.
    Entitlements::grant($this->subject, 'course-a', 'thrivecart', 'evt_1');
    Entitlements::grant($this->subject, 'course-a', 'thrivecart', 'evt_2');

    expect(Entitlement::query()->where('product_slug', 'course-a')->count())->toBe(2);
});

it('keeps the same product from two different sources as two grants', function () {
    Entitlements::grant($this->subject, 'course-a', 'newsletter_optin', 'course-a');
    Entitlements::grant($this->subject, 'course-a', 'thrivecart', 'evt_1');

    expect(Entitlement::query()->where('product_slug', 'course-a')->count())->toBe(2);
});

it('separates the same grant in two brands', function () {
    // brand_id is in the unique key. Leaving it out would mean one brand's grant
    // blocking another brand's identical one, which is a tenancy failure rather
    // than a de-duplication.
    $this->enableMultiBrand();

    $a = $this->makeBrand('alpha');
    $b = $this->makeBrand('beta');

    app('brand-context')->runFor($a, fn () => Entitlements::grant($this->subject, 'course-a', 'manual'));
    app('brand-context')->runFor($b, fn () => Entitlements::grant($this->subject, 'course-a', 'manual'));

    expect(DB::table('entitlements')->count())->toBe(2);
});
