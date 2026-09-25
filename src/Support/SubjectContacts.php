<?php

namespace Goldnead\Entitlements\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Statamic\Facades\User;
use Throwable;

/**
 * An address and a name for a subject, for the few places that write to one.
 *
 * Only ever called for a single subject at the moment a mail goes out, never
 * per row of a listing. Answers null rather than guessing: a mail without a
 * recipient is not sent, and the caller logs why.
 */
final class SubjectContacts
{
    /** @return array{email: string, name: string|null}|null */
    public static function for(SubjectReference $reference): ?array
    {
        try {
            if ($reference->type === 'email') {
                return filter_var($reference->id, FILTER_VALIDATE_EMAIL) ? ['email' => $reference->id, 'name' => null] : null;
            }

            $class = Relation::getMorphedModel($reference->type) ?? $reference->type;

            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                $model = $class::query()->find($reference->id);
                $email = $model?->getAttribute('email');

                return is_string($email) && $email !== ''
                    ? ['email' => $email, 'name' => self::string($model->getAttribute('name'))]
                    : null;
            }

            if ($reference->type === 'user') {
                $user = User::find($reference->id);
                $email = $user?->email();

                return is_string($email) && $email !== ''
                    ? ['email' => $email, 'name' => method_exists($user, 'get') ? self::string($user->get('name')) : null]
                    : null;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
