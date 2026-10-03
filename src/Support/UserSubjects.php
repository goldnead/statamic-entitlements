<?php

namespace Goldnead\Entitlements\Support;

use Illuminate\Database\Eloquent\Model;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;
use Statamic\Query\Builder;
use Statamic\Query\EloquentQueryBuilder;
use Throwable;

/**
 * Grants whose subject is a Statamic user, read as people.
 *
 * The listing, the grant and the revocation used to name a person by her key,
 * `user:3f0c…`, and the listing search matched only that key: searching
 * "Clara" found whoever's UUID happened to contain those letters. This class
 * answers two questions for the Control Panel, in batches so a page of fifty
 * rows costs one user query rather than fifty:
 *
 * - who is behind these references (name, email, a link to her user page);
 * - which users match a search term (name or email).
 *
 * A reference is a user when its type is the one {@see MorphSubjectResolver}
 * writes for users on this install: `user` with the file repository, the user
 * model's morph class with the Eloquent one. `user` is always accepted, so a
 * grant written by a sibling (statamic-courses writes `user` + Statamic ID) is
 * named on an Eloquent install too.
 *
 * Everything here is display. A failure (a user repository that refuses a
 * query, a deleted user) costs the name, never the screen: the row falls back
 * to the resolver's label.
 */
final class UserSubjects
{
    /** Cap on the users a search may expand into, so a one-letter term stays one query. */
    private const SEARCH_LIMIT = 200;

    /** @return list<string> */
    public function types(): array
    {
        $types = ['user'];

        try {
            if (config('statamic.users.repository') === 'eloquent') {
                $class = config('auth.providers.users.model');

                if (is_string($class) && is_subclass_of($class, Model::class)) {
                    $types[] = (new $class)->getMorphClass();
                }
            }
        } catch (Throwable) {
            // Display only; `user` alone is still right for file users.
        }

        return array_values(array_unique($types));
    }

    public function isUser(SubjectReference $reference): bool
    {
        return in_array($reference->type, $this->types(), true);
    }

    /**
     * @param  iterable<SubjectReference>  $references
     * @return array<string, array{name: string, email: string|null, url: string}> keyed by reference key
     */
    public function describe(iterable $references): array
    {
        $types = $this->types();
        $wanted = [];

        foreach ($references as $reference) {
            if (in_array($reference->type, $types, true)) {
                $wanted[$reference->id][] = $reference->key();
            }
        }

        if ($wanted === []) {
            return [];
        }

        $described = [];

        foreach ($this->find(array_keys($wanted)) as $user) {
            $id = (string) $user->getAuthIdentifier();
            $email = $user->email();
            $name = method_exists($user, 'name') ? $user->name() : null;

            foreach ($wanted[$id] ?? [] as $key) {
                $described[$key] = [
                    'name' => is_string($name) && trim($name) !== '' ? $name : (string) $email,
                    'email' => is_string($email) ? $email : null,
                    'url' => cp_route('users.edit', $id),
                ];
            }
        }

        return $described;
    }

    public function describeOne(SubjectReference $reference): ?array
    {
        return $this->describe([$reference])[$reference->key()] ?? null;
    }

    /**
     * IDs of users whose name or email contains the term.
     *
     * @return list<string>
     */
    public function idsMatching(string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $ids = fn (iterable $users) => collect($users)
            ->map(fn (UserContract $user) => (string) $user->getAuthIdentifier())
            ->values()
            ->all();

        try {
            $query = $this->query();

            return $query === null ? [] : $ids($query
                ->where(fn ($query) => $query->where('email', 'like', $like)->orWhere('name', 'like', $like))
                ->limit(self::SEARCH_LIMIT)
                ->get());
        } catch (Throwable) {
            // An Eloquent user table without a `name` column: email only.
            try {
                $query = $this->query();

                return $query === null ? [] : $ids($query->where('email', 'like', $like)->limit(self::SEARCH_LIMIT)->get());
            } catch (Throwable) {
                return [];
            }
        }
    }

    /**
     * @param  list<string>  $ids
     * @return iterable<UserContract>
     */
    private function find(array $ids): iterable
    {
        try {
            return $this->query()?->whereIn('id', $ids)->get() ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The user query, narrowed to the two builders core ships (file and
     * Eloquent). The contract it is typed as declares no `where()`; a third,
     * custom repository is not searched rather than guessed at.
     */
    private function query(): Builder|EloquentQueryBuilder|null
    {
        $query = User::query();

        return $query instanceof Builder || $query instanceof EloquentQueryBuilder
            ? $query
            : null;
    }
}
