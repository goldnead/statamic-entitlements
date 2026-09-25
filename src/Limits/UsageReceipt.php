<?php

namespace Goldnead\Entitlements\Limits;

use Goldnead\Entitlements\Support\SubjectReference;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Proof of one booking: where it went, so it can be given back there.
 *
 * `consume()` returns it; `release(..., receipt: $receipt)` books the release
 * into exactly that counter. Without it, a release happens in whatever period
 * is current — and a job booked on 31 March that fails on 1 April would lower
 * April's counter and leave March's full.
 *
 * Scalars only, so it survives a queue: store `toArray()` on the job or in a
 * column and hand the array back to `release()` (or `fromArray()` it).
 * `id` is the booking's own identity; a receipt releases once.
 */
final readonly class UsageReceipt implements JsonSerializable
{
    public function __construct(
        public string $id,
        public string $holderType,
        public string $holderId,
        public string $key,
        public string $periodKey,
        public int $amount,
        public int $brandId,
    ) {}

    public function holder(): SubjectReference
    {
        return new SubjectReference($this->holderType, $this->holderId);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'holder_type', 'holder_id', 'key', 'period_key', 'amount', 'brand_id'] as $field) {
            if (! isset($data[$field]) || $data[$field] === '') {
                throw new InvalidArgumentException('A usage receipt needs ['.$field.'].');
            }
        }

        return new self(
            id: (string) $data['id'],
            holderType: (string) $data['holder_type'],
            holderId: (string) $data['holder_id'],
            key: (string) $data['key'],
            periodKey: (string) $data['period_key'],
            amount: (int) $data['amount'],
            brandId: (int) $data['brand_id'],
        );
    }

    /** @return array{id: string, holder_type: string, holder_id: string, key: string, period_key: string, amount: int, brand_id: int} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'holder_type' => $this->holderType,
            'holder_id' => $this->holderId,
            'key' => $this->key,
            'period_key' => $this->periodKey,
            'amount' => $this->amount,
            'brand_id' => $this->brandId,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
