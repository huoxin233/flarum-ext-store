# 商品插件开发指南

本文介绍如何基于 `mattoid/flarum-ext-store`（Solution A · 运行时注册表）开发自己的 **商品插件**。

> 语言：**中文** · [English](plugin-development.md)
>
> 另见：[主 README](readme_cn.md) · [旧插件升级指南](upgrade-guide_cn.md)

---

## 目录

- [核心概念](#核心概念)
- [项目骨架](#项目骨架)
- [步骤 1 — composer 配置](#步骤-1--composer-配置)
- [步骤 2 — 定义商品元数据（`Goods`）](#步骤-2--定义商品元数据goods)
- [步骤 3 — 购买前置校验（`Validate`）](#步骤-3--购买前置校验validate)
- [步骤 4 — 购买后业务（`After`）](#步骤-4--购买后业务after)
- [步骤 5 — 失效 / 续费失败处理（`Invalid`）](#步骤-5--失效--续费失败处理invalid)
- [步骤 6 — 购物车切换（`Enable`）](#步骤-6--购物车切换enable)
- [步骤 7 — 在 `extend.php` 中注册](#步骤-7--在-extendphp-中注册)
- [事件](#事件)
- [popUp 表单语法](#popup-表单语法)
- [生命周期 & 注册表语义](#生命周期--注册表语义)
- [调试建议](#调试建议)
- [FAQ](#faq)

---

## 核心概念

一个商品插件向商店框架贡献一种 **商品类型**（用唯一字符串 code 标识，例如 `invite`）。在 Solution A 下，这个贡献 **完全发生在内存里**：插件启动时把至多五个类的全限定名塞进 `StoreExtend::$registry[code]`：

| 槽位 | 基类 | 触发时机 |
|---|---|---|
| `goods` | `Mattoid\Store\Goods\Goods` | 后台列商品类型时；前台 API 序列化 `Store` 行时 |
| `validate` | `Mattoid\Store\Goods\Validate` | 扣费前 |
| `after` | `Mattoid\Store\Goods\After` | 扣费成功后，仍处于事务内 |
| `invalid` | `Mattoid\Store\Goods\Invalid` | 限时类 (`limit`) 购物车过期、且未续费（或续费失败）时 |
| `enable` | `Mattoid\Store\Goods\Enable` | 用户在购物车点「使用 / 取消使用」时 |

只需要注册你真正用到的槽位。除 `goods` 外其他都可选。

## 项目骨架

最小商品插件目录结构：

```
my-product/
├── composer.json
├── extend.php
├── src/
│   └── Goods/
│       ├── MyGoods.php
│       ├── MyValidate.php   （可选）
│       ├── MyAfter.php      （可选）
│       ├── MyInvalid.php    （可选）
│       └── MyEnable.php     （可选）
└── locale/
    └── zh-Hans.yml
```

## 步骤 1 — composer 配置

```json
{
    "name": "vendor/flarum-ext-my-product",
    "type": "flarum-extension",
    "require": {
        "flarum/core": "^1.2.0",
        "mattoid/flarum-ext-store": "*"
    },
    "autoload": {
        "psr-4": { "Vendor\\MyProduct\\": "src/" }
    },
    "extra": {
        "flarum-extension": {
            "title": "Store / My Product"
        }
    }
}
```

把 `mattoid/flarum-ext-store` 写成硬依赖，让 Flarum 扩展管理器能正确提示安装顺序。

## 步骤 2 — 定义商品元数据（`Goods`）

```php
<?php

namespace Vendor\MyProduct\Goods;

use Mattoid\Store\Goods\Goods;

class MyGoods extends Goods
{
    /** 后台「添加商品」下拉框里显示的 i18n key */
    public $name = 'vendor-my-product.forum.title';

    /**
     * 用户点击「购买」时弹出的表单。
     * 留空数组则不弹弹窗，直接调用 API。
     * 字段含义见下方「popUp 表单语法」。
     */
    public $popUp = [
        [
            'label'    => 'vendor-my-product.forum.email',
            'prop'     => 'input',
            'type'     => 'email',
            'value'    => 'email',
            'helpText' => 'vendor-my-product.forum.email-help',
        ],
    ];

    /** 弹窗 modal 根节点的 CSS class，默认 `store-buy Modal--small` */
    public $className = 'store-buy Modal--small';
}
```

> **重要**：属性必须声明为 `public`。Solution A 直接读取属性（仍保留 reflection 兼容 `protected`，但 `public` 是推荐路径，性能更好）。

## 步骤 3 — 购买前置校验（`Validate`）

```php
<?php

namespace Vendor\MyProduct\Goods;

use Flarum\Foundation\ValidationException;
use Flarum\User\User;
use Mattoid\Store\Goods\Validate;
use Mattoid\Store\Model\StoreModel;

class MyValidate extends Validate
{
    public static function validate(User $user, StoreModel $store, $params)
    {
        // 校验失败有两种处理：
        //  1) 抛 ValidationException：消息会原样返回给用户；
        //  2) return false：使用通用的「校验失败」文案。
        if (empty($params['email']) || ! filter_var($params['email'], FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['message' => '邮箱格式不正确']);
        }

        return true;
    }
}
```

`Validate::validate` 在 **扣费之前** 执行。返回 `false` 或抛异常会终止购买，用户不会被扣款，也不会产生购物车记录。

## 步骤 4 — 购买后业务（`After`）

```php
<?php

namespace Vendor\MyProduct\Goods;

use Flarum\User\User;
use Mattoid\Store\Goods\After;
use Mattoid\Store\Model\StoreModel;

class MyAfter extends After
{
    public static function after(User $user, StoreModel $store, $params)
    {
        // 在这里写自有业务，比如下发邀请码、赋予徽章……
        //
        // 此方法运行在 BuyGoodsController 的 DB 事务内，所有 Eloquent
        // 操作都自动具备原子性；任何异常 / return false 会触发回滚：
        // 框架会自动退款 + 还原库存，无需你手动处理。
        //
        // 如果有错误消息想反馈给用户，优先抛 ValidationException。

        return true;
    }
}
```

### 什么时候需要手动派发 `StoreBuyFailEvent`？

几乎不需要。只要你抛异常或 `return false`，主插件会替你处理退款和库存回滚。仅当 **失败发生在事务提交之后**（事后补偿场景）才需要手动派发——这种情况很少见。

## 步骤 5 — 失效 / 续费失败处理（`Invalid`）

```php
<?php

namespace Vendor\MyProduct\Goods;

use Mattoid\Store\Goods\Invalid;
use Mattoid\Store\Model\StoreCartModel;
use Mattoid\Store\Model\StoreModel;

class MyInvalid extends Invalid
{
    public static function invalid(StoreModel $store, StoreCartModel $cart)
    {
        // 由 `mattoid:store:check:date` 调度任务触发：
        // 当 `type=limit` 的购物车到期、且满足以下任一条件时调用：
        //   - 商品没开启自动续费；
        //   - 开启了但本次续费扣款失败。
        //
        // 典型动作：撤销之前授予的能力（解除禁言、移除角色…）。

        return true;
    }
}
```

## 步骤 6 — 购物车切换（`Enable`）

```php
<?php

namespace Vendor\MyProduct\Goods;

use Flarum\User\User;
use Mattoid\Store\Goods\Enable;
use Mattoid\Store\Model\StoreCartModel;
use Mattoid\Store\Model\StoreModel;

class MyEnable extends Enable
{
    public static function enable(User $user, StoreModel $store, StoreCartModel $cart)
    {
        // 用户在购物车点击「使用 / 取消使用」时触发。
        // $cart->enable 已被 controller 翻转，本方法负责落实副作用
        // （例如套用头像框、禁言用户、激活权限……）。
        //
        // 返回 false 表示拒绝此次切换，cart 行不会被保存。

        return true;
    }
}
```

## 步骤 7 — 在 `extend.php` 中注册

```php
<?php

use Flarum\Extend;
use Mattoid\Store\Extend\StoreExtend;
use Vendor\MyProduct\Goods\MyAfter;
use Vendor\MyProduct\Goods\MyEnable;
use Vendor\MyProduct\Goods\MyGoods;
use Vendor\MyProduct\Goods\MyInvalid;
use Vendor\MyProduct\Goods\MyValidate;

return [
    new Extend\Locales(__DIR__.'/locale'),

    (new StoreExtend('my-product'))             // 商品唯一 code
        ->addStoreGoods(MyGoods::class)
        ->addValidate(MyValidate::class)
        ->addAfter(MyAfter::class)
        ->addInvalid(MyInvalid::class)
        ->addEnable(MyEnable::class),
];
```

`new StoreExtend(...)` 接收的字符串就是 **商品 code**：它在所有已安装的商品插件之间必须唯一，后台添加商品时下拉框选的也是它。

收工：`php flarum cache:clear` 后，你的商品类型会出现在 **后台 → 商店 → 添加**。

## 事件

除了注册槽位，你还可以用 `Extend\Event()->listen(...)` 监听以下事件：

| 事件 | 字段 | 说明 |
|---|---|---|
| `Mattoid\Store\Event\StoreBuyEvent` | `user`, `store`, `cart`, `params` | 「购买成功」。事务提交后触发，适合做非关键副作用（发邮件、调 webhook、写审计日志）。 |
| `Mattoid\Store\Event\StoreInvalidEvent` | `store`, `cart`, `status` | 每个过期购物车触发一次，`status` 表示自动续费是否成功。 |
| `Mattoid\Store\Event\StoreBuyFailEvent` | `user`, `store`, `cart`, `params` | 内部事件，主插件已自行监听用于退款 + 回滚库存。可以监听，但 **不要随意派发**，除非你完全理解回滚链。 |
| `StoreCartAddEvent` / `StoreCartEditEvent` / `StoreStockAddEvent` / `StoreStockSubEvent` | — | 内部管道事件，监听可行但极少用到。 |

> **经验法则**：必须与购买原子绑定的业务写在 `After` 里；其余（通知、统计、外部调用）放在 `StoreBuyEvent` 监听器里。

## popUp 表单语法

`Goods::$popUp` 是表单行的数组，每一行字段：

| Key | 必填 | 说明 |
|---|---|---|
| `label` | 是 | 行标签的 i18n key |
| `prop` | 是 | 元素类型，目前支持 `input` / `textarea` / `switch` / `select` |
| `type` | `prop=input` 时 | HTML `type` 属性（`text`/`email`/`number`…） |
| `value` | 是 | 字段在 API 中的 key，会出现在 `params[...]` |
| `helpText` | 否 | 帮助文本的 i18n key |

带 `select` 的例子：

```php
public $popUp = [
    [
        'label' => 'vendor-my-product.forum.duration',
        'prop'  => 'select',
        'value' => 'duration',
        'options' => [
            ['label' => '1 个月', 'value' => 30],
            ['label' => '1 年',  'value' => 365],
        ],
    ],
];
```

`value` 字段就是 `Validate::validate` / `After::after` / `Enable::enable` 中拿到的 `$params` 的 key。

## 生命周期 & 注册表语义

- **首次启动**：`StoreExtend::__construct` 在 `$registry` 里建立空占位，`add*` 链式调用依次填值。整个过程不落库。
- **`onEnable`**（Flarum lifecycle）：Solution A 下基本是 no-op，只 best-effort 清理可能残留的旧 `store_goods` 行（兼容旧版本升级用户）。
- **`onDisable`**（Flarum lifecycle）：把所有 `code` 等于你注册 key 的 `store` 行设为 `status = 0`，购物车数据保留，但商品变为不可购买。
- **彻底卸载**（composer remove）：你插件的代码不再加载，`StoreExtend::has($code)` 返回 `false`。`store` 行和购物车数据保留，API 返回里会带 `orphaned = true`，前端据此禁用购买 / 使用按钮。

## 调试建议

- **本地 smoke**：`composer require vendor/flarum-ext-my-product` → `php flarum extension:enable vendor-my-product` → `php flarum cache:clear`，打开 **后台 → 商店 → 添加**，确认商品出现在下拉框。
- **复现购买链路**：`POST /api/store/buy/goods`，body 形如 `{ "id": <store_id>, ...popup_fields }`。整条链路（validate → 扣费 → 加购物车 → after → BuyEvent）都会执行，日志见 `storage/logs/flarum.log`。
- **复现调度任务**：`php flarum mattoid:store:check:date`，会遍历过期的 limit 购物车并调用 `Invalid::invalid()`（或先尝试自动续费）。
- **验证孤儿处理**：临时 `php flarum extension:disable vendor-my-product`，前台对应商品应当 `orphaned = true`、购买按钮置灰。

## FAQ

**Q：五个钩子全都要注册吗？**
不需要。实际上只有 `addStoreGoods` 是必须的，否则后台连商品都加不进去。`Validate / After / Invalid / Enable` 都可选，不需要就别注册。

**Q：怎么把老插件的 `Goods::$name = protected` 改造？**
把可见性改成 `public` 就够了。新代码会用反射兜底读 `protected`，但 `public` 是推荐路径，性能也更好。

**Q：老插件在 `onEnable` 里写 `store_goods` 表，还要保留吗？**
不要。整段删掉。具体步骤见 [升级指南](upgrade-guide_cn.md)。

**Q：商品对应的前端逻辑放哪？**
跟普通 Flarum 扩展一样写在 `js/src/forum/index.{js,ts}` 即可。Store 框架已经承担了商店列表 / 购物车 UI，你的前端代码只需做差异化（按钮文案、购后弹窗等）。

**Q：两个插件能共用同一个商品 code 吗？**
不行。商品 code 就是注册表的 key，必须全局唯一。取一个带命名空间的（如 `mycompany-feature`）就稳妥了。
