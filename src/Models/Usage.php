<?php

namespace Goldnead\Entitlements\Models;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Entitlements\Casts\UtcDateTime;
use Goldnead\Entitlements\Limits\QuotaManager;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * How much one subject has used of one limit in one period.
 *
 * Written only through {@see QuotaManager}, and
 * only by conditional UPDATEs: the count is never read, added to and written
 * back, because that is the race two simultaneous bookings on the last slot win.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $limit_key
 * @property string $period_key
 * @property int $used
 * @property int|null $limit_value
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property CarbonImmutable|null $reached_at
 * @property CarbonImmutable|null $rolled_over_at
 */
class Usage extends Model
{
    use HasBrand;

    protected $table = 'entitlement_usages';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'used' => 'integer',
            'limit_value' => 'integer',
        ];
    }

    protected function periodStart(): Attribute
    {
        return UtcDateTime::attribute();
    }

    protected function periodEnd(): Attribute
    {
        return UtcDateTime::attribute();
    }

    protected function reachedAt(): Attribute
    {
        return UtcDateTime::attribute();
    }

    protected function rolledOverAt(): Attribute
    {
        return UtcDateTime::attribute();
    }

    public function subjectKey(): string
    {
        return $this->subject_type.':'.$this->subject_id;
    }
}
