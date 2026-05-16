<?php

namespace Mattoid\Store\Support;

/**
 * 缓存 key 命名空间（V-09 / V-15）
 * Cache key namespaces (V-09 / V-15).
 *
 * 不同业务路径的分布式锁必须使用独立的 key 命名空间，否则会出现互锁。
 * Distinct business paths must use distinct lock namespaces to avoid cross-locking.
 */
final class CacheKeys
{
    /**
     * 购买商品时的防重锁
     * Anti-duplicate lock for purchase requests.
     */
    public static function buyLock($storeId, $userId): string
    {
        return 'mattoid-store:buy-lock:'.$storeId.':'.$userId;
    }

    /**
     * 自动续费扣款锁
     * Lock for auto-deduction job.
     */
    public static function autoDeductionLock($storeId, $userId): string
    {
        return 'mattoid-store:auto-deduction-lock:'.$storeId.':'.$userId;
    }
}
