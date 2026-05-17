# Product Plugin Development Guide

This guide explains how to write a **product plugin** on top of `mattoid/flarum-ext-store` (Solution A — runtime registry).

> Language: **English** · [中文](plugin-development_cn.md)
>
> See also: [Main README](../README.md) · [Upgrade guide for legacy plugins](upgrade-guide.md)

---

## Table of Contents

- [Concept](#concept)
- [Skeleton](#skeleton)
- [Step 1 — Composer setup](#step-1--composer-setup)
- [Step 2 — Define the product metadata (`Goods`)](#step-2--define-the-product-metadata-goods)
- [Step 3 — Pre-purchase validation (`Validate`)](#step-3--pre-purchase-validation-validate)
- [Step 4 — Post-purchase business (`After`)](#step-4--post-purchase-business-after)
- [Step 5 — Expiry / failure handler (`Invalid`)](#step-5--expiry--failure-handler-invalid)
- [Step 6 — Cart toggle (`Enable`)](#step-6--cart-toggle-enable)
- [Step 7 — Register in `extend.php`](#step-7--register-in-extendphp)
- [Events](#events)
- [popUp form syntax](#popup-form-syntax)
- [Lifecycle & registry semantics](#lifecycle--registry-semantics)
- [Testing tips](#testing-tips)
- [FAQ](#faq)

---

## Concept

A product plugin contributes a **product type** (identified by a unique string code, e.g. `invite`) to the store. Under Solution A, that contribution is **purely in-memory**: when your plugin boots, it puts five class references into `StoreExtend::$registry[code]`:

| Slot | Base class | When it runs |
|---|---|---|
| `goods` | `Mattoid\Store\Goods\Goods` | When admin lists product types, and when the storefront API serializes a `Store` row |
| `validate` | `Mattoid\Store\Goods\Validate` | Before the user is charged |
| `after` | `Mattoid\Store\Goods\After` | After successful charge, inside the buy transaction |
| `invalid` | `Mattoid\Store\Goods\Invalid` | When a `limit`-type cart entry expires and auto-deduction failed (or wasn't enabled) |
| `enable` | `Mattoid\Store\Goods\Enable` | When the user toggles "use / cancel" on a cart item |

You can register only the slots you need — every slot is optional except `goods`.

## Skeleton

A minimal product plugin layout:

```
my-product/
├── composer.json
├── extend.php
├── src/
│   └── Goods/
│       ├── MyGoods.php
│       ├── MyValidate.php   (optional)
│       ├── MyAfter.php      (optional)
│       ├── MyInvalid.php    (optional)
│       └── MyEnable.php     (optional)
└── locale/
    └── en.yml
```

## Step 1 — Composer setup

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

Declare `mattoid/flarum-ext-store` as a hard dependency so Flarum's extension manager surfaces the requirement to the admin.

## Step 2 — Define the product metadata (`Goods`)

```php
<?php

namespace Vendor\MyProduct\Goods;

use Mattoid\Store\Goods\Goods;

class MyGoods extends Goods
{
    /** i18n key shown in the admin "Add product" dropdown. */
    public $name = 'vendor-my-product.forum.title';

    /**
     * Popup form rendered to the user when they click "Buy".
     * If empty, the buy button calls the API directly with no extra params.
     * See "popUp form syntax" below for the supported fields.
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

    /** CSS classes added to the buy-popup modal root. Defaults to `store-buy Modal--small`. */
    public $className = 'store-buy Modal--small';
}
```

> **Important:** properties must be `public`. Solution A reads them directly (it still falls back to reflection for backwards compatibility, but `public` is the supported path).

## Step 3 — Pre-purchase validation (`Validate`)

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
        // Reject obviously bad input. Throwing a ValidationException surfaces
        // its message directly to the user; returning false uses a generic message.
        if (empty($params['email']) || ! filter_var($params['email'], FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['message' => 'Invalid email']);
        }

        return true;
    }
}
```

`Validate::validate` runs **before** the user is charged. Returning `false` (or throwing) aborts the purchase with no money taken and no cart row.

## Step 4 — Post-purchase business (`After`)

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
        // Your business logic, e.g. provision an invite code, grant a badge, etc.
        //
        // This method runs INSIDE the DB transaction of BuyGoodsController.
        // Use Eloquent normally — anything you throw will roll the transaction
        // back, refund the user, and restore stock automatically.

        // Returning false has the same effect as throwing, but uses a generic
        // "buy failed" message. Prefer `throw new ValidationException(...)`
        // when you have a useful message.
        return true;
    }
}
```

### When to dispatch `StoreBuyFailEvent` from `After`

Almost never. If you throw or return `false`, the core handles refund + stock rollback for you. Dispatch `StoreBuyFailEvent` manually only when **the failure happens after the transaction has already committed** and you need to compensate (rare).

## Step 5 — Expiry / failure handler (`Invalid`)

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
        // Called by the `mattoid:store:check:date` scheduled command when a
        // `type=limit` cart entry has expired AND either:
        //   - auto_deduction is off, OR
        //   - auto_deduction is on but the renewal charge failed.
        //
        // Typical action: revoke whatever the product granted (mute, role, etc.).

        return true;
    }
}
```

## Step 6 — Cart toggle (`Enable`)

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
        // Called when the user clicks "use" or "cancel" on a cart item.
        // $cart->enable has already been flipped by the controller; this
        // hook should apply the side effect (e.g. enable a frame, mute a user).
        //
        // Return false to reject the toggle (the cart row is not saved).

        return true;
    }
}
```

## Step 7 — Register in `extend.php`

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

    (new StoreExtend('my-product'))             // unique product code
        ->addStoreGoods(MyGoods::class)
        ->addValidate(MyValidate::class)
        ->addAfter(MyAfter::class)
        ->addInvalid(MyInvalid::class)
        ->addEnable(MyEnable::class),
];
```

The string passed to `new StoreExtend(...)` is the **product code**. It must be globally unique across all installed product plugins; the same code is also what admin selects when creating a store row.

That's it — `php flarum cache:clear` and your product type shows up in **Admin → Store → Add**.

## Events

Beyond the registered hooks, you can listen to the following events with `Extend\Event()->listen(...)`:

| Event | Payload | Notes |
|---|---|---|
| `Mattoid\Store\Event\StoreBuyEvent` | `user`, `store`, `cart`, `params` | "Purchase succeeded." Fires after the transaction commits. Safe place for non-critical side effects (emails, webhooks, audit logs). |
| `Mattoid\Store\Event\StoreInvalidEvent` | `store`, `cart`, `status` | Fires once per expired cart entry. `status` reflects whether auto-deduction succeeded. |
| `Mattoid\Store\Event\StoreBuyFailEvent` | `user`, `store`, `cart`, `params` | Internal — already listened to by core. You can listen too, but **do not dispatch** unless you understand the rollback path. |
| `Mattoid\Store\Event\StoreCartAddEvent` / `StoreCartEditEvent` / `StoreStockAddEvent` / `StoreStockSubEvent` | — | Internal plumbing. Listening to these is supported but uncommon. |

> **Rule of thumb**: business logic that must run atomically with the purchase belongs in `After`. Anything else (notifications, analytics) belongs in a `StoreBuyEvent` listener.

## popUp form syntax

`Goods::$popUp` is an array of rows describing the buy form. Each row has these keys:

| Key | Required | Description |
|---|---|---|
| `label` | yes | i18n key for the row label |
| `prop` | yes | Element type. Supported: `input`, `textarea`, `switch`, `select` |
| `type` | when `prop=input` | HTML `type` attribute (`text`, `email`, `number`, …) |
| `value` | yes | Field key sent to the API in `params[...]` |
| `helpText` | no | i18n key for the help text shown under the field |

Example with select:

```php
public $popUp = [
    [
        'label' => 'vendor-my-product.forum.duration',
        'prop'  => 'select',
        'value' => 'duration',
        'options' => [
            ['label' => '1 month', 'value' => 30],
            ['label' => '1 year',  'value' => 365],
        ],
    ],
];
```

The values appear as keys on the `params` argument passed to `Validate::validate`, `After::after` and `Enable::enable`.

## Lifecycle & registry semantics

- **First boot** of your plugin: `StoreExtend::__construct` creates an empty placeholder slot in `$registry`, then each `add*` call fills in a class reference. Nothing is written to disk.
- **`onEnable`** (Flarum lifecycle): no-op under Solution A. There is a best-effort cleanup of any old `store_goods` row that may exist from a pre–Solution A install.
- **`onDisable`** (Flarum lifecycle): sets `status = 0` on every `store` row whose `code` matches your registered key. Existing carts keep their data; the product simply becomes unbuyable.
- **Uninstall** (composer remove): your plugin's code disappears, so `StoreExtend::has($code)` returns `false`. The store rows and cart rows remain in the DB, and the API marks them with `orphaned = true`. The frontend uses this to disable the buy / use buttons gracefully.

## Testing tips

- **Local smoke test**: `composer require vendor/flarum-ext-my-product`, then `php flarum extension:enable vendor-my-product`, then `php flarum cache:clear`. Open **Admin → Store → Add** and confirm your product appears.
- **Repro the buy flow**: `POST /api/store/buy/goods` with `{ "id": <store_id>, ...popup_fields }`. The full event chain (validate → charge → cart add → after → buy event) will run; use `storage/logs/flarum.log` for diagnostics.
- **Repro the scheduler**: `php flarum mattoid:store:check:date`. This iterates expired `limit` carts and calls your `Invalid::invalid()` (or auto-deduction on success).
- **Smoke-test orphan handling**: temporarily `php flarum extension:disable vendor-my-product`; the storefront row should turn `orphaned = true` and the buy button should grey out.

## FAQ

**Q: Do I have to register all five hooks?**
No. Only `addStoreGoods` is required in practice (otherwise admins can't add the product). `Validate`, `After`, `Invalid`, `Enable` are all optional — leave them off if you don't need them.

**Q: How do I migrate from the old `Goods::$name = protected` pattern?**
Change the visibility to `public`. The new code reads properties directly via `ReflectionObject::getProperty` with `setAccessible`, so both `public` and `protected` still work, but `public` is the supported path going forward and is faster.

**Q: My old plugin wrote to `store_goods` in `onEnable`. Do I still need that?**
No, remove that code entirely. See the [Upgrade Guide](upgrade-guide.md).

**Q: Where do I put the storefront frontend JS for my product type?**
Same as any other Flarum extension — `js/src/forum/index.{js,ts}`. The base store extension already loads the storefront page, so your JS just needs to register UI tweaks (custom button text, post-purchase modals, etc.).

**Q: Can two plugins share a product code?**
No. The product code is the registry key. Pick something namespaced (e.g. `mycompany-feature`) to be safe.
