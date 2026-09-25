<?php

namespace Goldnead\Entitlements\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;

/**
 * One number a product allows: `analyses` = 50 per year, `arrangements` = 10.
 *
 * `value` null means unlimited, `period` null means a stock limit the caller
 * counts itself. See the migration for why those two are different statements.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $product_slug
 * @property string $limit_key
 * @property int|null $value
 * @property string|null $period
 */
class ProductLimit extends Model
{
    use HasBrand;

    protected $table = 'entitlement_limits';

    protected $fillable = ['product_slug', 'limit_key', 'value', 'period'];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }
}
