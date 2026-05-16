<?php

namespace Mattoid\Store\Goods;

/**
 * 商品信息类，用于定义商品名称、购买弹窗等信息
 *
 * Product information class, used to define product names, purchase pop ups, and other information.
 *
 * Solution A 下属性改为 public，便于 StoreExtend / Serializer 运行时聚合读取，
 * 无需反射（保留 protected 在 BC 实现下也能工作，本插件内统一改为 public 提速）。
 *
 * Under Solution A, properties are public so they can be read directly by StoreExtend
 * and serializers at runtime without reflection.
 */
abstract class Goods
{
    /**
     * 商品名称（i18n key）
     * Product name (i18n key)
     *
     * @var string
     */
    public $name;

    /**
     * 弹窗内容
     * Pop up content
     *
     * @var array
     *
     * @example
     * [
     *   [
     *     // @label 当前行的说明、标题等 i18n key
     *     // @label i18n key for the row label / title
     *     'label' => 'mattoid-store-invite.forum.email',
     *
     *     // @prop 元素类型。目前支持（input / switch / textarea / select）
     *     // @prop Element type. Supported: input / switch / textarea / select
     *     'prop' => 'input',
     *
     *     // @type 元素的 type 属性，通常用于 input 中
     *     // @type The type attribute, typically used with input
     *     'type' => 'email',
     *
     *     // @value 元素值传输的 key，用于商品插件接收
     *     // @value Field key carried in the buy payload
     *     'value' => 'email',
     *
     *     // @helpText 当前行的补充说明
     *     // @helpText Additional explanation for the current row
     *     'helpText' => 'mattoid-store-invite.forum.email-help',
     *   ],
     * ]
     */
    public $popUp = [];

    /**
     * 弹窗样式
     * Pop up class
     *
     * @var string
     */
    public $className = 'store-buy Modal--small';
}
