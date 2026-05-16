<?php

namespace Mattoid\Store\Serializer;

use Flarum\Api\Serializer\AbstractSerializer;
use Flarum\Locale\Translator;
use Mattoid\Store\Extend\StoreExtend;

class StoreSerializer extends AbstractSerializer
{
    protected $translator;

    public function __construct(Translator $translator)
    {
        $this->translator = $translator;
    }

    protected function getDefaultAttributes($data)
    {
        // 从运行时注册表读取最新 popUp / className，避免 DB 快照过期
        // Read popUp / className from runtime registry to avoid stale DB snapshots
        $popUp = '[]';
        $className = 'store-buy Modal--small';
        $orphaned = true;

        if ($data->code) {
            $goods = StoreExtend::getStoreGoods($data->code);
            if ($goods) {
                $orphaned = false;
                $reflection = new \ReflectionObject($goods);
                if ($reflection->hasProperty('popUp')) {
                    $popProp = $reflection->getProperty('popUp');
                    $popProp->setAccessible(true);
                    $popUp = json_encode($popProp->getValue($goods) ?: []);
                }
                if ($reflection->hasProperty('className')) {
                    $clsProp = $reflection->getProperty('className');
                    $clsProp->setAccessible(true);
                    $value = $clsProp->getValue($goods);
                    if ($value) {
                        $className = $value;
                    }
                }
            }
        }

        return [
            'id' => $data->id,
            'code' => $data->code,
            'title' => $data->title,
            'price' => $data->price,
            'stock' => $data->stock,
            'discount' => $data->discount,
            'discountLimit' => $data->discount_limit,
            'discountLimitUnit' => $data->discount_limit_unit,
            'discountPrice' => $data->discount_price,
            'type' => $data->type,
            'outtime' => $data->outtime,
            'icon' => $data->icon,
            'hide' => $data->hide,
            'repeat' => $data->repeat,
            'desc' => $data->desc,
            'popUp' => $popUp,
            'className' => $className,
            'orphaned' => $orphaned,
            'status' => $data->status,
            'autoDeduction' => $data->auto_deduction,
            'createdAt' => $data->created_at,
            'updatedAt' => $data->updated_at,
        ];
    }
}
