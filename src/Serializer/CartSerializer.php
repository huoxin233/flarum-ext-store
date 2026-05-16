<?php

namespace Mattoid\Store\Serializer;

use Flarum\Api\Serializer\AbstractSerializer;
use Flarum\Locale\Translator;
use Mattoid\Store\Extend\StoreExtend;

class CartSerializer extends AbstractSerializer
{
    protected $translator;

    public function __construct(Translator $translator)
    {
        $this->translator = $translator;
    }

    protected function getDefaultAttributes($data)
    {
        // 商品类型已卸载时标记为 orphaned，前端可禁用按钮 / 标记 UI
        // Mark as orphaned when the product type is no longer registered
        $orphaned = $data->code ? ! StoreExtend::has($data->code) : true;

        return [
            'id' => $data->id,
            'storeId' => $data->store_id,
            'code' => $data->code,
            'title' => $data->title,
            'price' => $data->price,
            'payAmt' => $data->pay_amt,
            'type' => $data->type,
            'outtime' => $data->outtime,
            'status' => $data->status,
            'createdAt' => $data->created_at,
            'updatedAt' => $data->updated_at,
            'autoDeduction' => $data->auto_deduction,
            'enableType' => $data->enableType ?? 0,
            'enable' => $data->enable,
            'orphaned' => $orphaned,
        ];
    }
}
