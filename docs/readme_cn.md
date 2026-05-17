# Flarum-ext-store（积分商店框架）

[![GitHub license](https://img.shields.io/badge/license-MIT-blue.svg)](https://raw.githubusercontent.com/Mattoids/flarum-ext-store/master/LICENSE.md) [![Latest Stable Version](https://img.shields.io/packagist/v/mattoid/flarum-ext-store.svg)](https://packagist.org/packages/mattoid/flarum-ext-store) [![Total Downloads](https://img.shields.io/packagist/dt/mattoid/flarum-ext-store.svg)](https://packagist.org/packages/mattoid/flarum-ext-store)

一个 [Flarum](http://flarum.org) 论坛的 **积分商店框架**。本插件本身不提供任何商品类型，所有商品（邀请码、补签卡、自定义徽章……）都由第三方商品插件通过 `StoreExtend` 在运行时注册。

> 语言：**中文** · [English](../README.md)

---

## 目录

- [功能特性](#功能特性)
- [安装](#安装)
- [升级](#升级)
- [架构（Solution A）](#架构solution-a)
- [给论坛管理员](#给论坛管理员)
- [给插件开发者](#给插件开发者)
- [权限](#权限)
- [事件总览](#事件总览)
- [已有商品插件](#已有商品插件)
- [文档导航](#文档导航)
- [相关链接](#相关链接)

---

## 功能特性

- **资金统一处理**：扣费 / 退款统一走 `antoinefr/flarum-ext-money` 的 `BalanceManager`，自动落资金流水。
- **原子库存**：扣减 / 回滚均为单条 SQL `UPDATE`，并支持无限库存（`stock = -99`）。
- **事务化购买**：整条购买链路被 `DB::transaction` 包裹，并配合缓存锁防重入。
- **商品软删除**：`store` 表使用 `SoftDeletes`；历史购物车通过 `withTrashed()` 仍能解析快照。
- **自动续费**：限时商品可通过 `mattoid:store:check:date` 调度任务自动扣费续期。
- **运行时注册表（Solution A）**：商品类型完全由 PHP 代码注册，无中间数据库表；卸载插件即时下架，对应商品被标记为 `orphaned`。

## 安装

```sh
composer require mattoid/flarum-ext-store:"*"
php flarum migrate
php flarum cache:clear
```

依赖：必须同时安装 [`antoinefr/flarum-ext-money`](https://github.com/antoinefr/flarum-ext-money)（已声明为硬依赖）。

## 升级

```sh
composer update mattoid/flarum-ext-store:"*"
php flarum migrate
php flarum cache:clear
```

> **从 Solution A 之前的版本升级？** 老的商品插件多半还在用 `onEnable → store_goods` 写表的方式注册商品，需要做少量调整。请查阅 [商品插件升级指南](upgrade-guide_cn.md)。

## 架构（Solution A）

Solution A 重构之后：

- 不再使用 `store_goods` 表。所有商品元信息（显示名、弹窗表单、对话框样式类）都存放在 `Mattoid\Store\Extend\StoreExtend::$registry`（一个内存里的静态注册表）中，由每个商品插件在 `extend.php` 里登记。
- `store` 表不再保存 `class_name` / `pop_up`，这两个字段由 serializer 在运行时从注册表注入。
- `store` 表新增 `deleted_at`（`SoftDeletes`）以支持安全下架，同时不破坏历史购物车。

设计收益：

| 场景 | 旧方案 | Solution A |
|---|---|---|
| 卸载商品插件 | `store_goods` 残留脏数据 | 即时消失，已购商品在 API 中被标记 `orphaned = true` |
| 升级弹窗 / 类名 | 必须 `禁用 → 启用` 触发 onEnable 写表 | 改完 `cache:clear` 即生效 |
| 首次安装失败 | `onEnable` 写表失败 → 商品类型永久不可用 | 不依赖 DB 写入，重新进程即可恢复 |

更详细的演化背景见 [`docs/06-plugin-registration-redesign.md`](06-plugin-registration-redesign.md)。

## 给论坛管理员

安装并启用插件之后：

1. **后台 → 权限**：为合适的用户组授予「查看商店」/「管理商店」权限。
2. 安装任意商品插件（见 [已有商品插件](#已有商品插件)）。
3. **后台 → 商店**：点「添加」，从下拉框中选一个已注册的商品类型，填好价格 / 库存 / 折扣 / 时长，保存。
4. （可选）开启计划任务，用于支撑订阅型商品的自动续费：

   ```cron
   * * * * * cd /path/to/forum && php flarum schedule:run >> /dev/null 2>&1
   ```

> 商品插件被卸载后，`store` 表中的记录会保留，但 API 返回里会带 `orphaned = true`，前端会禁用购买按钮。重新安装插件可恢复。

## 给插件开发者

商品插件在自己的 `extend.php` 中注册：

```php
use Mattoid\Store\Extend\StoreExtend;

return [
    // ...其他 extender
    (new StoreExtend('my-product-code'))           // 商品唯一 key，不可与其他插件冲突
        ->addStoreGoods(MyGoods::class)            // 商品元数据
        ->addValidate(MyValidate::class)           // 购买前置校验
        ->addAfter(MyAfter::class)                 // 购买后业务
        ->addInvalid(MyInvalid::class)             // 失效 / 扣费失败处理
        ->addEnable(MyEnable::class),              // 购物车内「使用 / 取消使用」开关
];
```

每个钩子类都继承自 `Mattoid\Store\Goods` 命名空间下的抽象基类。完整的签名、生命周期、最小示例和 FAQ 见：

➡️ **[商品插件开发指南](plugin-development_cn.md)**

如果你维护的是 Solution A 之前的老插件，请先阅读：

➡️ **[商品插件升级指南](upgrade-guide_cn.md)**

## 权限

| 权限标识 | 含义 |
|---|---|
| `mattoid-store.group-view` | 浏览商店、购买商品、操作购物车 |
| `mattoid-store.group-moderate` | 后台管理商品、上传图标 |

`BasicUserSerializer` 上挂了 `canStoreView` 属性，前端可据此干净地隐藏入口。

## 事件总览

除非特别注明，所有事件都在事务边界 **之外** 触发。它们是「通知」语义，不要在事件回调里写需要原子性的业务逻辑——那种逻辑请通过 `StoreExtend` 注册。

| 事件 | 监听方 | 用途 |
|---|---|---|
| `StoreBuyEvent` | 你的插件 | 购买成功通知 |
| `StoreBuyFailEvent` | 主插件（处理退款 + 库存回滚） | 自有业务失败时由你在 `After::after()` 中分发 |
| `StoreCartAddEvent` | 主插件 | 创建购物车记录并原子扣库存 |
| `StoreCartEditEvent` | 主插件 | 更新购物车状态；`status > 1` 时自动回滚库存 |
| `StoreStockAddEvent` / `StoreStockSubEvent` | 主插件 | 手动回滚 / 扣减库存 |
| `StoreInvalidEvent` | 你的插件 | `limit` 类型购物车过期后由调度任务触发 |

事件的字段、触发顺序、幂等注意事项见 [商品插件开发指南](plugin-development_cn.md#事件)。

## 已有商品插件

- [邀请码（审核版）](https://github.com/Mattoids/flarum-ext-store-invite)
- [补签卡](https://github.com/Mattoids/flarum-ext-store-check-in)
- [自动签到卡](https://github.com/Mattoids/flarum-ext-store-auto-check-in)

如果你写了新的商品插件，欢迎提 PR 把它加进列表。

## 文档导航

| 主题 | 英文 | 中文 |
|---|---|---|
| 主 README | [README.md](../README.md) | [docs/readme_cn.md](readme_cn.md) |
| 商品插件开发 | [docs/plugin-development.md](plugin-development.md) | [docs/plugin-development_cn.md](plugin-development_cn.md) |
| 旧插件升级到 Solution A | [docs/upgrade-guide.md](upgrade-guide.md) | [docs/upgrade-guide_cn.md](upgrade-guide_cn.md) |
| Solution A 设计纪要 | [docs/06-plugin-registration-redesign.md](06-plugin-registration-redesign.md) | — |
| 历史安全审计 / 优化路线 | [docs/04-security-audit.md](04-security-audit.md) · [docs/03-optimization-roadmap.md](03-optimization-roadmap.md) | — |

## 相关链接

- [Packagist](https://packagist.org/packages/mattoid/flarum-ext-store)
- [GitHub](https://github.com/mattoids/flarum-ext-store)
- [Flarum Discuss](https://discuss.flarum.org/d/34793)
