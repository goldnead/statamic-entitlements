<?php

namespace Goldnead\Entitlements\Fieldtypes;

use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;
use Statamic\Fields\Fieldtype;
use Throwable;

/**
 * "Zugänge": the grants of the person whose user page this is.
 *
 * Shipped here, generic, so any site gets it by adding one field to its user
 * blueprint:
 *
 *     -
 *       handle: zugaenge
 *       field:
 *         type: user_entitlements
 *         display: Zugänge
 *
 * It lists her grants (name, state, source, from and until, a link to the
 * grant), grants a new one and revokes with a mandatory reason. It writes
 * nothing of its own: granting posts to the manual grant and revoking to the
 * revocation, so the user page and Users > Entitlements cannot disagree about
 * what a manual grant is. The Gate decides on the server; the flags below only
 * spare a button that would answer 403.
 *
 * It stores nothing on the user either. `process()` returns null, which both
 * user repositories drop rather than write.
 */
class UserEntitlements extends Fieldtype
{
    protected static $handle = 'user_entitlements';

    protected $categories = ['special'];

    protected $icon = 'key';

    protected $localizable = false;

    protected $validatable = false;

    protected $defaultable = false;

    public static function title()
    {
        return __('entitlements::cp.user_section_title');
    }

    public function preProcess($data)
    {
        return null;
    }

    public function process($data)
    {
        return null;
    }

    public function augment($value)
    {
        return null;
    }

    public function preload(): array
    {
        // `getAuthIdentifier()` is on the contract; `id()` is only on the two
        // concrete user classes.
        $id = $this->user()?->getAuthIdentifier();
        $id = $id === null ? null : (string) $id;
        $canView = Gate::allows('view entitlements');

        return [
            'userId' => $id,
            'canView' => $canView,
            'rowsUrl' => $id !== null && $canView ? cp_route('entitlements.user', ['userId' => $id]) : null,
            'storeUrl' => cp_route('entitlements.store'),
        ];
    }

    /**
     * The person being edited.
     *
     * The field's parent when the caller set one. Core's user edit screen does
     * not (`ExtractsFromUserFields` builds the fields without a parent), so the
     * `users.edit` route parameter is the fallback. On the create screen there
     * is neither, and the section says to save first.
     */
    private function user(): ?UserContract
    {
        $parent = $this->field?->parent();

        if ($parent instanceof UserContract) {
            return $parent;
        }

        try {
            $id = request()->route('user');

            return is_string($id) || is_int($id) ? User::find((string) $id) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
