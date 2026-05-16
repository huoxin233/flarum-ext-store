<?php

namespace Mattoid\Store\Extend;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extend\LifecycleInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;
use Mattoid\Store\Goods\After;
use Mattoid\Store\Goods\Enable;
use Mattoid\Store\Goods\Goods;
use Mattoid\Store\Goods\Invalid;
use Mattoid\Store\Goods\Validate;
use Mattoid\Store\Model\StoreModel;

/**
 * 商品注册扩展点（运行时聚合方案 - Solution A）
 * Product registration extension point (runtime aggregation - Solution A)
 *
 * 设计目标：
 * - 商品类型元数据的「唯一权威源」是商品插件代码自身（运行时静态注册表）；
 * - 不再依赖 store_goods 数据库表；
 * - composer remove / 插件禁用后，商品类型立即消失；
 * - popUp / className 升级即时生效，无需 disable+enable。
 *
 * Design goals:
 * - The only authoritative source for product metadata is the plugin code itself
 *   (in-memory runtime registry);
 * - No longer depends on the store_goods table;
 * - When the plugin is removed via composer, the product type disappears immediately;
 * - popUp / className upgrades take effect immediately without disable+enable cycle.
 *
 * @link docs/06-plugin-registration-redesign.md
 */
class StoreExtend implements ExtenderInterface, LifecycleInterface
{
    /**
     * 运行时注册表（唯一权威源）
     * Runtime registry (single source of truth)
     *
     * 结构：
     * [
     *   'code-key' => [
     *     'goods'    => GoodsClass::class,
     *     'validate' => ValidateClass::class,
     *     'after'    => AfterClass::class,
     *     'invalid'  => InvalidClass::class,
     *     'enable'   => EnableClass::class,
     *   ],
     * ]
     *
     * @var array<string, array<string, string|null>>
     */
    private static $registry = [];

    /**
     * 当前注册项的 code（商品唯一标识）
     * Current product unique identifier key
     */
    private $key = '';

    public function __construct(string $key = '')
    {
        $this->key = $key;
        // 占位空记录，保证后续 add* 调用前 all() 返回中已包含该 key
        // Placeholder to ensure all() includes this key even if no class is added yet
        if ($key !== '' && ! isset(self::$registry[$key])) {
            self::$registry[$key] = [
                'goods' => null,
                'validate' => null,
                'after' => null,
                'invalid' => null,
                'enable' => null,
            ];
        }
    }

    /**
     * 注册商品元信息类
     * Register product metadata class
     *
     * @param  class-string<Goods>  $callback
     */
    public function addStoreGoods($callback): self
    {
        self::$registry[$this->key]['goods'] = $callback;
        return $this;
    }

    /**
     * 注册购买前置校验
     * Register pre-purchase validator
     *
     * @param  class-string<Validate>  $callback
     */
    public function addValidate($callback): self
    {
        self::$registry[$this->key]['validate'] = $callback;
        return $this;
    }

    /**
     * 注册购买成功后业务
     * Register post-purchase handler
     *
     * @param  class-string<After>  $callback
     */
    public function addAfter($callback): self
    {
        self::$registry[$this->key]['after'] = $callback;
        return $this;
    }

    /**
     * 注册商品失效逻辑
     * Register product invalidation handler
     *
     * @param  class-string<Invalid>  $callback
     */
    public function addInvalid($callback): self
    {
        self::$registry[$this->key]['invalid'] = $callback;
        return $this;
    }

    /**
     * 注册商品使用逻辑
     * Register product enable handler
     *
     * @param  class-string<Enable>  $callback
     */
    public function addEnable($callback): self
    {
        self::$registry[$this->key]['enable'] = $callback;
        return $this;
    }

    /**
     * 获取所有已注册的商品 code
     * Get all registered product codes
     *
     * @return array<string>
     */
    public static function codes(): array
    {
        return array_keys(self::$registry);
    }

    /**
     * 获取完整注册表
     * Get the full registry
     *
     * @return array<string, array<string, string|null>>
     */
    public static function all(): array
    {
        return self::$registry;
    }

    /**
     * 判断商品 code 是否已注册（且包含 Goods 元数据）
     * Check whether a product code is registered (with goods metadata)
     */
    public static function has(string $key): bool
    {
        return isset(self::$registry[$key]['goods']) && self::$registry[$key]['goods'] !== null;
    }

    /**
     * 获取商品元数据实例
     * Get the Goods metadata instance
     */
    public static function getStoreGoods(string $key): ?Goods
    {
        $class = self::$registry[$key]['goods'] ?? null;
        return $class ? self::resolve($class) : null;
    }

    /**
     * 获取购买前置校验实例
     * Get the Validate handler instance
     */
    public static function getValidate(string $key): ?Validate
    {
        $class = self::$registry[$key]['validate'] ?? null;
        return $class ? self::resolve($class) : null;
    }

    /**
     * 获取购买后置业务实例
     * Get the After handler instance
     */
    public static function getAfter(string $key): ?After
    {
        $class = self::$registry[$key]['after'] ?? null;
        return $class ? self::resolve($class) : null;
    }

    /**
     * 获取商品失效逻辑实例
     * Get the Invalid handler instance
     */
    public static function getInvalid(string $key): ?Invalid
    {
        $class = self::$registry[$key]['invalid'] ?? null;
        return $class ? self::resolve($class) : null;
    }

    /**
     * 获取商品使用逻辑实例
     * Get the Enable handler instance
     */
    public static function getEnable(string $key): ?Enable
    {
        $class = self::$registry[$key]['enable'] ?? null;
        return $class ? self::resolve($class) : null;
    }

    /**
     * 通过容器实例化，支持构造器注入
     * Instantiate via container to support constructor injection
     */
    private static function resolve(string $class)
    {
        // 容器可用时走 DI，否则降级到 new（测试环境）
        // Use DI when container is available; fallback to plain new for tests
        if (function_exists('resolve')) {
            return resolve($class);
        }
        return new $class;
    }

    /**
     * ExtenderInterface 入口（运行时方案下无需绑定容器）
     * ExtenderInterface hook (no container binding required for runtime approach)
     */
    public function extend(Container $container, Extension $extension = null)
    {
        // Solution A: 注册表完全在内存中，无需 DI 绑定
        // Registry lives entirely in memory; no container binding needed
    }

    /**
     * 插件启用回调
     * Plugin enable callback
     *
     * Solution A 下不再写入 store_goods 表，仅保留一个可恢复下架商品的安全网。
     * Under Solution A we no longer write to store_goods table; we only restore
     * previously disabled stores as a safety net (no-op if none).
     */
    public function onEnable(Container $container, Extension $extension)
    {
        // 兼容方案 B 历史数据：清理可能残留的旧 store_goods 行（如有该表）
        // 注意：本插件不再创建/维护 store_goods 表，但为了不影响升级用户，
        // 这里做尽力而为的清理，失败也无影响。
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('store_goods')) {
                \Illuminate\Support\Facades\DB::table('store_goods')->where('code', $this->key)->delete();
            }
        } catch (\Throwable $e) {
            // best-effort: ignore
        }
    }

    /**
     * 插件禁用回调
     * Plugin disable callback
     *
     * 当商品插件被禁用时，自动下架对应商品（status=0），但保留 store_cart 历史记录。
     * When a product plugin is disabled, auto-deactivate matching store rows
     * (status=0). store_cart history is preserved.
     */
    public function onDisable(Container $container, Extension $extension)
    {
        try {
            StoreModel::query()->where('code', $this->key)->update(['status' => 0]);
        } catch (\Throwable $e) {
            // best-effort
        }

        // 同时清理可能残留的旧 store_goods 行
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('store_goods')) {
                \Illuminate\Support\Facades\DB::table('store_goods')->where('code', $this->key)->delete();
            }
        } catch (\Throwable $e) {
            // best-effort
        }
    }
}
