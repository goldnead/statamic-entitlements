<?php

namespace Goldnead\Entitlements\Http\Controllers\Cp;

use Goldnead\Entitlements\EntitlementManager;
use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\ProductCatalog;
use Goldnead\Entitlements\Support\SourceRegistry;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\User as StatamicUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The grants of one person, for the "Zugänge" section on her user page.
 *
 * Read only. Granting from the user page posts to the manual grant
 * (`entitlements.store`) and revoking to the existing revocation, so both are
 * the same write, the same validation and the same Gate as under
 * Users > Entitlements. A second write path here would be a second place for
 * the rules to drift apart.
 *
 * Only this person's own grants: `forSubject()`, not the grants of the teams
 * she belongs to. The section answers "what was granted to her", and a team's
 * grant is revoked on the team, not here.
 */
class UserEntitlementController extends Controller
{
    public function __construct(private readonly EntitlementManager $entitlements) {}

    public function show(string $userId)
    {
        Gate::authorize('view entitlements');

        $user = StatamicUser::find($userId) ?? throw new NotFoundHttpException;

        $reference = $this->entitlements->reference($user);
        $catalog = app(ProductCatalog::class);
        $products = $catalog->all();
        $sources = app(SourceRegistry::class);
        $canRevoke = Gate::allows('revoke entitlements');

        $rows = $this->entitlements->forSubject($reference)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (Entitlement $grant) use ($catalog, $products, $sources, $canRevoke) {
                $state = $grant->state();

                return [
                    'id' => $grant->getKey(),
                    'product_slug' => $grant->product_slug,
                    'product_label' => $catalog->label($grant->product_slug, $products),
                    'product_unknown' => $catalog->isUnknown($grant->product_slug, $products),
                    'state' => $state->value,
                    'state_label' => $state->label(),
                    'grants_access' => $state->grantsAccess(),
                    'source' => $sources->label($grant->source),
                    'starts_at' => $this->iso($grant->starts_at),
                    'expires_at' => $this->iso($grant->expires_at),
                    'show_url' => cp_route('entitlements.show', ['entitlement' => $grant->getKey()]),
                    'revoke_url' => cp_route('entitlements.revoke', ['entitlement' => $grant->getKey()]),
                    'can_revoke' => $canRevoke && $state !== EntitlementState::Revoked,
                ];
            })
            ->values()
            ->all();

        $canGrant = Gate::allows('grant entitlements');

        return [
            'subject' => $reference->key(),
            'rows' => $rows,
            'canGrant' => $canGrant,
            'canRevoke' => $canRevoke,
            // The same options the grant form offers. Null without a catalogue:
            // the section then asks for the slug as text, as the form does.
            // A list, not a map: a JSON object with numeric-looking slugs would
            // come back reordered.
            // Only catalogue entries, as in the grant form.
            'products' => $canGrant && $products !== []
                ? collect($catalog->options($products))
                    ->map(fn (string $label, string|int $slug) => ['value' => (string) $slug, 'label' => $label])
                    ->values()
                    ->all()
                : null,
            // "All grants" opens the listing filtered to her.
            'indexUrl' => cp_route('entitlements.index', ['subject' => $reference->key()]),
        ];
    }

    /** An ISO instant; the section formats it in the viewer's locale, as core does. */
    private function iso(mixed $date): ?string
    {
        return $date ? $date->toIso8601ZuluString() : null;
    }
}
