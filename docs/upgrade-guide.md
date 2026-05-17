# Plugin Upgrade Guide (Legacy → Solution A)

This guide is for maintainers of product plugins written against `mattoid/flarum-ext-store` **before the Solution A refactor**. New plugins built with the current API can ignore this document; see the [Plugin Development Guide](plugin-development.md) instead.

> Language: **English** · [中文](upgrade-guide_cn.md)
>
> See also: [Main README](../README.md) · [Plugin Development Guide](plugin-development.md)

---

## What changed in Solution A

| Topic | Before | After (Solution A) |
|---|---|---|
| Product metadata source | `store_goods` table, populated by `StoreExtend::onEnable()` | `StoreExtend::$registry` — an in-memory static array, filled at boot |
| `store_goods` table | Required, written on enable / deleted on disable | **Dropped by migration** (`2026_05_16_000000_solution_a_refactor`) |
| `store.class_name` / `store.pop_up` columns | Snapshotted from `store_goods` at admin "Add product" time | **Dropped**; injected at serialize time from the runtime registry |
| `Goods` property visibility | `protected $name / $popUp / $className` (read via reflection) | `public $name / $popUp / $className` (direct read, with reflection fallback) |
| Soft delete on `store` | Hard delete | `SoftDeletes` with `deleted_at` |
| Disabling a product plugin | `store_goods` row removed; the store row left in a half-broken state | All matching `store` rows are set to `status = 0`; cart history preserved; API marks the row `orphaned = true` |

A more thorough rationale is in [`docs/06-plugin-registration-redesign.md`](06-plugin-registration-redesign.md).

---

## Migration checklist

For each product plugin you maintain:

- [ ] Bump the `mattoid/flarum-ext-store` constraint in `composer.json`.
- [ ] Change `Goods` property visibility from `protected` to `public`.
- [ ] Remove any `onEnable` / `onDisable` code that wrote to `store_goods` directly.
- [ ] Drop `pop_up` / `class_name` snapshot reads — these are now provided by the storefront serializer.
- [ ] (Optional) Tighten exception handling in `After::after()` — Solution A wraps the whole buy flow in a `DB::transaction`, so throwing is enough; you no longer need to dispatch `StoreBuyFailEvent` manually.
- [ ] Run the storefront with the new product type, then **uninstall** the plugin and confirm the row turns `orphaned = true` (instead of leaving stale `store_goods` data).
- [ ] Update your README / docs to reference the new flow.

The detailed before/after diffs follow.

---

## 1. `Goods` properties: `protected → public`

**Before**

```php
class InviteGoods extends Goods
{
    protected $name = 'mattoid-store-invite.forum.title';
    protected $popUp = [/* ... */];
    protected $className = 'store-buy Modal--small';
}
```

**After**

```php
class InviteGoods extends Goods
{
    public $name = 'mattoid-store-invite.forum.title';
    public $popUp = [/* ... */];
    public $className = 'store-buy Modal--small';
}
```

Solution A still falls back to reflection if it encounters `protected` properties, so old plugins keep working. But the supported (and faster) path is `public`. The base class `Mattoid\Store\Goods\Goods` has been changed accordingly.

---

## 2. Remove `onEnable` writes to `store_goods`

If you wrote a custom `LifecycleInterface` implementation that touched `store_goods`, delete it.

**Before**

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

**After**

```php
// Nothing. StoreExtend handles the lifecycle for you:
//   - onEnable: best-effort cleanup of any legacy store_goods rows (idempotent)
//   - onDisable: set status=0 on every matching store row
return [
    (new StoreExtend('invite'))
        ->addStoreGoods(InviteGoods::class)
        ->addValidate(InviteValidate::class)
        ->addAfter(InviteAfter::class)
        ->addInvalid(InviteInvalid::class)
        ->addEnable(InviteEnable::class),
];
```

> The previous `onEnable → DB insert` path was the single biggest failure mode in the old design: a transient SQL error would silently leave the plugin "enabled by Flarum" but with no `store_goods` row, making the product type permanently unusable until the admin manually `DELETE FROM store_goods` and re-enabled. Solution A removes that failure mode entirely.

---

## 3. Drop `pop_up` / `class_name` snapshot reads

If your plugin read `$store->pop_up` or `$store->class_name` directly, stop doing that — those columns no longer exist on the `store` table after migration `2026_05_16_000000_solution_a_refactor`.

The storefront API (`StoreSerializer`) now reads `popUp` / `className` from the runtime registry on every request, so:

- Editing `Goods::$popUp` in code takes effect immediately after `cache:clear` (no more re-enable cycle).
- Already-purchased cart rows still resolve via `StoreCartModel::store()->withTrashed()` — they get the **current** popup definition, which is what you usually want for `Enable::enable()`.

If you genuinely need the historical popup definition for a past purchase, you must store it yourself (e.g. in your plugin's own table) at purchase time.

---

## 4. (Optional) Simplify `After::after()` error handling

**Before** — manually dispatching `StoreBuyFailEvent` on partial failure:

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

**After** — let the surrounding transaction do its job:

```php
public static function after(User $user, StoreModel $store, $params)
{
    self::doStuff($user, $store, $params);
    return true;
}
```

`BuyGoodsController` wraps the whole call in `DB::transaction(...)`. Any `Throwable` (or `return false`) rolls back the entire purchase — including the cart row creation, balance change, and stock decrement — and dispatches the refund / stock-rollback chain for you.

Only fall back to manual `StoreBuyFailEvent` dispatch if your business logic spawns **post-commit** side effects that need compensation; that is a rare case.

---

## 5. Migration data flow & rollback

The data migration shipped by the main extension (`2026_05_16_000000_solution_a_refactor`) does the following:

1. `DROP TABLE store_goods` (if present).
2. `ALTER TABLE store DROP COLUMN pop_up, DROP COLUMN class_name`.
3. `ALTER TABLE store ADD COLUMN deleted_at TIMESTAMP NULL` (`SoftDeletes`).
4. `ALTER TABLE store MODIFY COLUMN discount_price DECIMAL(10, 2) NOT NULL DEFAULT 0`.
5. Add composite indexes for hot queries.

The migration's `down` method recreates the dropped table, columns, and reverts the type changes — but if you have important data in `store_goods` that you want to preserve, take a manual `mysqldump` first:

```sh
mysqldump --no-create-info <db> store_goods > store_goods.backup.sql
```

Then run:

```sh
php flarum migrate
php flarum cache:clear
```

> **Heads up for self-hosters running multiple product plugins**: enable the main store extension first, run migrations, and *then* enable / update each product plugin. The product plugins no longer touch `store_goods`, but their first boot after upgrade is when their `extend.php` files re-populate the runtime registry.

---

## 6. Quick smoke test after upgrading

1. `php flarum migrate && php flarum cache:clear`.
2. Open **Admin → Store → Add**: every product type you used to have should still be in the dropdown.
3. Existing storefront entries should still display correctly with the updated popup.
4. Try a purchase end-to-end and confirm balance + cart behavior.
5. Temporarily `php flarum extension:disable <your-product-plugin>` and reload the storefront: the row should turn into an orphan (greyed-out button), and the existing cart entries for that user should display `orphaned = true` in the API response.
6. Re-enable the plugin — the row goes back to normal.

If anything in step 2 is missing, check that your `extend.php` still constructs `new StoreExtend(<code>)` for that product type. Solution A relies entirely on that runtime call.
