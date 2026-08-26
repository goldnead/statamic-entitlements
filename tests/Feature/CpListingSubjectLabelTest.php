<?php

use Goldnead\Entitlements\Contracts\SubjectResolver;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\MorphSubjectResolver;
use Goldnead\Entitlements\Support\SubjectReference;

/*
 * The listing has to ask the host's SubjectResolver, not just the detail view.
 *
 * Found by opening the screen. A host had bound a resolver specifically so the
 * Control Panel would read `Maria Schneider` instead of `App\Models\User:4` —
 * and it did, on the detail page nobody visits, while the listing everybody
 * scans still printed the raw key. The resolver worked; nothing asked it.
 *
 * That is the failure mode an addon test cannot see on its own: with the
 * default resolver both paths return the same raw key, so the listing looked
 * correct in every suite that never bound one.
 */

function resolverThatNames(array $names): void
{
    app()->bind(SubjectResolver::class, fn () => new class($names) extends MorphSubjectResolver
    {
        public function __construct(private array $names) {}

        public function label(SubjectReference $reference): ?string
        {
            return $this->names[$reference->key()] ?? null;
        }
    });
}

it('shows the resolved name in the listing', function () {
    $grant = Entitlement::factory()->create([
        'subject_type' => 'user',
        'subject_id' => '4',
    ]);

    resolverThatNames(['user:4' => 'Maria Schneider']);

    $row = $this->actingAs(cpUserWith(['access cp', 'view entitlements']))
        ->getJson(cp_route('entitlements.index'))
        ->assertOk()
        ->json('data.0');

    expect($row['subject'])->toBe('Maria Schneider')
        // The key stays alongside: a name is a display, not an identity, and
        // two members can share one.
        ->and($row['subject_key'])->toBe('user:4')
        ->and($row['id'])->toBe($grant->getKey());
});

it('falls back to the raw key when the host names nobody', function () {
    Entitlement::factory()->create([
        'subject_type' => 'user',
        'subject_id' => '4',
    ]);

    // The shipped default: it returns null on purpose, because the addon cannot
    // know how to load a subject without querying once per row.
    $row = $this->actingAs(cpUserWith(['access cp', 'view entitlements']))
        ->getJson(cp_route('entitlements.index'))
        ->assertOk()
        ->json('data.0');

    // subjectLabel() does the falling back itself, so the two are simply
    // equal — which is exactly the signal the listing uses to decide whether
    // printing both would say anything.
    expect($row['subject'])->toBe('user:4')
        ->and($row['subject_key'])->toBe('user:4');
});
