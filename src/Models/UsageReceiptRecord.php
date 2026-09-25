<?php

namespace Goldnead\Entitlements\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Database\Eloquent\Model;

/**
 * The server's copy of one booking. See the migration for why it exists.
 *
 * Brand-scoped like every table here: a receipt presented in another brand is
 * not found, whatever `brand_id` the caller's array claims.
 *
 * @property string $id
 * @property int $brand_id
 * @property int $usage_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $limit_key
 * @property string $period_key
 * @property int $amount
 * @property int $released
 */
class UsageReceiptRecord extends Model
{
    use HasBrand;

    protected $table = 'entitlement_usage_receipts';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'usage_id' => 'integer',
            'amount' => 'integer',
            'released' => 'integer',
        ];
    }

    public function holder(): SubjectReference
    {
        return new SubjectReference($this->subject_type, $this->subject_id);
    }
}
