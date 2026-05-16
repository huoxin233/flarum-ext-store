# 06 - 商品插件注入机制重构

> 范围：分析当前 `StoreExtend` + `store_goods` 表的注册机制存在的脆弱性，并给出多种替代方案与推荐路径。
>
> 关键问题：商品类型元数据（name / popUp / class_name）依赖 `LifecycleInterface::onEnable` 时一次性插入 `store_goods` 表。**插入失败 → 商品类型彻底不可用**，且没有任何自愈机制。

---

## 1. 当前机制回顾

### 1.1 数据来源「两套」并存

```
┌──────────────────────────────────────┐
│  StoreExtend::$goodList (静态数组)    │ ← 每个请求 PHP 重启时由 extend.php 重新填充
│  $goodList['invite'] = InviteGoods   │ ← 内容：类名（class string）
│  $validateList[...] = ...            │
│  $afterList[...] = ...               │
│  $invalidList[...] = ...             │
│  $enableList[...] = ...              │
└──────────────────────────────────────┘
              ↕（仅在 onEnable / onDisable 时单向写入 / 删除）
┌──────────────────────────────────────┐
│  store_goods (数据库表)               │ ← 持久化
│  {code, name, class_name, pop_up}    │
└──────────────────────────────────────┘
```

### 1.2 关键代码

`src/Extend/StoreExtend.php:166-180`：
```php
public function onEnable(Container $container, Extension $extension)
{
    $goods = StoreExtend::getStoreGoods($this->key);     // 从静态数组取
    if ($goods) {
        StoreGoodsModel::query()->insert([                // 写入 DB
            'code' => $this->key,
            'name' => $goods->name,
            'class_name' => $goods->className,
            'pop_up' => json_encode($goods->popUp),
            'created_at' => Carbon::now()->tz($this->storeTimezone)
        ]);
    }
}
```

`src/Extend/StoreExtend.php:190-196`：
```php
public function onDisable(Container $container, Extension $extension)
{
    StoreModel::query()->where('code', $this->key)->update(['status' => 0]);
    StoreGoodsModel::query()->where('code', $this->key)->delete();
}
```

`src/Controller/ListGoodsController.php:51`（管理端"添加商品"列表）：
```php
$list = StoreGoodsModel::query()->orderByDesc('created_at')->get();   // 只读 DB
```

`src/Controller/PostStoreController.php:58`（管理员创建商品时）：
```php
$goods = StoreGoodsModel::query()->where('code', $parseBody['code'])->first();
if (!$goods) {
    throw new ValidationException([...]);    // ⚠ DB 没有 → 商品类型不可用
}
$params['pop_up'] = $goods->pop_up;          // ⚠ popUp 从 DB 读，schema 升级有滞后
$params['class_name'] = $goods->class_name;  // ⚠ 类名从 DB 读
```

---

## 2. 当前机制的 6 大问题

### 2.1 ❗ enable 失败 = 永久瘫痪

`onEnable` 中 `StoreGoodsModel::query()->insert(...)` 在以下场景会失败：

| 场景 | 表现 |
|------|------|
| `store_goods` 表 unique 约束冲突（同 code 残留行） | `QueryException: Duplicate entry` |
| 该插件**之前 enable 过且未正常 disable** | 已存在记录，唯一约束冲突 |
| 数据库连接超时 / 死锁 | 异常 |
| `mattoid/flarum-ext-store` 主插件**还未启用**（自家迁移没跑） | `store_goods` 表不存在 → SQL 错误 |
| 时区配置异常导致 `Carbon::now()` 抛错 | 异常 |

**任一失败的后果**：
- Flarum 中插件标记为"已启用"，但 `store_goods` 没有这条记录；
- 管理员到"添加商品"列表 → **看不到这个类型**；
- 手工 disable + enable 也可能再次失败（unique 冲突仍在）。

**已知规避**：管理员必须用 SQL 手工 `DELETE FROM store_goods WHERE code='xxx'` 后再 enable。**完全不可接受的运维负担**。

### 2.2 ❗ 静态数组 vs DB 长期不一致

- 静态数组 `$goodList` 在每个 PHP 请求都重建（PHP share-nothing 特性）；
- `store_goods` 表只在 enable/disable 时变更；
- 现实场景：
  - 用户 `composer remove mattoid/flarum-ext-store-invite` 后**没有**触发 `onDisable`（因为是 composer 卸载而非 Flarum 后台禁用）；
  - 结果：静态数组里没有 `invite` 这个 code，但 `store_goods` 表里还有；
  - 管理端"添加商品"还能选 invite → 添加成功 → 但购买时 `StoreExtend::getValidate('invite')` 返回 null → **商品功能瘫痪**；
- 反向场景：
  - 管理员直接 `truncate store_goods` 想"重置"；
  - 静态数组依然有数据；
  - 但 `ListGoodsController` 仅查 DB → 看不到任何商品类型。

### 2.3 ❗ popUp schema 升级无法生效

商品插件 v1.0 中：
```php
class InviteGoods extends Goods {
    public $popUp = [
        ['label' => 'count', 'prop' => 'input', 'value' => 'count'],
    ];
}
```

v1.1 升级时新增字段：
```php
public $popUp = [
    ['label' => 'count', 'prop' => 'input', 'value' => 'count'],
    ['label' => 'note', 'prop' => 'textarea', 'value' => 'note'],     // 新增
];
```

**结果**：
- 静态数组立即生效；
- 但 DB `store_goods.pop_up` 仍然是 v1.0 的 JSON；
- 管理端"添加商品"读 DB → 显示旧表单；
- 同样**已添加的 `store` 行**也用旧 popUp（因为 `PostStoreController` 把 `class_name + pop_up` 在 insert 时快照到 store 表）。

**用户必须 disable + enable** 才能"刷新" popUp，且 disable 会下架所有该 code 的 `store` 行！

### 2.4 ❗ 命名冲突无法检测

如果两个插件都用 `(new StoreExtend('vip'))` 注册：
- 静态数组：后者覆盖前者（last-write wins，悄无声息）；
- DB：unique 约束在第二个 enable 时报错；
- 结果：**插件加载顺序决定哪个生效**，调试地狱。

### 2.5 ❗ 抽象基类无法注入 DI

`StoreExtend::getValidate($key)` 内部 `new $class`：
- 不走 Flarum 容器；
- 子类的 `static validate(...)` 无法访问 `$translator/$settings/$events/$cache`（README 注释提到这些依赖但拿不到）；
- 测试时无法 mock。

### 2.6 ❗ store 表的 class_name 字段冗余

`store.class_name` 在 PostStoreController 创建商品时从 `store_goods.class_name` 快照而来：
- 后续如果该商品插件被卸载，`store.class_name` 还存着旧类名；
- 实际购买流程会调 `StoreExtend::getValidate(code)` 而**不读** `store.class_name`；
- 字段实际上**死代码**，仅在前端展示弹窗 CSS 类时有用。

---

## 3. 替代方案对比

### 方案 A · 运行时静态聚合（强烈推荐 ⭐⭐⭐⭐⭐）

**核心思想**：商品类型元数据**完全由内存静态数组提供**，废弃 `store_goods` 表。

```
┌──────────────────────────────────────┐
│  StoreExtend::$registry              │ ← 唯一权威数据源（每次请求由 extend.php 注入）
│  ['invite' => [                      │
│     'goods' => InviteGoods::class,   │
│     'validate' => ...,               │
│     'after' => ...,                  │
│     'invalid' => ...,                │
│     'enable' => ...,                 │
│   ]]                                 │
└──────────────────────────────────────┘
              ↓
       ListGoodsController 直接遍历返回元数据
```

**改造点**：

1. `ListGoodsController::data()` 改为：
   ```php
   $registry = StoreExtend::all();    // 返回所有 code => GoodsMetadata
   return collect($registry)->map(function ($meta, $code) {
       $goods = resolve($meta['goods']);    // 走容器
       return (object) [
           'code' => $code,
           'name' => $this->translator->trans($goods->name),
           'pop_up' => json_encode($goods->popUp),
           'class_name' => $goods->className,
       ];
   })->values();
   ```

2. `PostStoreController` / `PutStoreController` 改为运行时读：
   ```php
   $meta = StoreExtend::all()[$parseBody['code']] ?? null;
   if (!$meta) { throw new ValidationException([...]); }
   $goods = resolve($meta['goods']);
   $params['pop_up'] = json_encode($goods->popUp);
   $params['class_name'] = $goods->className;
   ```

3. `StoreExtend::onEnable/onDisable` 改为：
   ```php
   public function onEnable(...) {
       // 仅做"下架对应商品的状态恢复"等可选操作，不再写 store_goods
   }
   public function onDisable(...) {
       StoreModel::query()->where('code', $this->key)->update(['status' => 0]);
       // 不再删 store_goods（因为表本身废弃）
   }
   ```

4. 数据库迁移：废弃 `store_goods` 表（保留备份），或保留为可选缓存表（参见方案 B 的 reconcile）。

**优势**：
- ✅ 完全消除 enable/disable 时 DB 写入失败的问题；
- ✅ popUp / className 升级**即时生效**；
- ✅ 静态数组与 DB 永远一致（因为只有一个权威源）；
- ✅ composer remove 后立刻"消失"（不会留下幽灵商品类型）；
- ✅ 简化代码、删除 ~30 行；
- ✅ 测试时只需在 setUp 中操作静态数组。

**劣势 / 注意点**：
- ⚠ 已经被添加到 `store` 表的商品行，如果插件被卸载，`store.code` 找不到对应 metadata：
  - `BuyGoodsController` 中 `StoreExtend::getValidate($store->code)` 返回 null，purchases 仍然能跑（plugin 仅是钩子）；
  - 但 `StoreExtend::getAfter()` 返回 null → 购买不会真正发货；
  - **解决**：在 admin 端列表 + 购买时检查 `StoreExtend::all()[$store->code]` 是否存在，若无则在 UI 标记"未注册"且禁止购买。
- ⚠ `Goods` 实例的 `popUp` 中如果用到了 i18n key，i18n 会随每次请求重新 translate（轻微性能损耗，可忽略）。

### 方案 B · 幂等 reconcile + 自愈（中等推荐 ⭐⭐⭐）

**核心思想**：保留 `store_goods` 表作为缓存，但**每次访问列表时自动同步**。

```
┌──────────────────────┐         ┌──────────────────────┐
│ StoreExtend::$registry│ ──────→ │   store_goods 表      │
│ (内存权威)           │ reconcile│   (持久化缓存)        │
└──────────────────────┘         └──────────────────────┘
```

**改造点**：

1. 新增 `StoreExtend::reconcile()` 方法：
   ```php
   public static function reconcile(string $timezone = 'UTC'): void
   {
       $existing = StoreGoodsModel::query()->pluck('code')->all();
       $registered = array_keys(self::$goodList);

       // 1. 补缺（in-memory 有但 DB 没有）
       foreach (array_diff($registered, $existing) as $code) {
           $goods = self::getStoreGoods($code);
           StoreGoodsModel::query()->insert([
               'code' => $code,
               'name' => $goods->name,
               'class_name' => $goods->className,
               'pop_up' => json_encode($goods->popUp),
               'created_at' => Carbon::now()->tz($timezone),
           ]);
       }

       // 2. 清孤儿（DB 有但 in-memory 没有）
       foreach (array_diff($existing, $registered) as $code) {
           StoreGoodsModel::query()->where('code', $code)->delete();
       }

       // 3. 刷新元数据（in-memory 与 DB 不同时更新）
       foreach (array_intersect($registered, $existing) as $code) {
           $goods = self::getStoreGoods($code);
           StoreGoodsModel::query()->where('code', $code)->update([
               'name' => $goods->name,
               'class_name' => $goods->className,
               'pop_up' => json_encode($goods->popUp),
           ]);
       }
   }
   ```

2. `ListGoodsController::data()` 开头调用：
   ```php
   StoreExtend::reconcile($this->storeTimezone);
   $list = StoreGoodsModel::query()->orderByDesc('created_at')->get();
   ```

3. `onEnable/onDisable` 改为**可选/最佳努力**：
   ```php
   public function onEnable(...) {
       try {
           // 现有逻辑（但失败也无所谓，reconcile 兜底）
       } catch (\Throwable $e) {
           // 日志记录，不抛
       }
   }
   ```

**优势**：
- ✅ 完全向后兼容（不破坏 `store_goods` 表结构）；
- ✅ 自愈：enable 失败、composer remove 卸载、popUp 升级，下次列表访问时自动修复；
- ✅ DB 仍可用于 SQL 查询/统计（如"按商品类型分组的总购买数"）。

**劣势**：
- ⚠ 每次列表访问多一次 reconcile（可加 1 分钟 cache key 缓解）；
- ⚠ 维护两份数据，仍可能有 race condition（但比 onEnable-only 好太多）。

### 方案 C · 容器服务注入（Flarum 原生风格 ⭐⭐⭐⭐）

**核心思想**：使用 Flarum 容器作为注册表，替代静态数组。

```php
// StoreExtend::extend()
public function extend(Container $container, Extension $extension = null)
{
    $container->extend('mattoid-store.registry', function ($registry) {
        $registry[$this->key] = [
            'goods' => $this->goodClass,
            'validate' => $this->validateClass,
            'after' => $this->afterClass,
            'invalid' => $this->invalidClass,
            'enable' => $this->enableClass,
        ];
        return $registry;
    });
}
```

`extend.php` 中预注册空容器：
```php
return [
    // ...
    function (Container $container) {
        $container->singleton('mattoid-store.registry', fn() => []);
    },
];
```

获取时：
```php
public static function get(string $key, string $type)   // type ∈ ['goods','validate','after','invalid','enable']
{
    $registry = resolve('mattoid-store.registry');
    $class = $registry[$key][$type] ?? null;
    return $class ? resolve($class) : null;
}
```

**优势**：
- ✅ 走 Flarum 容器，DI 支持完整（抽象基类可构造器注入）；
- ✅ singleton 缓存，避免重复 `new`；
- ✅ 测试时 `app()->instance('mattoid-store.registry', $mock)` 即可覆盖；
- ✅ 不需要数据库；
- ✅ 完美符合 Flarum 主流风格（参考 `Flarum\Extend\Routes`、`Flarum\Extend\Event` 实现）。

**劣势**：
- ⚠ 与现有 `StoreExtend::getValidate($key)` 静态 API 不兼容，需保留过渡层；
- ⚠ 商品插件需在 `extend.php` 中通过 ExtenderInterface 注册，不能在 boot 后才注册。

### 方案 D · ServiceProvider boot 时同步（不推荐 ⭐⭐）

**核心思想**：在 `Application::booted` 时遍历静态数组，upsert 到 DB。

```php
// extend.php
return [
    function (Application $app) {
        $app->booted(function () {
            StoreExtend::reconcile();
        });
    },
];
```

**优势**：
- ✅ 每次启动都同步一次，比 onEnable-only 自愈频率更高；
- ✅ 兼容现有 DB 结构。

**劣势**：
- ⚠ 每个 Flarum 请求 boot 都做一次（即使 GET 静态资源）；
- ⚠ DB 写延迟会增加首字节时间（TTFB）；
- ⚠ 仍然依赖 DB，没有从根本上解决 DB 失败问题；
- ⚠ 不如方案 A 简洁。

### 方案 E · 内存 + 文件缓存（高性能场景 ⭐⭐）

**核心思想**：把注册表序列化到 `storage/cache/mattoid-store-registry.json`，类似 Laravel 的 `route:cache`。

**优势**：
- ✅ 减少每次 boot 时遍历 extend.php 的开销（仅在生产环境）。

**劣势**：
- ⚠ 增加 `php flarum mattoid:store:cache` 与 `cache:clear` 复杂度；
- ⚠ 商品插件升级后必须手动 clear cache；
- ⚠ 失去方案 A 的"实时生效"优势；
- 仅在大流量场景下才值得引入。

---

## 4. 综合推荐

### 短期（保持 BC）：方案 B（reconcile 自愈）

**优势**：
- 几乎零破坏性，现有商品插件不需改一行代码；
- 立即解决 enable 失败、composer remove 不触发 onDisable 等核心痛点；
- 实现工作量 ≈ 2 小时。

### 中期（重构）：方案 A + C 组合

**步骤**：
1. 把 `StoreExtend::$goodList` 等静态数组**改造为容器服务**（方案 C 思路），保持静态 API `StoreExtend::getValidate(...)` 兼容；
2. `ListGoodsController` 改为**运行时聚合**（方案 A 思路），废弃 `StoreGoodsModel` 直接查询；
3. 保留 `store_goods` 表为只读缓存（reconcile 写入），管理端列表读容器；
4. 下一个 major 版本（2.0）完全废弃 `store_goods` 表。

### 长期：完全无 DB（方案 A）

- `store_goods` 表 drop；
- `store.class_name` / `store.pop_up` 字段去除，运行时从 metadata 获取；
- 这是最干净的状态，但属于破坏性变更，建议 2.0 引入。

---

## 5. 推荐实施步骤（PR 拆分）

### PR-X1：增加 `reconcile()` 自愈机制（短期保 BC）

修改文件：
- `src/Extend/StoreExtend.php`：
  - 新增 `public static function reconcile(string $timezone): void`；
  - `onEnable` 包 try/catch，失败仅记日志；
  - `onDisable` 同上。
- `src/Controller/ListGoodsController.php`：开头调 `StoreExtend::reconcile()`；
- 增加 cache（1-5 分钟）避免每次调用都同步：
  ```php
  $this->cache->remember('mattoid-store.reconcile', 60, function () {
      StoreExtend::reconcile($this->storeTimezone);
      return true;
  });
  ```

**收益**：立即解决"enable 失败永远恢复不了"的故障；普通用户无感知，但运维负担大幅下降。

### PR-X2：popUp 改为运行时读取（中期）

修改文件：
- `src/Controller/PostStoreController.php` / `PutStoreController.php`：
  ```diff
  - $goods = StoreGoodsModel::query()->where('code', $code)->first();
  + $goods = StoreExtend::getStoreGoods($code);
    if (!$goods) {
        throw new ValidationException(['message' => '...']);
    }
  - $params['pop_up'] = $goods->pop_up;
  - $params['class_name'] = $goods->class_name;
  + $params['pop_up'] = json_encode($goods->popUp);    // 运行时获取最新 schema
  + $params['class_name'] = $goods->className;
  ```
- `src/Controller/BuyGoodsController.php`：购买流程读取 popUp 时也从运行时取（如果有此需求）。

**收益**：popUp 升级即时生效；不再需要 disable+enable 来刷新 schema。

### PR-X3：容器化注册（中期，方案 C）

修改文件：
- `src/Extend/StoreExtend.php`：
  - `extend(Container $c, Extension $e)` 中向 `'mattoid-store.registry'` 注册；
  - 静态 getter 改为 `resolve('mattoid-store.registry')[$key][$type] ?? null`；
  - 保留旧 API 作为别名；
- `extend.php` 中预注册容器服务：
  ```php
  function (Container $container) {
      $container->singleton('mattoid-store.registry', fn() => []);
  },
  ```
- `Mattoid\Store\Goods\{Validate,After,Invalid,Enable}` 改为支持构造器注入（移除 static 修饰）。

**收益**：
- 商品插件可构造器注入服务；
- singleton 缓存避免重复 new；
- 测试友好。

### PR-X4：废弃 `store_goods` 表（长期，2.0 版本）

修改文件：
- 删除 `src/Model/StoreGoodsModel.php`；
- 删除 `migrations/2024_06_25_000000_create_store_goods_table.php`（同时增加 drop migration）；
- `src/Extend/StoreExtend.php`：完全移除 onEnable 中的 DB 操作；
- `src/Controller/ListGoodsController.php`：完全从 `StoreExtend::all()` 聚合；
- 文档：在 README 中标注 2.0 破坏性变更。

---

## 6. 边界情况处理

### 6.1 已购但商品类型卸载

场景：用户购买了"邀请码"，论坛主下次升级时移除了 invite 插件。

**当前**：`store_cart.code='invite'`，`StoreExtend::getEnable('invite')` 返回 null。
- 用户在购物车点"使用"：`UseGoodsController` 抛 `cart-no-use`；
- 自动续费时 `StoreExtend::getInvalid('invite')` 返回 null → catch 后 `cart.status=2`。

**改进**：在 `StoreSerializer` / `CartSerializer` 中暴露 `is_orphaned` 字段，前端 UI 区分"插件已卸载"的购物车条目，避免用户误操作。

### 6.2 商品插件升级中 popUp 字段重命名

场景：v1.0 用 `'value' => 'count'`，v1.1 改为 `'value' => 'quantity'`。

**当前**：DB 中已 saved 的 `store.pop_up` 仍是旧 schema；新 store 行用新 schema。**前端表单字段不一致**。

**改进**：
- 推荐**避免重命名**；
- 必要时商品插件提供 migration（如：`UPDATE store SET pop_up = REPLACE(pop_up, ...) WHERE code='invite'`）。

### 6.3 多语言 popUp label

当前 `$popUp` 中 `label` 通常是 i18n key（如 `'invite.label.count'`），运行时由前端 translator 解析。
- 静态数组中只存 i18n key → OK；
- DB 存的也是 i18n key → OK；
- **但**如果商品插件 i18n 文件升级、key 改名，DB 里旧 popUp 还引用旧 key → 前端显示 `[missing translation]`。

**改进**：方案 A（运行时读）下，重启即生效；方案 B reconcile 也会刷新。

### 6.4 popUp options 动态生成

某些场景下 popUp 的 `options` 需要运行时计算（如可选 group 列表来自数据库）。

**当前**：`$popUp` 是 class 属性，只能写死。**不能**支持动态。

**改进**：把 `Goods::popUp` 从属性改为方法：
```php
abstract class Goods {
    abstract public function getPopUp(): array;    // 替代 public $popUp
}
```
方案 A 下每次 `getStoreGoods($code)->getPopUp()` 实时计算，完美支持动态。

---

## 7. 完整对比矩阵

| 维度 | 当前 | 方案 A 运行时 | 方案 B reconcile | 方案 C 容器 |
|------|------|---------------|------------------|-------------|
| enable 失败影响 | ❌ 永久瘫痪 | ✅ 无影响 | ✅ 自愈 | ✅ 无影响 |
| popUp 升级生效 | ❌ 需 disable+enable | ✅ 即时 | ✅ reconcile 后 | ✅ 即时 |
| composer remove | ❌ 留幽灵 | ✅ 即消失 | ✅ reconcile 清理 | ✅ 即消失 |
| 命名冲突检测 | ❌ silent | ✅ 可加 throw | ✅ 可加 throw | ✅ 可加 throw |
| DI 支持 | ❌ | ⚠ 需配合 resolve | ⚠ | ✅ 完整 |
| 性能 | ✅ DB 读快 | ✅ 内存读最快 | ⚠ 多一次 reconcile | ✅ 容器查找快 |
| 向后兼容 | – | ❌ 破坏 store_goods | ✅ 完全 BC | ⚠ 静态 API 需别名 |
| 实施工作量 | – | 中（4-6h） | 低（2h） | 中（6-8h） |
| 推荐场景 | – | 长期理想 | 短期 hotfix | 中期重构 |

---

## 8. 风险提示

### 8.1 第三方插件兼容性

- 方案 A、C 都需要修改 `StoreExtend::getValidate()` 等公开 API 的语义（但保留签名）；
- **保留至少 1 个 minor 版本的过渡期**，发布 deprecation notice；
- 提供 `docs/upgrade-guide.md` 详细迁移说明。

### 8.2 已存在的 store_goods 数据

- 方案 A：drop 表前需提供 export 脚本（如导出为 JSON 备份）；
- 方案 B：reconcile 时若发现"DB 有但 in-memory 没有"，**默认仅删除并打日志**，确保管理员能从日志看到。

### 8.3 高并发 reconcile

- 方案 B 中 reconcile 在并发请求时可能多次执行；
- 已用 cache 1 分钟兜底，但仍可能短时间内有 cache miss；
- 可加分布式锁（参见 04-security-audit.md V-09 cache 锁分析）：
  ```php
  if ($this->cache->add('mattoid-store.reconcile.lock', 1, 30)) {
      try { StoreExtend::reconcile(); } finally {
          $this->cache->delete('mattoid-store.reconcile.lock');
      }
  }
  ```

### 8.4 测试覆盖

每个 PR 必须覆盖：
- 商品插件 enable → 列表显示；
- 商品插件 disable → 列表隐藏；
- enable 时 DB 失败 → 后续仍能恢复；
- 两个插件用同样 code → 抛 conflict 或 last-write wins（取决于设计决策）；
- popUp 升级 → 立即生效。

---

## 9. 建议的最终架构（2.0）

```
┌────────────────────────────────────────────────────┐
│  StoreExtend (ExtenderInterface)                   │
│  ↓ extend(Container, Extension)                    │
│  注册到容器：'mattoid-store.registry'              │
└────────────────────────────────────────────────────┘
                       ↓
┌────────────────────────────────────────────────────┐
│  Container: 'mattoid-store.registry' (singleton)   │
│  [ 'invite' => GoodsMetadata(                       │
│       goods: InviteGoods,                           │
│       validate: InviteValidate,                     │
│       after: InviteAfter,                           │
│       invalid: InviteInvalid,                       │
│       enable: InviteEnable,                         │
│   ),                                                │
│    'check-in' => GoodsMetadata(...),                │
│  ]                                                  │
└────────────────────────────────────────────────────┘
        ↓                          ↓
ListGoodsController          BuyGoodsController
（运行时聚合返回前端）        （运行时取 Validate/After）

数据库表：
- store (无 class_name / pop_up 列，仅保留 code + 业务字段)
- store_cart
- store_goods_icon
（drop: store_goods）
```

**核心约束**：
- 商品类型元数据**唯一权威源**是商品插件自身的代码；
- 数据库只存"已上架商品"和"用户购买记录"；
- 商品插件卸载 → 商品列表即时消失；
- popUp 升级 → 即时生效。

---

## 10. 立即可做的最小改动（30 分钟）

如果暂不重构，只想**消除最痛的故障**（enable 失败永久瘫痪），可以只改 3 处：

```diff
// src/Extend/StoreExtend.php

public function onEnable(Container $container, Extension $extension)
{
    $goods = StoreExtend::getStoreGoods($this->key);
    if ($goods) {
+       try {
+           // 用 updateOrInsert 替代 insert，避免 unique 冲突
+           StoreGoodsModel::query()->updateOrInsert(
+               ['code' => $this->key],
+               [
                    'name' => $goods->name,
                    'class_name' => $goods->className,
                    'pop_up' => json_encode($goods->popUp),
                    'created_at' => Carbon::now()->tz($this->storeTimezone)
+               ]
+           );
+       } catch (\Throwable $e) {
+           // 失败不抛错，避免阻塞 Flarum enable 流程
+           resolve(\Psr\Log\LoggerInterface::class)
+               ->error('StoreExtend onEnable failed', ['code' => $this->key, 'exception' => $e]);
+       }
-       StoreGoodsModel::query()->insert([
-           'code' => $this->key,
-           'name' => $goods->name,
-           'class_name' => $goods->className,
-           'pop_up' => json_encode($goods->popUp),
-           'created_at' => Carbon::now()->tz($this->storeTimezone)
-       ]);
    }
}
```

**收益**：
- `updateOrInsert` 解决 unique 冲突问题；
- try/catch 防止 enable 流程被中断；
- 至少不会让 Flarum 显示"已启用"但实际不能用。

**不足**：
- 仍然不能修复"composer remove 后留幽灵"问题；
- popUp 升级仍然不会自动同步；
- **不是终极解法**，但 30 分钟内可让线上系统少出运维事故。

---

> **回到索引**：[00-index.md](./00-index.md)
> **下一步建议**：先做"立即可做的最小改动"（第 10 节），然后排期 PR-X1 reconcile 自愈方案 B。
