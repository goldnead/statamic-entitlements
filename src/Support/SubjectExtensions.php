<?php

namespace Goldnead\Entitlements\Support;

use Goldnead\Entitlements\Contracts\SubjectExpander;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Further subjects a subject acts for: the teams of a person, the organisation
 * of a team member.
 *
 * This package does not know what a team is, and it must not. A grant belongs
 * to whoever it was written for; whether a person may use the grant of a group
 * they belong to is a fact about the host's membership model. So the host (or a
 * sibling such as statamic-teams) registers an expander, in any of three shapes:
 *
 *     Entitlements::extendSubjects(new TeamSubjects);                 // a SubjectExpander
 *     Entitlements::extendSubjects($teams);                           // any object with relatedSubjects()
 *     Entitlements::extendSubjects(fn (mixed $subject, SubjectReference $ref) => [...]);
 *
 * or tags a class in the container as `entitlements.subject-expanders`. Then
 * `allows()`, `activeProductSlugsFor()` and every limit read consider the
 * grants of those subjects as well. `forSubject()` and every write do not.
 *
 * ## One level, never recursive
 *
 * Expanders are asked about the subject that was passed in, and only about it.
 * The subjects they return are not fed back in.
 *
 * ## A failing expander costs its extension, never the answer
 *
 * One that throws is logged and skipped. The subject's own grants are still
 * decided, so a broken membership lookup can at worst hide a team's access,
 * never a person's own purchase.
 */
final class SubjectExtensions
{
    public const TAG = 'entitlements.subject-expanders';

    /** @var list<callable|object> */
    private array $expanders = [];

    /**
     * @param  callable(mixed, SubjectReference): iterable<mixed>|SubjectExpander|object|class-string  $expander
     */
    public function register(mixed $expander): void
    {
        if (is_string($expander) && class_exists($expander)) {
            $expander = app($expander);
        }

        if (! (is_object($expander) && method_exists($expander, 'relatedSubjects')) && ! is_callable($expander)) {
            throw new InvalidArgumentException(
                'A subject expander is a callable, a '.SubjectExpander::class.' or an object with relatedSubjects().'
            );
        }

        $this->expanders[] = $expander;
    }

    public function isEmpty(): bool
    {
        return $this->expanders === [] && $this->tagged() === [];
    }

    public function forget(): void
    {
        $this->expanders = [];
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

        foreach ([...$this->expanders, ...$this->tagged()] as $expander) {
            try {
                $found = is_object($expander) && method_exists($expander, 'relatedSubjects')
                    ? $expander->relatedSubjects($reference)
                    : $expander($subject, $reference);

                foreach ($found ?? [] as $value) {
                    if ($value !== null) {
                        $extra[] = $value;
                    }
                }
            } catch (Throwable $e) {
                Log::warning('statamic-entitlements: a subject expander failed and was skipped.', [
                    'subject' => $reference->key(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $extra;
    }

    /** @return list<object> */
    private function tagged(): array
    {
        try {
            return array_values(array_filter(
                iterator_to_array(app()->tagged(self::TAG), false),
                fn ($expander) => is_object($expander),
            ));
        } catch (Throwable) {
            return [];
        }
    }
}
