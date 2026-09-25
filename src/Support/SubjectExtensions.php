<?php

namespace Goldnead\Entitlements\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Further subjects a subject acts for: the teams of a person, the organisation
 * of a team member.
 *
 * This package does not know what a team is, and it must not. A grant belongs
 * to whoever it was written for; whether a person may use the grant of a group
 * they belong to is a fact about the host's membership model. So the host (or a
 * sibling such as statamic-teams) registers a resolver:
 *
 *     Entitlements::extendSubjects(fn (mixed $subject, SubjectReference $ref) => $teamsOf($ref));
 *
 * and `allows()`, `activeProductSlugsFor()` and every limit read then consider
 * the grants of those subjects as well.
 *
 * ## One level, never recursive
 *
 * Resolvers are asked about the subject that was passed in, and only about it.
 * The subjects they return are not fed back in. A team inside a team is a
 * modelling decision for the resolver to make explicitly, not something that
 * happens because two resolvers happen to chain.
 *
 * ## A failing resolver costs its extension, never the answer
 *
 * A resolver that throws is logged and skipped. The subject's own grants are
 * still decided, so a broken membership lookup can at worst hide a team's
 * access, never a person's own purchase.
 */
final class SubjectExtensions
{
    /** @var list<callable(mixed, SubjectReference): iterable<mixed>> */
    private array $resolvers = [];

    /** @param  callable(mixed, SubjectReference): iterable<mixed>  $resolver */
    public function register(callable $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    public function isEmpty(): bool
    {
        return $this->resolvers === [];
    }

    public function forget(): void
    {
        $this->resolvers = [];
    }

    /**
     * The extra subjects, as raw values the SubjectResolver still has to turn
     * into references. Never contains the subject itself.
     *
     * @return list<mixed>
     */
    public function extraFor(mixed $subject, SubjectReference $reference): array
    {
        $extra = [];

        foreach ($this->resolvers as $resolver) {
            try {
                foreach ($resolver($subject, $reference) ?? [] as $value) {
                    if ($value !== null) {
                        $extra[] = $value;
                    }
                }
            } catch (Throwable $e) {
                Log::warning('statamic-entitlements: a subject extension failed and was skipped.', [
                    'subject' => $reference->key(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $extra;
    }
}
