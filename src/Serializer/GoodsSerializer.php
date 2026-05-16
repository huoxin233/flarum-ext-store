<?php

namespace Mattoid\Store\Serializer;

use Flarum\Api\Serializer\AbstractSerializer;
use Flarum\Locale\Translator;

/**
 * 序列化商品元类型（运行时注册表中的 Goods 元数据）
 * Serialize the Goods metadata from the runtime registry.
 */
class GoodsSerializer extends AbstractSerializer
{
    protected $type = 'mattoid-store-goods';

    protected $translator;

    public function __construct(Translator $translator)
    {
        $this->translator = $translator;
    }

    /**
     * 使用 code 作为 JSON:API 的 id（Solution A 下无独立数字主键）
     * Use `code` as JSON:API id (no separate numeric PK under Solution A)
     */
    public function getId($model)
    {
        return $model->code;
    }

    protected function getDefaultAttributes($data)
    {
        return [
            'code' => $data->code,
            'name' => $this->translator->trans($data->name),
        ];
    }
}
