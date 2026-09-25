<?php

namespace Goldnead\Entitlements\Limits;

use Carbon\CarbonImmutable;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\SubjectReference;
use JsonSerializable;

/**
 * One limit, as it applies to one subject right now.
 *
 * `limit` null is unlimited, 0 is none. `holder` is the subject whose grant the
 * limit comes from and at which usage is counted — a team, when a member uses
 * the team's plan. `source` says where the number came from: a grant, the
 * fallback product (a free plan for everybody without one), or nowhere.
 */
final readonly class Quota implements JsonSerializable
{
    public const KIND_USAGE = 'usage';

    public const KIND_STOCK = 'stock';

    public const SOURCE_GRANT = 'grant';

    public const SOURCE_FALLBACK = 'fallback';

    public const SOURCE_NONE = 'none';

    public function __construct(
        public string $key,
        public ?int $limit,
        public string $kind,
        public ?string $period,
        public ?SubjectReference $holder,
        public string $source,
        public ?string $product = null,
        public ?Entitlement $entitlement = null,
        public ?int $used = null,
        public ?CarbonImmutable $periodStart = null,
        public ?CarbonImmutable $periodEnd = null,
    ) {}

    public function unlimited(): bool
    {
        return $this->limit === null && $this->source !== self::SOURCE_NONE;
    }

    /** What is left: null when unlimited, never below zero. Null too for a stock limit nobody counted. */
    public function remaining(): ?int
    {
        if ($this->unlimited()) {
            return null;
        }

        if ($this->used === null) {
            return $this->kind === self::KIND_STOCK ? null : (int) $this->limit;
        }

        return max(0, (int) $this->limit - $this->used);
    }

    public function reached(): bool
    {
        return ! $this->unlimited() && $this->used !== null && $this->used >= (int) $this->limit;
    }

    public function withUsed(?int $used): self
    {
        return new self(
            $this->key, $this->limit, $this->kind, $this->period, $this->holder, $this->source,
            $this->product, $this->entitlement, $used, $this->periodStart, $this->periodEnd,
        );
    }

    /**
     * @return array{key: string, kind: string, limit: int|null, unlimited: bool, used: int|null, remaining: int|null, period: string|null, period_start: string|null, period_end: string|null, holder: string|null, source: string, product: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind,
            'limit' => $this->limit,
            'unlimited' => $this->unlimited(),
            'used' => $this->used,
            'remaining' => $this->remaining(),
            'period' => $this->period,
            'period_start' => $this->periodStart?->toIso8601String(),
            'period_end' => $this->periodEnd?->toIso8601String(),
            'holder' => $this->holder?->key(),
            'source' => $this->source,
            'product' => $this->product,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
