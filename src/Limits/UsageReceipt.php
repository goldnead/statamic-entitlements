<?php

namespace Goldnead\Entitlements\Limits;

use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;

/**
 * A handle on one booking, so it can be given back where it was booked.
 *
 * `consume()` returns it; `release(..., receipt: $receipt)` books the release
 * into exactly that counter. Without it, a release happens in whatever period
 * is current — and a job booked on 31 March that fails on 1 April would lower
 * April's counter and leave March's full.
 *
 * Scalars only, so it survives a queue: store `toArray()` on the job or in a
 * column and hand the array back to `release()`. **Only the `id` is read back.**
 * The server keeps its own copy of every receipt (`entitlement_usage_receipts`)
 * and a release is checked against that copy: holder, key, period, amount and
 * brand in the array are for the caller's information and are ignored.
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

    /**
     * The one thing a release takes from what the caller presents: the id.
     * Everything else is read from the stored copy. An id that is not a UUID
     * is refused, never shortened into one that might exist.
     *
     * @param  self|array<string, mixed>  $receipt
     */
    public static function idOf(self|array $receipt): string
    {
        $id = $receipt instanceof self ? $receipt->id : ($receipt['id'] ?? null);

        if (! is_string($id) || ! Str::isUuid($id)) {
            throw new InvalidArgumentException('A usage receipt needs the id consume() gave it.');
        }

        return strtolower($id);
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
