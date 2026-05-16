# 03 - 优化路径（Roadmap）

> 本文档将 [04-security-audit.md](./04-security-audit.md) 与 [05-improvement-opportunities.md](./05-improvement-opportunities.md) 中识别出的改进点编排为**可执行的迭代路线**。
>
> 按 **P0 紧急修复 → P1 安全/资金加固 → P2 架构演进 → P3 长期治理** 四个阶段推进。

---

## 推进原则

1. **小步快跑**：每个 PR 只做一类事，便于 review 与回滚。
2. **变更可逆**：所有破坏性变更（如 BalanceManager 迁移）提供 upgrade-guide。
3. **格式化与逻辑变更分离**：先 Prettier 一刀切，再做语义改动，否则 diff 全是噪声。
4. **测试先行**：每个 PR 必须新增对应单元测试（即使覆盖率初期低也要积累习惯）。
5. **不破坏第三方插件兼容**：5 个抽象基类 `Goods/Validate/After/Invalid/Enable` 的签名与 `StoreExtend` 的链式 API 暂不动。

---

## 路线总览

```
当前状态 ──┐
           ↓
        [阶段 0]  整理 / 格式化         （半天）
           ↓
        [阶段 1]  P0 紧急安全修复       （1 天）
           ↓
        [阶段 2]  P1 资金 / 类型升级    （3-5 天）
           ↓
        [阶段 3]  P2 架构演进           （1-2 周）
           ↓
        [阶段 4]  P3 长期治理与运维     （持续）
```

---

## 阶段 0 · 整理与格式化（0.5 天）

> 目标：把代码库整理到一致的状态，为后续大改铺路。

### PR-00-A：仓库整理
- [ ] `.gitignore` 增补：`.DS_Store`、`.idea/`、`node_modules/`、`js/dist/*.LICENSE.txt`；
- [ ] 删除已 track 的 `.DS_Store`、`tests/.DS_Store`、`src/.DS_Store`、`js/.DS_Store`；
- [ ] 删除已 track 的 `node_modules/`（若有）；
- [ ] 删除已 track 的 `js/dist/*.LICENSE.txt`、`284.js` 等 chunk 残留（建议改为发布时构建）。

### PR-00-B：Prettier 格式化
- [ ] 同步对照仓库 B 的格式化结果（`yarn run format`）；
- [ ] 整理 PHP 代码风格：`!$x` → `! $x`、`function() {` → `function () {`、`String $key` → `string $key`；
- [ ] **零逻辑变更**，仅样式；
- [ ] 加入 `pre-commit` 钩子 / GitHub Action 校验。

### PR-00-C：补充 `composer.json` 显式依赖
- [ ] `require`: `antoinefr/money: "^x.y"`（先做这一步，无论后续是否迁移 BalanceManager，至少把隐式依赖显式化）；
- [ ] `suggest`: `mattoid/flarum-ext-money-history` 软依赖说明。

**验收标准**：CI 通过，无逻辑差异。

---

## 阶段 1 · P0 紧急安全修复（1 天）

> 目标：消除可被直接利用的高危漏洞。详见 [04-security-audit.md](./04-security-audit.md)。

### PR-01-A：文件上传安全加固（修 V-01 + V-02）

修改文件：
- `src/Controller/StoreUpdateIconController.php`：增加 `group-moderate` 权限校验；
- `src/Upload/StoreValidator.php`：取消 `assertFileMimes` 注释；
- `src/Upload/StoreUploader.php`：增加扩展名白名单 + 强制小写。

新增测试：
- `tests/Integration/StoreUpdateIconControllerTest.php`：
  - 未登录用户调用 → 403
  - 普通用户调用 → 403
  - moderator 上传 .png → 200
  - moderator 上传 .php → 422
  - moderator 上传 6MB 文件 → 422

### PR-01-B：异常 catch + 权限 + NPE 三件套（修 V-05 + V-06 + V-08）

修改文件：
- `src/Controller/BuyGoodsController.php`：删除 `use Mockery\Exception;`，改为 `catch (\Throwable $e)` 并加 logger.error；
- `src/Controller/UseGoodsController.php`：
  - 权限改为 `group-view`；
  - `$cart` 加 null 校验；
- `src/Controller/PutStoreController.php`：`$goods` null 时无条件抛错。

新增测试：
- 用户调用 `/store/use/goods` 时 cart 不存在 → 422 `cart-not-found`
- 普通用户调用 `/store/use/goods` → 不再 403

### PR-01-C：admin 权限收紧（修 V-03）

修改文件：
- `js/src/admin/index.tsx`：`mattoid-store.group-moderate` 的 `allowGuest: false`；
- 重新构建 `js/dist/admin.js`。

### PR-01-D：兜底日志（不修 bug，但便于线上排错）

- 引入 `Psr\Log\LoggerInterface`，在 BuyGoodsController / GoodsInvalidCommand 的关键节点打 info/error 日志（详见 05-improvement-opportunities.md 4.1）。

**阶段 1 验收**：
- 安全漏洞 V-01/V-02/V-03/V-05/V-06/V-08 全部关闭；
- 线上立即可上 hotfix 版本（如 1.x.1）。

---

## 阶段 2 · P1 资金与类型升级（3-5 天）

> 目标：把当前项目与对照仓库 B 拉齐，引入 BalanceManager + TypeScript 强类型。

### PR-02-A：BalanceManager 资金重构（修 V-04）

修改文件：
- `src/Controller/BuyGoodsController.php`
- `src/Console/Command/GoodsInvalidCommand.php`
- `src/Listeners/StoreBuyFailListeners.php`

变更要点（参考 02-project-comparison.md）：
- 注入 `AntoineFr\Money\Service\BalanceManager`；
- 替换 `$user->money -= $price` + 乐观锁为 `$balances->applyBalanceChange(...preventOverdraft: true)`；
- 移除 `MoneyHistoryEvent` 软依赖代码（BalanceManager 自带流水）；
- 修复 `if (!$invalidList)` → `isEmpty()`；
- 修复 `array_column(json_decode(...))` → `pluck()`；
- 增加 `?? null + continue` 防御 storeMap 缺失；
- 锁释放改为 `try/finally`。

新增测试：
- 余额不足 → 422 user-balance-low
- 并发同店购买 → 第二次被 5 秒锁拦截
- After 抛 `\RuntimeException` → 触发 BuyFail，退款成功
- 自动续费成功 / 失败两条路径

⚠ **破坏性变更提示**：宿主需提前安装 `antoinefr/money`，否则升级后会 500。

### PR-02-B：DB 事务包裹购买流程（额外加固 V-04）

修改文件：
- `src/Controller/BuyGoodsController.php`
- `src/Console/Command/GoodsInvalidCommand.php`

```php
use Illuminate\Support\Facades\DB;

protected function data(...) {
    return DB::transaction(function () use ($request) {
        // 整个 data() 原逻辑放此处
    });
}
```

注意：
- 事件 dispatch 内的 listener 操作也会包含在事务中；
- 若 listener 主动 throw，事务自动回滚；
- 测试需覆盖"事务回滚时 BalanceManager 是否同步回滚"——通常 BalanceManager 也是基于 DB，会一起回滚。

### PR-02-C：TypeScript 强类型化

新增文件：
- `js/src/@types/shims.d.ts`：定义 `StoreItemData` / `StoreApiResource` / `StoreBoxField` 等。

修改文件：
- `js/src/forum/components/component/{CartItem,StoreItem}.tsx`
- `js/src/forum/components/modal/StoreBox.tsx`
- `js/src/forum/components/pages/{StorePage,MyCartPage}.tsx`
- `js/src/forum/index.ts`
- `js/src/admin/index.tsx`
- `js/src/admin/components/*.tsx`
- `js/package.json`：增加 `format / analyze / build-typings / check-typings` 等脚本，加 `bundlewatch` devDep。

### PR-02-D：mass-assignment 防护（修 V-07）

修改文件：
- `src/Model/StoreModel.php`：增加 `$fillable` + `$casts`；
- `src/Model/StoreCartModel.php`：同上 + `belongsTo` 关联；
- `src/Model/StoreGoodsModel.php`：同上；
- `src/Model/StoreGoodsIconModel.php`：同上；
- `src/Controller/PostStoreController.php` / `PutStoreController.php`：改用 `StoreModel::create($filtered)` / `$model->update($filtered)`，并对 `$parseBody` 做白名单过滤。

### PR-02-E：discount_limit_unit 白名单校验（修 V-13）

修改文件：
- `src/Controller/PostStoreController.php` / `PutStoreController.php`：
```php
$validUnits = ['days','hour','minute','second'];
if (isset($parseBody['discount_limit_unit']) && !in_array($parseBody['discount_limit_unit'], $validUnits, true)) {
    throw new ValidationException([...]);
}
```

**阶段 2 验收**：
- V-04/V-07/V-13 关闭；
- 与对照仓库 B 在资金/类型层面拉齐；
- 单元测试覆盖率 ≥ 30%。

---

## 阶段 3 · P2 架构演进（1-2 周）

> 目标：把项目从"散落 Controller"演进到"分层架构"。

### PR-03-A：Service 层抽取

新增目录：`src/Service/`

新增文件：
- `src/Service/BuyGoodsService.php`：从 BuyGoodsController 抽取业务逻辑；
- `src/Service/CartService.php`：从 StoreCartAddListeners + StoreCartEditListeners 抽取；
- `src/Service/StockService.php`：从 StoreStockSubListeners + StoreStockAddListeners 抽取；
- `src/Service/AutoDeductionService.php`：从 GoodsInvalidCommand 抽取。

修改文件：
- 4 个 Controller：仅做参数解析、权限、调用 service；
- 5 个 Listener：保留事件订阅入口，调用 service；
- `GoodsInvalidCommand`：调用 `AutoDeductionService`。

**注意**：保持事件总线对外接口（`StoreBuyEvent / StoreInvalidEvent`）不变，仅重构内部实现。

### PR-03-B：StoreExtend DI 化

修改文件：
- `src/Extend/StoreExtend.php`：把 `new $class` 改为 `resolve($class)`，让子类支持构造器注入；
- 同步添加单元测试覆盖容器注册路径。

### PR-03-C：内部事件 vs 外部事件拆分

新增目录：`src/Internal/Event/`

把以下事件迁移为内部事件（不承诺 BC）：
- `StoreCartAddEvent`
- `StoreCartEditEvent`
- `StoreStockSubEvent`
- `StoreStockAddEvent`
- `StoreBuyFailEvent`

保留为外部事件（承诺 BC）：
- `StoreBuyEvent`
- `StoreInvalidEvent`

`Mattoid\Store\Event\*` 别名指向 `Mattoid\Store\Internal\Event\*`，保持 1.x 向后兼容；2.0 移除别名。

### PR-03-D：数据库索引补充

新增 migration：`2026_xx_xx_add_indexes.php`

```php
Schema::table('store_cart', function (Blueprint $t) {
    $t->index(['user_id','store_id','status'], 'store_cart_dup');
    $t->index(['status','type','outtime'], 'store_cart_invalid');
    $t->index(['user_id','status','type'], 'store_cart_user');
});
Schema::table('store', function (Blueprint $t) {
    $t->index(['status','type','created_at'], 'store_list');
});
Schema::table('store', function (Blueprint $t) {
    $t->decimal('discount_price', 10, 2)->default(0)->change();   // 修 V-12
});
```

### PR-03-E：库存并发原子性（修 V-10）

修改文件：
- `src/Service/StockService.php`：把 select-then-decrement 改为原子 SQL：
```php
$updated = StoreModel::query()
    ->where('id', $storeId)
    ->where(function ($q) {
        $q->where('stock', '>', 0)->orWhere('stock', '=', -99);
    })
    ->update([
        'stock' => DB::raw('CASE WHEN stock = -99 THEN -99 ELSE stock - 1 END'),
    ]);
if (!$updated) { throw new ValidationException([...]); }
```

### PR-03-F：DeleteStoreController 软删除（修 V-11）

新增 migration：
```php
$table->softDeletes();
```

修改文件：
- `src/Model/StoreModel.php`：use `SoftDeletes`；
- 所有查询 `where('status', 1)` 改为 `whereNull('deleted_at')->where('status', 1)`（Eloquent 自动 scope）；
- `DeleteStoreController`：改为软删除。

### PR-03-G：i18n 一致性

修改文件：
- `locale/en.yml`：
  - 修正 `cannot-Goods-repeatedly` → `cannot-purchase-repeatedly`；
  - 补全 `item-cart-status-{0,1,2,all}` / `item-cart-type-{permanent,limit,all}` / `item-cart-auto-deduction-{0,1,all}`；
- `locale/zh-Hans.yml`：补全对应 key；
- 把 `js/src/forum/components/component/CartItem.tsx` 等的中文硬编码改为 `app.translator.trans(...)`；
- 删除 `src/Enum/LimitUnitEnum.php` 中的中文字典（改用 i18n）。

**注意**：`tital` 拼写错误**保留作为别名**：
```yaml
mattoid-store:
  forum:
    title: 商店
    tital: $$ref{mattoid-store.forum.title}    # 兼容旧用法
```

**阶段 3 验收**：
- V-10/V-11/V-12 关闭；
- 项目分层清晰：Controller → Service → Model；
- 第三方插件可享受 DI；
- 大流量索引就位；
- i18n 完全国际化。

---

## 阶段 4 · P3 长期治理（持续）

### 4.1 测试覆盖率

- 持续提升单元 / 集成 / Feature 测试覆盖率；
- 目标：3 个月内 ≥ 60%。

### 4.2 监控与可观测性

- 接入 Prometheus / Datadog / Sentry；
- 关键指标：购买成功率、5xx 比例、自动续费成功率、库存异常告警；
- 新增 `/api/store/health` endpoint 暴露剩余库存接近 0 的商品。

### 4.3 文档体系

- `docs/extension-developer-guide.md`：完整商品插件开发指南（含示例）；
- `docs/upgrade-guide.md`：版本升级指南（特别是 BalanceManager 迁移）；
- `docs/architecture.md`：架构图 / ER 图（mermaid）；
- `docs/api-reference.md`：API 路由完整文档；
- `CHANGELOG.md`：遵循 Keep a Changelog；
- README 增加截图。

### 4.4 扩展能力

按需补充：
- 多币种支持；
- 商品组合 / 套餐；
- 赠送 / 转赠；
- 更多钩子（BeforeBuy / CalculatePrice / OnExpire 等）；
- 队列化自动续费（高并发场景）。

### 4.5 UI/UX 优化

- 购买成功后局部更新（取消 `location.reload()`）；
- 高价商品二次确认弹窗；
- 错误码精细化前端展示；
- 移动端 webm 视频图标兼容性测试。

### 4.6 兼容 / 升级策略

- 维护 1.x stable 分支（向后兼容）；
- 2.x 开始可移除内部事件别名、清理死代码；
- 制定 deprecation 日历（提前 1-2 minor 版本预告）。

---

## 关键依赖与风险

| 项 | 依赖 | 风险 |
|----|------|------|
| BalanceManager 迁移 | 宿主必须装 `antoinefr/money` | 不装则 500，需在 `composer.json` 强制 require + 发版说明 |
| 数据库迁移补索引 | DDL 在大表上耗时 | 提供 `--online`（pt-online-schema-change）的运维提示 |
| 软删除引入 | 已有数据 deleted_at = null | 默认 OK，但所有查询需测试 scope |
| Service 层重构 | 第三方商品插件耦合度 | 保持事件 BC，重构内部即可 |
| i18n key 改名 | 商品插件可能引用 | 用别名兼容 |
| TS 类型化 | bundle 体积可能变化 | 配合 bundlewatch 监控 |

---

## 里程碑示例

| 版本 | 阶段 | 内容 | 预期发布周期 |
|------|------|------|-------------|
| v1.x.1 | 阶段 0+1 | 整理 + P0 修复 | 1 周内 |
| v1.x.2 | 阶段 2 | BalanceManager + TS 类型 | 2-3 周后 |
| v1.x.3 | 阶段 3 上半 | Service 层 + DI | 5-6 周后 |
| v1.x.4 | 阶段 3 下半 | 索引 + i18n + 软删除 | 8-9 周后 |
| v2.0.0 | 阶段 4 | 长期治理（破坏性整理） | 3-6 个月 |

---

## 当前阶段建议

**立刻可做**（无需破坏性变更）：

1. PR-00-A：清理 .gitignore + 删除 `.DS_Store` / `node_modules` 等 → 30 分钟；
2. PR-01-A：上传安全（V-01/V-02）→ 1-2 小时；
3. PR-01-B：catch + 权限 + NPE（V-05/V-06/V-08）→ 1-2 小时；
4. PR-01-C：admin allowGuest（V-03）→ 30 分钟。

**前 4 个 PR 半天内可完成，能显著降低安全风险**，且没有破坏性变更。

后续阶段按团队节奏推进即可。

---

> **回到索引**：[00-index.md](./00-index.md)
