<?php

namespace Mattoid\Store\Model;

use Flarum\Database\AbstractModel;

/**
 * 图标库模型（store_goods_icon 表）
 * Icon library model.
 *
 * @property int    $id
 * @property string $uuid  文件 MD5 hash（去重）
 * @property string $url   图标访问路径
 * @property int    $count 引用计数
 */
class StoreGoodsIconModel extends AbstractModel
{
    protected $table = 'store_goods_icon';

    protected $fillable = [
        'uuid', 'url', 'count', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'count' => 'integer',
    ];

    protected $dates = ['created_at', 'updated_at'];
}
