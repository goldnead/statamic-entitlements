<?php

namespace Goldnead\Entitlements\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * A timestamp column that is UTC on disk and an immutable UTC Carbon in memory,
 * always.
 *
 * Every date on an entitlement decides access. `starts_at`, `expires_at`,
 * `grace_until` and `revoked_at` are compared against `now()` to answer whether
 * a paying customer gets in, so a two-hour timezone slip is not a formatting
 * bug — it is two hours of access nobody paid for, or two hours of a locked-out
 * customer.
 *
 * Two things this replaces, both of which bite in practice:
 *
 * **Laravel's `datetime` cast does not convert.** It formats whatever Carbon it
 * is handed *in that value's own timezone* and writes the result verbatim, so
 * assigning 19:00 Europe/Berlin stores the string "19:00" and reading it back
 * yields 19:00 UTC — two hours out, silently, and only for callers who bothered
 * to state a timezone at all.
 *
 * **A custom `CastsAttributes` class does not help.**
 * `setClassCastableAttribute()` writes the converted value to `$attributes` and
 * then caches the *original object* in `$classCastCache`, so reading the
 * attribute back in the same request hands out exactly what was assigned —
 * mutable `Illuminate\Support\Carbon` included, in whatever zone it arrived in.
 *
 * An `Attribute` has no such cache unless it asks for one, so the accessor runs
 * against the stored string every time and there is exactly one answer.
 *
 * The two reading rules, both deliberate:
 *
 *  - A `DateTimeInterface` keeps its own zone and is converted. That is the
 *    honest reading of "access starts at 19:00 in Berlin".
 *  - A bare string with no zone is read as **UTC**, not as the application
 *    timezone. The column is documented as UTC and a string is the shape a
 *    database row arrives in; guessing the app timezone would make the same
 *    literal mean different instants on two servers.
 */
class UtcDateTime
{
    /** @return Attribute<CarbonImmutable|null, string|null> */
    public static function attribute(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value) => self::fromStorage($value),
            set: fn (mixed $value) => self::toStorage($value),
        )
            // Not optional. Object caching is on by default and it stores the
            // value that was *assigned*, so `$grant->expires_at = now()` followed
            // by reading it back returns the mutable Carbon that went in rather
            // than the UTC CarbonImmutable this accessor promises — and the
            // events type-hint CarbonImmutable.
            ->withoutObjectCaching();
    }

    public static function fromStorage(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::toCarbon($value)->utc();
    }

    public static function toStorage(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::toCarbon($value)->utc()->format('Y-m-d H:i:s');
    }

    private static function toCarbon(mixed $value): CarbonImmutable
    {
        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value, 'UTC');
    }
}
