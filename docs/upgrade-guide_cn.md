# 商品插件升级指南（旧版 → Solution A）

本文面向 **Solution A 重构之前** 已有的商品插件维护者。如果你是从零开始写新插件，请直接看 [商品插件开发指南](plugin-development_cn.md)。

> 语言：**中文** · [English](upgrade-guide.md)
>
> 另见：[主 README](readme_cn.md) · [商品插件开发指南](plugin-development_cn.md)

---

## Solution A 改了什么

| 维度 | 旧版 | Solution A |
|---|---|---|
| 商品元数据来源 | `store_goods` 表，由 `StoreExtend::onEnable()` 写入 | `StoreExtend::$registry` 内存静态数组，启动时填入 |
| `store_goods` 表 | 必需，启用时写入 / 禁用时删除 | **被迁移删除**（`2026_05_16_000000_solution_a_refactor`） |
| `store.class_name` / `store.pop_up` 列 | 在后台「添加商品」时从 `store_goods` 快照而来 | **被删除**；由 serializer 运行时从注册表注入 |
| `Goods` 属性可见性 | `protected $name / $popUp / $className`（用反射读） | `public $name / $popUp / $className`（直接读，反射兜底） |
| `store` 表软删除 | 直接 delete | `SoftDeletes`，新增 `deleted_at` 列 |
| 禁用商品插件 | `store_goods` 行被删，`store` 行残留 | 所有相关 `store` 行被设为 `status = 0`；购物车保留；API 标记 `orphaned = true` |

详细演化背景见 [`docs/06-plugin-registration-redesign.md`](06-plugin-registration-redesign.md)。

---

## 升级检查清单

对你维护的每一个商品插件：

- [ ] 在 `composer.json` 里调整 `mattoid/flarum-ext-store` 的版本约束。
- [ ] 把 `Goods` 子类的属性可见性从 `protected` 改成 `public`。
- [ ] 删除自定义的 `onEnable` / `onDisable` 里向 `store_goods` 写表的代码。
- [ ] 不再读 `$store->pop_up` / `$store->class_name`——这些列已被删除，序列化器会替你注入最新值。
- [ ] （可选）简化 `After::after()` 里的异常处理——Solution A 已用 `DB::transaction` 包裹整条购买链路，抛异常 / `return false` 即可，无需手动派发 `StoreBuyFailEvent`。
- [ ] 安装新版后跑一遍商店：购买 → 卸载插件，确认商品行变成 `orphaned = true`（不再残留 `store_goods` 脏数据）。
- [ ] 更新自己 README / 文档里的描述。

下面是逐项差异。

---

## 1. `Goods` 属性可见性：`protected → public`

**改前**

```php
class InviteGoods extends Goods
{
    protected $name = 'mattoid-store-invite.forum.title';
    protected $popUp = [/* ... */];
    protected $className = 'store-buy Modal--small';
}
```

**改后**

```php
class InviteGoods extends Goods
{
    public $name = 'mattoid-store-invite.forum.title';
    public $popUp = [/* ... */];
    public $className = 'store-buy Modal--small';
}
```

Solution A 遇到 `protected` 仍会用反射兜底，老插件不改也能跑。但 `public` 是推荐路径，更快；基类 `Mattoid\Store\Goods\Goods` 已经全部改成 `public`。

---

## 2. 删除 `onEnable` 向 `store_goods` 写表的代码

如果你写过自定义 `LifecycleInterface` 实现来操作 `store_goods`，全部删掉。

**改前**

```php
public function onEnable(Container $container, Extension $extension)
{
    $goods = new InviteGoods();
    StoreGoodsModel::query()->insert([
        'code'       => 'invite',
        'name'       => $goods->name,
        'class_name' => $goods->className,
        'pop_up'     => json_encode($goods->popUp),
        'created_at' => Carbon::now(),
    ]);
}

public function onDisable(Container $container, Extension $extension)
{
    StoreGoodsModel::query()->where('code', 'invite')->delete();
}
```

**改后**

```php
// 什么都不写。StoreExtend 替你处理 lifecycle：
//   - onEnable：尽力而为地清理可能残留的旧 store_goods 行（幂等）
//   - onDisable：把所有匹配 code 的 store 行设为 status=0
return [
    (new StoreExtend('invite'))
        ->addStoreGoods(InviteGoods::class)
        ->addValidate(InviteValidate::class)
        ->addAfter(InviteAfter::class)
        ->addInvalid(InviteInvalid::class)
        ->addEnable(InviteEnable::class),
];
```

> 旧的 `onEnable → DB insert` 路径是老设计最大的失效来源：一次瞬时 SQL 错误就会让 Flarum 认为插件「已启用」，但 `store_goods` 表里没记录，于是商品类型彻底不可用，必须管理员手工 `DELETE FROM store_goods` 后重新启用。Solution A 直接消除了这条失败路径。

---

## 3. 不要再读 `pop_up` / `class_name` 快照

如果你的插件里有 `$store->pop_up` 或 `$store->class_name`，去掉——这两列在迁移 `2026_05_16_000000_solution_a_refactor` 之后已经不存在了。

新版 `StoreSerializer` 在每次序列化时都从运行时注册表读 `popUp / className`：

- 改 `Goods::$popUp`，`cache:clear` 即生效（不需要禁用→启用）。
- 历史购物车通过 `StoreCartModel::store()->withTrashed()` 仍能解析 store 快照，并拿到 **最新** 的 popup 定义——这通常正是 `Enable::enable()` 需要的。

如果确实需要保留某次购买时的历史 popup 定义，应当自己存（写到你插件自有的表里），不能再依赖 `store` 表快照。

---

## 4. （可选）简化 `After::after()` 的错误处理

**改前** —— 手动派发 `StoreBuyFailEvent` 做补偿：

```php
public static function after(User $user, StoreModel $store, $params)
{
    try {
        $this->doStuff($user, $store, $params);
        return true;
    } catch (\Throwable $e) {
        resolve('events')->dispatch(new StoreBuyFailEvent($user, $store, $cart, $params));
        return false;
    }
}
```

**改后** —— 交给外层事务回滚：

```php
public static function after(User $user, StoreModel $store, $params)
{
    self::doStuff($user, $store, $params);
    return true;
}
```

`BuyGoodsController` 用 `DB::transaction(...)` 包裹整次调用。任何 `Throwable`（或 `return false`）都会把整笔购买回滚——包括购物车记录创建、扣款、库存扣减——并自动触发退款 + 库存回滚链。

只有当你的业务有 **事务提交之后** 才出问题的副作用、需要补偿时，才需要手动派发 `StoreBuyFailEvent`，这种情况很少。

---

## 5. 数据迁移流程 & 回滚

主插件附带的迁移 `2026_05_16_000000_solution_a_refactor` 做了以下事情：

1. `DROP TABLE store_goods`（如果存在）。
2. `ALTER TABLE store DROP COLUMN pop_up, DROP COLUMN class_name`。
3. `ALTER TABLE store ADD COLUMN deleted_at TIMESTAMP NULL`（`SoftDeletes`）。
4. `ALTER TABLE store MODIFY COLUMN discount_price DECIMAL(10, 2) NOT NULL DEFAULT 0`。
5. 给热点查询加复合索引。

迁移的 `down` 会重建被删的表 / 列、恢复字段类型。如果 `store_goods` 里有你重视的数据，先 `mysqldump` 备份一份：

```sh
mysqldump --no-create-info <db> store_goods > store_goods.backup.sql
```

然后执行：

```sh
php flarum migrate
php flarum cache:clear
```

> **多商品插件部署建议**：先升级 + 启用主插件，跑完迁移，再依次启用 / 升级每个商品插件。商品插件本身不再操作 `store_goods`，但首次启动时会通过 `extend.php` 重新把元数据填回运行时注册表。

---

## 6. 升级后的快速验证

1. `php flarum migrate && php flarum cache:clear`。
2. 打开 **后台 → 商店 → 添加**：之前用过的商品类型应当还在下拉框里。
3. 前台已有的商品行能正常展示，弹窗用的是新版定义。
4. 走一遍完整的购买流程，确认扣款 + 购物车行为正常。
5. 临时 `php flarum extension:disable <你的商品插件>`，刷新前台：对应商品行应当变成孤儿（按钮置灰）、API 中 `orphaned = true`。
6. 重新启用插件——商品行恢复正常。

如果第 2 步少了某个商品类型，检查你的 `extend.php` 是否还在调用 `new StoreExtend(<code>)`。Solution A 完全依赖这个运行时调用。
