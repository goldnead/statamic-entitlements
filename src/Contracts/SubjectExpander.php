<?php

namespace Goldnead\Entitlements\Contracts;

use Goldnead\Entitlements\Support\SubjectReference;

/**
 * Extension point: the further subjects a subject acts for.
 *
 * A person is a member of a team, and the team's grant should open the door
 * for the person too. This package does not know what a team is; whoever does
 * (statamic-teams, or the host) answers here.
 *
 * Register an implementation with `Entitlements::extendSubjects($expander)` or
 * tag it in the container as `entitlements.subject-expanders`. Several are
 * allowed. An object that has this method without declaring the interface is
 * accepted as well, so a sibling can offer it without requiring this package.
 *
 * Applies to the read side only: `decide()`, `allows()`,
 * `activeProductSlugsFor()` and every limit. **Never** to `forSubject()`,
 * `renew()` or anything that writes: a refund against a person must not
 * revoke the team's grant.
 *
 * Asked about the subject that was passed in, once, never recursively.
 * Implementations must be cheap — this is on the access path.
 */
interface SubjectExpander
{
    /**
     * @return list<SubjectReference|mixed> anything the SubjectResolver accepts, the subject itself excluded
     */
    public function relatedSubjects(SubjectReference $subject): array;
}
