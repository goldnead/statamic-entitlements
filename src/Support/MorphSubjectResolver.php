<?php

namespace Goldnead\Entitlements\Support;

use Goldnead\Entitlements\Contracts\SubjectResolver;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

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
