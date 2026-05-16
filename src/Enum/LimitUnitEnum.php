<?php

namespace Mattoid\Store\Enum;

/**
 * 折扣期限单位定义
 * Discount limit unit definition.
 *
 * 移除原硬编码中文（'天'/'小时'/'分钟'/'秒'）；使用 i18n key 由调用方翻译。
 * Removed previously hardcoded Chinese labels; callers should resolve via i18n.
 */
class LimitUnitEnum
{
    /**
     * 单位枚举（与 PostStoreController::ALLOWED_LIMIT_UNITS 保持一致）
     * Enum (kept in sync with PostStoreController::ALLOWED_LIMIT_UNITS)
     */
    public const UNITS = ['days', 'hour', 'minute', 'second'];

    /**
     * 各单位对应的 i18n key
     * Translation key for each unit
     */
    public const TRANSLATION_KEYS = [
        'days'   => 'mattoid-store.lib.item-limit-unit-days',
        'hour'   => 'mattoid-store.lib.item-limit-unit-hour',
        'minute' => 'mattoid-store.lib.item-limit-unit-minute',
        'second' => 'mattoid-store.lib.item-limit-unit-second',
    ];

    /**
     * @deprecated 历史 API，保留以兼容；不要在新代码中使用。
     *             Legacy API kept for BC; do not use in new code.
     */
    public static $LIMIT_UNIT = ['days' => 'days', 'hour' => 'hour', 'minute' => 'minute', 'second' => 'second'];
}
