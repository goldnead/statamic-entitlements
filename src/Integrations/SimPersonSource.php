<?php

namespace Goldnead\Entitlements\Integrations;

use Goldnead\BrandContext\Contracts\ProvidesSimPersons;
use Goldnead\Entitlements\EntitlementManager;
use Goldnead\Entitlements\Support\SubjectReference;
use Statamic\Facades\User;

/**
 * The access a real person holds, for the dry run's "as it is for person X" (see ProvidesSimPersons).
 *
 * Read only, and it finds nobody: the CRM finds the person and gives this source her identity, email
 * and id. Only grants that give access right now count (active or in their grace period). Scheduled,
 * pending, expired and revoked grants are left out, and so is any grant of somebody else.
 *
 * Nothing is written here and no line of the log names the person.
 */
class SimPersonSource implements ProvidesSimPersons
{
    /** Most slugs one person hands over, so a copy stays small whatever the data holds. */
    private const LIMIT = 50;

    /**
     * Not injected: the source is built at boot to be registered, and the manager is a singleton that keeps
     * the event dispatcher it was built with. Built that early it would hold the real one, not a test's fake.
     */
    public function personSourceId(): string
    {
        return 'entitlements';
    }

    /** The same right that opens the access listing in the Control Panel. */
    public function personsAuthorized($user): bool
    {
        return $user !== null && $user->can('view entitlements');
    }

    /** This source finds no one; the CRM does. */
    public function searchPersons(string $query, int $limit): array
    {
        return [];
    }

    /** Nothing to identify here: keys come from the CRM. */
    public function identifyPerson(string $key): ?array
    {
        return null;
    }

    /**
     * The slugs of the access she holds now: grants on her email, and on the Statamic user with that email if there is one.
     *
     * @param  array{email?: string, id?: string|int}  $identity
     * @return array{access?: list<string>}
     */
    public function personFacet(array $identity): array
    {
        $email = is_string($identity['email'] ?? null) ? trim($identity['email']) : '';

        if ($email === '') {
            return [];
        }

        $entitlements = app(EntitlementManager::class);

        $subjects = [new SubjectReference('email', $email)];

        if ($user = User::findByEmail($email)) {
            $subjects[] = $entitlements->reference($user);
        }

        $slugs = $entitlements->forSubjects($subjects)
            ->orderBy('id')
            ->get()
            ->filter(fn ($grant) => $grant->state()->grantsAccess())
            ->pluck('product_slug')
            ->filter(fn ($slug) => is_string($slug) && $slug !== '')
            ->unique()
            ->values()
            ->take(self::LIMIT)
            ->all();

        return ['access' => $slugs];
    }
}
