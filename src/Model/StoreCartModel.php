<?php

namespace Mattoid\Store\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 购物车 / 购买记录模型（store_cart 表）
 * Cart / purchase history model.
 *
 * @property int    $id
 * @property int    $user_id
 * @property int    $store_id
 * @property string $code
 * @property string $title
 * @property float  $price
 * @property float  $pay_amt
 * @property string $type
 * @property string $outtime
 * @property int    $status
 * @property int    $auto_deduction
 * @property int    $enable
 */
class StoreCartModel extends AbstractModel
{
    protected $table = 'store_cart';

    protected $fillable = [
        'user_id', 'store_id', 'code', 'title',
        'price', 'pay_amt', 'type', 'outtime',
        'status', 'auto_deduction', 'enable',
        'created_at', 'updated_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'pay_amt' => 'decimal:2',
        'status' => 'integer',
        'user_id' => 'integer',
        'store_id' => 'integer',
        'auto_deduction' => 'integer',
        'enable' => 'integer',
        'outtime' => 'datetime',
    ];

    protected $dates = ['created_at', 'updated_at', 'outtime'];

    /**
     * 用户关联
     * Belongs-to User relation.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 商品关联（支持读取已软删除商品的快照信息）
     * Belongs-to Store relation (withTrashed for orphan resilience).
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(StoreModel::class, 'store_id')->withTrashed();
    }
}
