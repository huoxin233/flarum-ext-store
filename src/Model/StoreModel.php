<?php

namespace Mattoid\Store\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoreModel extends AbstractModel
{
    use SoftDeletes;

    protected $table = "store";

    /** 开启 Eloquent 自动时间戳（UTC） */
    public $timestamps = true;

    /** 无限库存标记值 */
    const STOCK_UNLIMITED = -99;

    protected $fillable = [
        'code', 'title', 'class_name', 'price', 'stock',
        'discount', 'discount_limit', 'discount_limit_unit', 'discount_price', 'discount_start_at',
        'type', 'status', 'outtime', 'icon', 'hide', 'repeat', 'desc', 'pop_up',
        'auto_deduction', 'use_type', 'subtype', 'group',
        'purchase_limit_enable', 'purchase_limit_count',
        'purchase_limit_period', 'purchase_limit_period_unit',
        'usable', 'sort',
    ];

    protected $dates = ['discount_start_at'];

    protected $casts = [
        'price' => 'float',
        'discount_price' => 'float',
    ];

    public function carts()
    {
        return $this->hasMany(StoreCartModel::class, 'store_id');
    }

    /**
     * 累计销量：统计历史上所有购买/赠送成功的订单（已支付 1、已使用 3、已失效 9）
     * 优先使用 withCount 聚合出的 sold_count 字段，单行查询时按需懒加载
     */
    public function getSoldCountAttribute(): int
    {
        if (array_key_exists('sold_count', $this->attributes)) {
            return (int) $this->attributes['sold_count'];
        }

        return (int) $this->carts()->whereIn('status', [1, 3, 9])->count();
    }

    /**
     * 当前在用/持有用户数：统计当前处于有效持有期内（status = 1 且未过期）的记录数
     * 优先使用 withCount 聚合出的 active_count 字段，单行查询时按需懒加载
     */
    public function getActiveCountAttribute(): int
    {
        if (array_key_exists('active_count', $this->attributes)) {
            return (int) $this->attributes['active_count'];
        }

        return (int) $this->carts()
            ->where('status', 1)
            ->where(function ($q) {
                $q->where('type', 'permanent')
                    ->orWhere('outtime', '>=', Carbon::now());
            })
            ->count();
    }
}
