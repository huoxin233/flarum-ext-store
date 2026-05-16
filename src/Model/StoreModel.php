<?php

namespace Mattoid\Store\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 商品模型（store 表）
 * Store product model.
 *
 * @property int    $id
 * @property string $code
 * @property string $title
 * @property string $desc
 * @property string $icon
 * @property float  $price
 * @property int    $stock
 * @property int    $discount
 * @property int    $discount_limit
 * @property string $discount_limit_unit
 * @property float  $discount_price
 * @property string $type
 * @property int    $status
 * @property int    $outtime
 * @property int    $hide
 * @property int    $repeat
 * @property int    $auto_deduction
 */
class StoreModel extends AbstractModel
{
    use SoftDeletes;

    protected $table = 'store';

    protected $fillable = [
        'code', 'title', 'desc', 'icon',
        'price', 'stock', 'type', 'outtime',
        'discount', 'discount_limit', 'discount_limit_unit', 'discount_price',
        'hide', 'repeat', 'status', 'auto_deduction',
        'created_at', 'updated_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discount_price' => 'decimal:2',
        'stock' => 'integer',
        'discount' => 'integer',
        'discount_limit' => 'integer',
        'status' => 'integer',
        'outtime' => 'integer',
        'hide' => 'integer',
        'repeat' => 'integer',
        'auto_deduction' => 'integer',
    ];

    protected $dates = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * 购物车记录关联
     * Cart relation.
     */
    public function carts()
    {
        return $this->hasMany(StoreCartModel::class, 'store_id');
    }
}
