<?php

namespace Goldnead\Entitlements\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Who a grant belongs to, reduced to two strings.
 *
 * The system this was extracted from had `foreignId('user_id')` on the grants
 * table. That is two problems at once: it binds the package to the host
 * application's `users` table — the exact `App\...` dependency an extracted
 * addon may not carry — and it makes a grant to somebody who has no user record
 * impossible. A lead magnet is claimed by a CRM contact who has not created an
 * account and may never create one.
 *
 * So the subject is a `(type, id)` pair. The pair is stored, never a foreign
 * key, because a grant is a fact about the past and must survive the record it
 * points at being renamed, merged or deleted.
 *
 * `type` is a morph alias, not necessarily a class name. Registering an alias in
 * the host's morph map — `Relation::enforceMorphMap(['user' => User::class])` —
 * means the stored value stays `user` when the class moves namespace, which is
 * the whole reason morph maps exist.
 */
final readonly class SubjectReference
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if ($type === '' || $id === '') {
            throw new InvalidArgumentException('A subject reference needs both a type and an id.');
        }
    }

    /**
     * The pair for an Eloquent model.
     *
     * `getMorphClass()` rather than `::class`, so a registered morph alias wins.
     */
    public static function for(Model $model): self
    {
        $key = $model->getKey();

        if ($key === null || $key === '') {
            throw new InvalidArgumentException(
                'Cannot grant to an unsaved '.$model::class.': it has no key yet.'
            );
        }

        return new self($model->getMorphClass(), (string) $key);
    }

    public function key(): string
    {
        return $this->type.':'.$this->id;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
