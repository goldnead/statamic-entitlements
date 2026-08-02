<?php

namespace Goldnead\Entitlements\Models;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Entitlements\Casts\UtcDateTime;
use Goldnead\Entitlements\Database\Factories\EntitlementFactory;
use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Support\StateResolver;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A single grant: this subject may use this product, from this source, for this
 * window.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $product_slug
 * @property string $source
 * @property string $source_ref
 * @property string $status
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $grace_until
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_reason
 * @property string|null $announced_state
 * @property array<string, mixed>|null $meta
 */
class Entitlement extends Model
{
    /** @use HasFactory<EntitlementFactory> */
    use HasFactory;

    use HasBrand;

    protected $table = 'entitlements';

    /**
     * Mass assignment is closed rather than open.
     *
     * `$guarded = []` on a model whose columns decide access means any array
     * that reaches `fill()` can set `brand_id`, `status` or `revoked_at`. The
     * manager writes the sensitive columns explicitly; the Control Panel form
     * only ever reaches the ones listed here.
     */
    protected $fillable = [
        'subject_type',
        'subject_id',
        'product_slug',
        'source',
        'source_ref',
        'starts_at',
        'expires_at',
        'grace_until',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    /**
     * The four date columns, all UTC.
     *
     * Not `'datetime'`: Laravel's datetime cast writes a zoned Carbon without
     * converting it, so a value assigned in Europe/Berlin is stored two hours
     * early and every access comparison against it is two hours wrong. See
     * {@see UtcDateTime}.
     */
    protected function startsAt(): Attribute
    {
        return UtcDateTime::attribute();
    }

    protected function expiresAt(): Attribute
    {
        return UtcDateTime::attribute();
    }

    protected function graceUntil(): Attribute
    {
        return UtcDateTime::attribute();
    }

    protected function revokedAt(): Attribute
    {
        return UtcDateTime::attribute();
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The state of this grant right now.
     *
     * Delegates to the one resolver. There is no second implementation anywhere
     * in this package, and that is the single most important property it has:
     * the codebase it was extracted from had two, they disagreed about `pending`,
     * and the disagreement meant a grant awaiting double-opt-in confirmation
     * handed out access through one of the two paths.
     */
    public function state(): EntitlementState
    {
        return StateResolver::resolve($this);
    }

    public function grantsAccess(): bool
    {
        return $this->state()->grantsAccess();
    }

    public function isRevoked(): bool
    {
        return $this->state() === EntitlementState::Revoked;
    }

    /** Whether this grant carries an external reference; absence is stored as the empty string. */
    public function hasSourceRef(): bool
    {
        return $this->source_ref !== '' && $this->source_ref !== null;
    }

    /**
     * A stable, human-readable handle for the subject, for listings and logs.
     *
     * Never resolves the related model: a grant outlives the record it points
     * at, and a listing that lazily loads a subject per row is a listing that
     * issues one query per row.
     */
    public function subjectKey(): string
    {
        return $this->subject_type.':'.$this->subject_id;
    }

    protected static function newFactory(): EntitlementFactory
    {
        return EntitlementFactory::new();
    }
}
