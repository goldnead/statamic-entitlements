<?php

namespace Goldnead\Entitlements\Http\Controllers\Cp;

use Goldnead\Entitlements\EntitlementManager;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\IdentityContracts\Identity;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\User as StatamicUser;

/**
 * Setting a usage counter back to zero, by hand.
 *
 * Gated like changing a limit: giving somebody their year's analyses again is
 * the same commercial decision as raising the number.
 */
class UsageController extends Controller
{
    public function __construct(private readonly EntitlementManager $entitlements) {}

    public function reset(Request $request)
    {
        Gate::authorize('manage entitlements limits');

        $data = $request->validate([
            'subject_type' => ['required', 'string', 'max:160'],
            'subject_id' => ['required', 'string', 'max:64'],
            'key' => ['required', 'string', 'max:64'],
            'redirect' => ['nullable', 'string'],
        ]);

        $done = $this->entitlements->resetUsage(
            new SubjectReference($data['subject_type'], $data['subject_id']),
            $data['key'],
            $this->actor(),
        );

        session()->flash($done ? 'success' : 'error', $done
            ? __('entitlements::cp.usage_reset_done')
            : __('entitlements::cp.usage_reset_nothing'));

        // Only back into this addon's own pages, never to an address a
        // request brought along.
        $back = $data['redirect'] ?? '';

        return redirect()->to(str_starts_with($back, cp_route('entitlements.index')) ? $back : cp_route('entitlements.index'));
    }

    private function actor(): ?Identity
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        $statamic = StatamicUser::fromUser($user);

        return Identity::user(id: (string) $user->getAuthIdentifier(), email: $statamic?->email());
    }
}
