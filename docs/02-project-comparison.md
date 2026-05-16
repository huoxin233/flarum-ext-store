# 02 - 当前项目 vs 对照项目 差异分析

> **当前项目（A）**：`/Users/liufei/work/mattoid/php/flarum-ext/flarum-ext-store/`
> **对照项目（B）**：`/Users/liufei/work/mattoid/php/flarum-ext-store/`
>
> 两者均为 `mattoid/flarum-ext-store`，命名空间、composer.json、extend.php、migrations、locale 完全相同。差异主要集中在 PHP 业务逻辑（资金处理）与前端 TypeScript 类型定义。

---

## 0. 差异总览

| 维度 | A（当前） | B（对照） | 影响 |
|------|----------|----------|------|
| 资金处理 | 内联 `$user->money -= price` + 乐观锁 | `AntoineFr\Money\Service\BalanceManager::applyBalanceChange()` | 安全/事务 |
| MoneyHistory 集成 | `class_exists` 软依赖 | 移除（统一由 BalanceManager 写入） | 简化 |
| TS 类型 | 大量 `any`、缺 `StoreApiResource` | 新增 `js/src/@types/shims.d.ts`，强类型 | 可维护性 |
| 异常捕获 | `catch (Mockery\Exception $e)` ⚠ | `catch (\Throwable $e)` | 健壮性 |
| 调度任务空集合判断 | `if (!$invalidList)` ⚠ Collection 不可这样判空 | `if ($invalidList->isEmpty())` | Bug 修复 |
| storeId 提取 | `array_column(json_decode($invalidList,true), 'store_id')` | `$invalidList->pluck('store_id')->unique()->toArray()` | 性能/可读性 |
| store 缺失防御 | 直接 `$store = $storeMap[$cart->store_id]` ⚠ 可能 undefined | 增加 `?? null` + skip | 健壮性 |
| cache 释放 | 手动 `cache->delete($key)` 易遗漏 | `try/finally` 包裹 | 健壮性 |
| 类型签名 | `String $key`（PHP 8 大小写敏感弃用警告） | `string $key` | 兼容性 |
| 代码风格 | 紧凑（`!$x`、缺空格） | Prettier 规范化（`! $x`、空格分隔） | 一致性 |
| package.json | 缺 `format` / `analyze` / `build-typings` 脚本 | 全套类型构建脚本 | 工具链 |
| bundlewatch | 未声明 devDependency | `bundlewatch ^0.4.2` | 监控 |

---

## 1. 资金处理（最关键差异）

### A · 内联模式（`src/Controller/BuyGoodsController.php`）

```php
$user = User::query()->where('id', $actor->id)->first();
$money = $user->money;
$balance = $money - $price;
if ($balance < 0) {
    throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.user-balance-low')]);
}

// 加入购物车（先扣库存）
$carts = $this->events->dispatch(new StoreCartAddEvent($user, $store, $price));
$cart = array_shift($carts);

$user->money = $balance;
$user->where('money', $money);      // ← 乐观锁
if (!$user->save()) {
    $this->events->dispatch(new StoreStockAddEvent($store));    // 回滚库存
    throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.user-balance-low')]);
}

// 通知 MoneyHistory（可选）
if (class_exists('Mattoid\MoneyHistory\Event\MoneyHistoryEvent')) {
    $this->events->dispatch(new \Mattoid\MoneyHistory\Event\MoneyHistoryEvent(
        $user, -$price, 'STOREBUYGOODS', $this->translator->trans(...), ''
    ));
}
```

### B · BalanceManager 模式

```php
$applied = $this->balances->applyBalanceChange(
    $user,
    -$price,
    'STORE_BUY_GOODS',
    $this->translator->trans("mattoid-store.forum.buy-goods", ['title' => $store->title]),
    ['item_title' => $store->title],
    $user,
    preventOverdraft: true            // PHP 8 命名参数
);
if (!$applied) {
    throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.user-balance-low')]);
}
$user->save();

$carts = $this->events->dispatch(new StoreCartAddEvent($user, $store, $price));
$cart = array_shift($carts);
```

### 差异点分析

| # | A 模式 | B 模式 | 评价 |
|---|---------|---------|------|
| 1 | 先扣库存再扣余额，失败再回滚库存 | 先扣余额再扣库存，失败前置阻断 | B 更安全（避免无效库存扣减） |
| 2 | 乐观锁通过 `$user->where('money',$old)->save()` 仍可能并发问题 | `BalanceManager` 内置 `preventOverdraft` 行级锁/数据库原生事务 | B 更可靠 |
| 3 | MoneyHistory 通过事件总线异步通知 | BalanceManager 内部直接写资金流水（同一事务） | B 数据一致性更强 |
| 4 | 失败时仅事件回滚库存，未回滚资金（因尚未扣） | 失败时 BalanceManager 不变更，库存事件不触发 | B 更简洁 |
| 5 | 异常捕获 `catch (Mockery\Exception $e)` | `catch (\Throwable $e)` | A 是错误的 catch（Mockery 是测试库，绝不会被实际抛出） |

### 同步影响范围

资金处理涉及的变更文件（A→B 需要同步）：

- `src/Controller/BuyGoodsController.php`
- `src/Console/Command/GoodsInvalidCommand.php`
- `src/Listeners/StoreBuyFailListeners.php`

### 隐藏问题

A 的 `composer.json` **没有声明** `antoinefr/money` 依赖；B 的 controller `use AntoineFr\Money\Service\BalanceManager;` **同样没有声明**——理论上两个项目都依赖宿主 Flarum 站点安装了 `antoinefr/money` 扩展。但 **A 还保留了 class_exists 优雅降级**，B 则直接硬依赖，**如果宿主未装 antoinefr/money，B 直接 500**。

> 修复建议：将 `antoinefr/money` 升级为 `composer.json: require` 显式依赖，并保留软依赖兜底。

---

## 2. TypeScript 类型系统

### B 新增 `js/src/@types/shims.d.ts`

为前端注入了两个核心类型：

```ts
interface StoreItemData {
  id: number;
  code: string;
  title: string;
  price: number;
  payAmt?: number;
  stock: number;
  type: 'permanent' | 'limit';
  status: 0 | 1 | 2 | 9;
  outtime?: string;
  hide?: boolean | number;
  popUp?: string;
  className?: string;
  // ... 其余字段
}
interface StoreApiResource {
  id: string;
  type: string;
  attributes: StoreItemData;
}
```

并把 `package.json` 的 `scripts` 扩充为完整类型工具链：
```json
"format": "prettier --write src",
"format-check": "prettier --check src",
"analyze": "cross-env ANALYZER=true yarn run build",
"clean-typings": "...",
"build-typings": "yarn run clean-typings && tsc && yarn run post-build-typings",
"check-typings": "tsc --noEmit --emitDeclarationOnly false",
"check-typings-coverage": "typescript-coverage-report"
```

A 仍使用 `private storeData: any = {}`、`private params: any = {}`、`oninit(vnode)`（无类型）等"动态语言"风格。

### 同步影响范围

A→B 需要新增 `js/src/@types/` 并修改 ≈10 个 .tsx 文件：

- `js/src/forum/components/component/{CartItem,StoreItem}.tsx`
- `js/src/forum/components/modal/StoreBox.tsx`
- `js/src/forum/components/pages/{StorePage,MyCartPage}.tsx`
- `js/src/forum/index.ts`
- `js/src/admin/index.tsx`
- `js/src/admin/components/*.tsx`

---

## 3. 调度任务的 Bug 修复

### A · `GoodsInvalidCommand.php`

```php
$invalidList = StoreCartModel::query()->where(...)->get();
if (!$invalidList) {                                              // ⚠ Collection 永远不是 false
    return;
}
$storeIdList = array_column(json_decode($invalidList, true), 'store_id');   // ⚠ 把 Collection JSON 化再 decode
$storeList = StoreModel::query()->whereIn('id', $storeIdList)->get();

foreach ($invalidList as $cart) {
    $store = $storeMap[$cart->store_id];                          // ⚠ undefined key 警告
    ...
}
```

### B 修复版

```php
$invalidList = StoreCartModel::query()->where(...)->get();
if ($invalidList->isEmpty()) {                                    // ✓ 正确判空
    return;
}
$storeIdList = $invalidList->pluck('store_id')->unique()->toArray();  // ✓ Collection 原生 API
$storeList = StoreModel::query()->whereIn('id', $storeIdList)->get();

foreach ($invalidList as $cart) {
    $store = $storeMap[$cart->store_id] ?? null;
    if (!$store) {
        $this->error("[{$cart->store_id}] Store not found, skipping cart {$cart->id}");
        continue;
    }
    ...
}
```

### `autoDeduction` 的 lock 释放

A 把 `$this->cache->delete($key)` 放在方法末尾，**异常路径会泄漏锁 5 秒**（虽然 `cache->add` 自带 TTL 兜底）。
B 用 `try { ... } finally { $this->cache->delete($key); }`，**严谨释放**。

---

## 4. PHP 8 兼容性

A 中：
```php
public static function getStoreGoods(String $key)    // String 大写
public static function getValidate(String $key)
// ...
```

PHP 8.0+ 中 `String` / `Int` / `Bool` 等大写类型名只是别名解析，**实际类型仍是 string**，但有些静态分析工具会报警；PHP 内置文档建议**全小写**：

B 中已统一改为 `string $key`。

---

## 5. 异常处理差异

| 文件 | A | B |
|------|---|---|
| `BuyGoodsController.php` | `use Mockery\Exception;` + `catch (Exception $e)` ⚠ Mockery 是测试库 | `catch (\Throwable $e)` |
| `BuyGoodsController.php` | `try { if(!$after->after()) ... } catch(...)` 两个 throw 重复 | `$afterSuccess = false; try { ... } catch; if(!$afterSuccess) ...` 单点 throw |
| `GoodsInvalidCommand.php` | `catch (\Exception)` 不能捕获 `\Error` | （未变更）仍只 catch Exception，但 store 缺失时有显式 skip 防御 |

> A 中 `use Mockery\Exception` 是**严重 bug**：购买流程中 `$after->after()` 如果抛出 `\Exception`（非 Mockery），catch 将**漏接**，最终触发 ValidationException 后还是会回滚（throw → 500），但日志会丢失原始异常信息。

---

## 6. 前端 UI 增强

### A 已包含的"加载动画"

近期 commit `e6f15d3 / 5e1a443 增加加载动画` 已在 `StorePage.tsx` 中加入：
```tsx
{this.loading && (
  <div class="DiscussionList">
    <div class="DiscussionList-loadMore">
      <div aria-label="loading…" role="status" data-size="medium" class="LoadingIndicator-container ...">
        <div aria-hidden="true" class="LoadingIndicator"></div>
      </div>
    </div>
  </div>
)}
```

B 中此段保留，但**额外**对 `parseResults` 做了空 payload 防御 + `Array.push.apply` 改为 `Array.prototype.push(...spread)`。

### `index.ts` 中 `extend(IndexPage.prototype, 'navItems')` 的返回值

A 中 `return false`（Flarum 的 extend 不接受返回 false 来短路，会被忽略）；
B 中改为 `return;`（语义正确）。
A 同时缺少 `this.user` null 防御：`href: app.route('myCartPage', { username: this.user.slug() })` 在 user 不存在时会 NPE。
B 中 `const user = this.user; if (!user) return;` 添加防御。

---

## 7. 代码格式 / 风格

B 已统一通过 Prettier 重新格式化整个项目：

- `!$x` → `! $x`（PSR-12 风格）
- `function() {` → `function () {`（function 关键字后空格）
- `import {X} from "y"` → `import { X } from 'y'`
- 末尾空格、尾随逗号统一
- 多行 JSX 缩进规整化

> 这是非语义变更，但 PR 合并时会产生大量 noise。建议在同步 BalanceManager 改造前先**单独跑一遍 Prettier**，把格式化 PR 与逻辑变更 PR 分离，便于 review。

---

## 8. 完全相同的文件

以下文件在 A、B 两边**完全一致**：

- `composer.json`
- `extend.php`
- `migrations/*`（7 个文件）
- `locale/en.yml` / `locale/zh-Hans.yml`
- `README.md` / `LICENSE.md`
- `js/webpack.config.js`、`js/tsconfig.json`
- 大部分前端文件（差异仅是 prettier 与类型注解）

---

## 9. A 独有 vs B 独有

| 项 | 仅 A 有 | 仅 B 有 |
|---|---------|---------|
| `.idea/` IDE 配置 | ✓ | – |
| `node_modules/` | ✓（已生成） | – |
| `js/dist/284.js` | ✓（chunk 分割产物） | – |
| `.DS_Store` 散落多处 | ✓ ⚠ | – |
| `js/src/@types/shims.d.ts` | – | ✓ |
| `package.json` 扩展脚本 | – | ✓ |
| `bundlewatch` devDep | – | ✓ |

---

## 10. 建议合并策略

将 A 推进到 B 的水平，推荐分 **3 个 PR**：

### PR-1：仅做格式化（Prettier）
- 跑 `yarn run format` 或同步 B 的所有 prettier 风格变更；
- **零逻辑变更**，便于后续 PR 的 review；
- 建议同时清理 `.DS_Store`、加 `.gitignore`（如 `.DS_Store`、`.idea/`、`node_modules/`）。

### PR-2：BalanceManager 资金处理重构
- composer.json 显式 require `antoinefr/money`；
- `BuyGoodsController` / `GoodsInvalidCommand` / `StoreBuyFailListeners` 三个文件改为注入 `BalanceManager`；
- 移除 `MoneyHistoryEvent` 软依赖代码（统一由 BalanceManager 内部写流水）；
- 修复 `catch (Mockery\Exception)` → `catch (\Throwable)`；
- 修复 `if (!$invalidList)` → `isEmpty()`；
- 修复 `array_column(json_decode(...))` → `pluck()`；
- 修复缺锁释放（用 try/finally）；
- 修复 store 缺失时的防御。

### PR-3：TypeScript 强类型化
- 新增 `js/src/@types/shims.d.ts`；
- 把所有 `any` 替换为类型；
- 同步 `package.json` 的 scripts（format / build-typings / analyze）；
- 修复 `forum/index.ts` 的 `return false` → `return;` + `this.user` null 防御。

### 可选 PR-4：保留并扩展 MoneyHistory 软依赖
若要保留 `Mattoid\MoneyHistory` 兼容（A 现有用户基础），可以同时支持两套路径：
```php
if (resolve(Container::class)->bound(BalanceManager::class)) {
    // BalanceManager 路径
} else {
    // 原 $user->money 路径
}
```

> 但不建议这么做——双路径维护成本极高。

---

> **下一步**：参见 [03-optimization-roadmap.md](./03-optimization-roadmap.md) 了解后续完整优化路径。
