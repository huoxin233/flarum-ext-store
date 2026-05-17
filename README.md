# Flarum-ext-store

[![GitHub license](https://img.shields.io/badge/license-MIT-blue.svg)](https://raw.githubusercontent.com/Mattoids/flarum-ext-store/master/LICENSE.md) [![Latest Stable Version](https://img.shields.io/packagist/v/mattoid/flarum-ext-store.svg)](https://packagist.org/packages/mattoid/flarum-ext-store) [![Total Downloads](https://img.shields.io/packagist/dt/mattoid/flarum-ext-store.svg)](https://packagist.org/packages/mattoid/flarum-ext-store)

A [Flarum](http://flarum.org) extension that provides a **points store framework**. This extension does not ship any product on its own — every product type (invitation code, check-in card, custom badge, …) is supplied at runtime by a third-party product plugin through the `StoreExtend` extender.

> Language: **English** · [中文](docs/readme_cn.md)

---

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Updating](#updating)
- [Architecture (Solution A)](#architecture-solution-a)
- [For Forum Administrators](#for-forum-administrators)
- [For Plugin Developers](#for-plugin-developers)
- [Permissions](#permissions)
- [Events Overview](#events-overview)
- [Known Product Plugins](#known-product-plugins)
- [Documentation](#documentation)
- [Links](#links)

---

## Features

- **Money integration** — payments and refunds are routed through `antoinefr/flarum-ext-money`'s `BalanceManager`, so every transaction automatically lands in the money history.
- **Atomic stock control** — decrement / rollback use a single SQL `UPDATE` and tolerate unlimited stock (`stock = -99`).
- **Transactional purchase flow** — the buy pipeline is wrapped in `DB::transaction`, with cache locks to prevent double-spend.
- **Soft-deleted products** — products use `SoftDeletes`, and historical carts still resolve via `withTrashed()`.
- **Auto-deduction (subscription)** — limited-time products can auto-renew on the `mattoid:store:check:date` schedule.
- **Runtime product registry (Solution A)** — product types live in PHP code, not in a database table; uninstalling a plugin instantly removes its product type and forces dependent stores into a safe `orphaned` state.

## Installation

```sh
composer require mattoid/flarum-ext-store:"*"
php flarum migrate
php flarum cache:clear
```

You also need to install [`antoinefr/flarum-ext-money`](https://github.com/antoinefr/flarum-ext-money) (declared as a hard dependency).

## Updating

```sh
composer update mattoid/flarum-ext-store:"*"
php flarum migrate
php flarum cache:clear
```

> **Upgrading from a pre–Solution A release?** Your existing product plugins likely register through the old `onEnable → store_goods` table. See the [Plugin Upgrade Guide](docs/upgrade-guide.md) — the changes are small but required.

## Architecture (Solution A)

Since the Solution A refactor:

- The `store_goods` table no longer exists. All product metadata (display name, popup form, dialog class) lives in `Mattoid\Store\Extend\StoreExtend::$registry` (an in-memory static map) and is filled in by each product plugin's `extend.php`.
- The `store` table no longer carries `class_name` / `pop_up` columns — they are injected at serialize time from the runtime registry.
- The `store` table has gained `deleted_at` (`SoftDeletes`) so that admins can hide a product without breaking historical purchase records.

The big benefits of this design:

| Concern | Before | After (Solution A) |
|---|---|---|
| Plugin removal | Stale metadata left in `store_goods` | Disappears instantly; orphaned stores get `orphaned = true` in the API |
| Popup / class upgrades | Required `disable → enable` cycle | Take effect immediately on `cache:clear` |
| First-install failure mode | `onEnable` write to DB could leave the type permanently unusable | No DB write needed; idempotent reboot recovers |

A deeper write-up of why we ended up here is in [`docs/06-plugin-registration-redesign.md`](docs/06-plugin-registration-redesign.md).

## For Forum Administrators

Once installed and enabled:

1. Go to **Admin → Extensions → Mattoid Store** and grant **View store** / **Manage store** permissions to the appropriate groups.
2. Install any number of product plugins (see [Known Product Plugins](#known-product-plugins)).
3. Go to **Admin → Store**, click **Add**, pick a registered product type, fill in price / stock / discount / expiry, save.
4. Optionally enable the scheduler so subscription-style products auto-renew:

   ```cron
   * * * * * cd /path/to/forum && php flarum schedule:run >> /dev/null 2>&1
   ```

> If a product plugin is uninstalled, its existing rows in `store` are kept but flagged `orphaned` in the API (the storefront button is disabled). Re-installing the plugin restores them.

## For Plugin Developers

A product plugin registers itself in its own `extend.php`:

```php
use Mattoid\Store\Extend\StoreExtend;

return [
    // ...other extenders
    (new StoreExtend('my-product-code'))           // unique key — must not collide
        ->addStoreGoods(MyGoods::class)            // product metadata
        ->addValidate(MyValidate::class)           // pre-purchase validation
        ->addAfter(MyAfter::class)                 // post-purchase business
        ->addInvalid(MyInvalid::class)             // expiry / failure handler
        ->addEnable(MyEnable::class),              // toggle "use / cancel" in cart
];
```

Each hook class extends one of the abstract base classes under `Mattoid\Store\Goods`. The full reference (signatures, lifecycle, examples, FAQ) is in:

➡️ **[Product Plugin Development Guide](docs/plugin-development.md)**

If you maintain a plugin written against the pre–Solution A API, read:

➡️ **[Plugin Upgrade Guide](docs/upgrade-guide.md)**

## Permissions

| Permission | What it grants |
|---|---|
| `mattoid-store.group-view` | View the storefront, buy products, toggle cart items |
| `mattoid-store.group-moderate` | Manage products in the admin panel, upload icons |

The `BasicUserSerializer` is extended with a `canStoreView` attribute so the frontend can hide the entry point cleanly.

## Events Overview

All events fire **outside** the transactional boundary unless noted. They are intended as notification hooks — for transactional, atomic business logic register your handler through `StoreExtend` instead.

| Event | Listened by | Use case |
|---|---|---|
| `StoreBuyEvent` | Your plugin | "purchase succeeded" notification |
| `StoreBuyFailEvent` | Core (refunds + stock rollback) | Dispatch from your `After::after()` if you need to abort post-purchase |
| `StoreCartAddEvent` | Core | Create a cart record and atomically decrement stock |
| `StoreCartEditEvent` | Core | Update cart status; auto-restore stock when `status > 1` |
| `StoreStockAddEvent` / `StoreStockSubEvent` | Core | Manual stock rollback / decrement |
| `StoreInvalidEvent` | Your plugin | Fired by the scheduler after a `limit`-type cart entry expires |

Detailed contracts (payload, dispatch order, idempotency notes) are in the [Plugin Development Guide](docs/plugin-development.md#events).

## Known Product Plugins

- [Invitation Code (Audit Version)](https://github.com/Mattoids/flarum-ext-store-invite)
- [Check-in Card](https://github.com/Mattoids/flarum-ext-store-check-in)
- [Auto Check-in Card](https://github.com/Mattoids/flarum-ext-store-auto-check-in)

If you publish a new product plugin, feel free to open a PR adding it to the list.

## Documentation

| Topic | English | 中文 |
|---|---|---|
| Main README | [README.md](README.md) | [docs/readme_cn.md](docs/readme_cn.md) |
| Product plugin development | [docs/plugin-development.md](docs/plugin-development.md) | [docs/plugin-development_cn.md](docs/plugin-development_cn.md) |
| Plugin upgrade (legacy → Solution A) | [docs/upgrade-guide.md](docs/upgrade-guide.md) | [docs/upgrade-guide_cn.md](docs/upgrade-guide_cn.md) |
| Internal design notes (Solution A rationale) | [docs/06-plugin-registration-redesign.md](docs/06-plugin-registration-redesign.md) | — |
| Security audit & roadmap (historical) | [docs/04-security-audit.md](docs/04-security-audit.md) · [docs/03-optimization-roadmap.md](docs/03-optimization-roadmap.md) | — |

## Links

- [Packagist](https://packagist.org/packages/mattoid/flarum-ext-store)
- [GitHub](https://github.com/mattoids/flarum-ext-store)
- [Discuss](https://discuss.flarum.org/d/34793)
