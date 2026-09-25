<?php

namespace Goldnead\Entitlements\Support;

use Goldnead\Entitlements\Contracts\SubjectResolver;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Statamic\Contracts\Auth\User as StatamicUser;

/**
 * The default subject resolver: Eloquent models through the morph map, plus
 * references that are already pairs.
 *
 * `label()` returns null on purpose. Producing a name would mean loading the
 * subject, and the listing calls this once per row — a helpful label here is a
 * hundred queries on a hundred-row page. A consumer that wants names binds its
 * own resolver and can batch the lookup however it likes.
 */
class MorphSubjectResolver implements SubjectResolver
{
    public function reference(mixed $subject): SubjectReference
    {
        if ($subject instanceof SubjectReference) {
            return $subject;
        }

        if ($subject instanceof Model) {
            return SubjectReference::for($subject);
        }

        // A Statamic user. With the Eloquent repository it wraps the model, and
        // the model's morph type is what every other path writes, so the same
        // person is one subject whichever of the two a caller holds. With the
        // file repository there is no model: the type is `user` and the id is
        // Statamic's, as statamic-courses writes it.
        if ($subject instanceof StatamicUser) {
            if (method_exists($subject, 'model') && ($model = $subject->model()) instanceof Model) {
                return SubjectReference::for($model);
            }

            $id = method_exists($subject, 'id') ? (string) $subject->id() : '';

            if ($id !== '') {
                return new SubjectReference('user', $id);
            }
        }

        throw new InvalidArgumentException(
            'Cannot use '.get_debug_type($subject).' as an entitlement subject. '
            .'Pass an Eloquent model or a SubjectReference, or bind your own '
            .SubjectResolver::class.' implementation.'
        );
    }

    public function label(SubjectReference $reference): ?string
    {
        return null;
    }
}
