# 05 - 优化空间分析

> 范围：从**架构、性能、可维护性、可测试性、国际化、可用性**等维度梳理改进点
> 目标：识别投入产出比合理、可分阶段落地的优化机会
> 注意：本文档侧重"为什么/有什么空间"，具体落地步骤见 [03-optimization-roadmap.md](./03-optimization-roadmap.md)。

---

## 1. 架构层面

### 1.1 缺失 Service 层 → 业务逻辑分散

当前架构：

```
HTTP Request
    ↓
Controller::data()  ←─ 包含权限校验、参数解析、业务逻辑、事件分发、序列化
    ↓
Event / Listener   ←─ 部分业务逻辑（库存、退款、cart 状态）
    ↓
Eloquent Query    ←─ 直接 query builder，没有 Repository
```

**问题**：
- `BuyGoodsController::data()` 长达 100 行，混合了：HTTP 解析、权限、5 秒锁、库存校验、重复购买校验、折扣计算、扣费、库存扣减、商品后置钩子、退款、事件通知；
- 想在 CLI / Console 复用同样购买逻辑（如批量发货） → **必须复制粘贴**；
- 单元测试需要 mock `ServerRequestInterface` 才能跑业务测试 → **测试门槛高**。

**优化空间**：

```
                          BuyGoodsController
                           ↓ (只做参数解析 / 权限 / 序列化)
                          BuyGoodsService::buy($user, $storeId, $params)
                           ↓
            ┌──────────────┼──────────────┐
            ↓              ↓              ↓
       StockService   PaymentService   CartService
            ↓              ↓              ↓
         events...      events...      events...
```

**收益**：
- 每个 service 独立单元测试；
- 跨入口复用（HTTP / Console / Event）；
- 业务逻辑集中，便于事务包裹。

### 1.2 Model 完全空壳

`StoreModel / StoreCartModel / StoreGoodsModel / StoreGoodsIconModel` 仅声明 `$table`，未定义：
- `$fillable` / `$guarded`：导致 mass-assignment 风险（见 04-security-audit.md V-07）；
- `$casts`：`outtime` 从 string 转回 Carbon 实例靠 Eloquent 默认行为，无显式 cast；
- `$dates`：timestamps 默认行为；
- `belongsTo / hasMany` 关联：全部在 Controller 中手写 JOIN/where。

**优化空间**：

```php
// src/Model/StoreCartModel.php
class StoreCartModel extends AbstractModel {
    protected $table = "store_cart";
    protected $fillable = ['user_id','store_id','code','title','price','pay_amt','type','outtime','status','auto_deduction','enable'];
    protected $casts = [
        'price' => 'decimal:2',
        'pay_amt' => 'decimal:2',
        'outtime' => 'datetime',
        'status' => 'integer',
        'auto_deduction' => 'boolean',
        'enable' => 'boolean',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function store() { return $this->belongsTo(StoreModel::class, 'store_id'); }
}
```

**收益**：
- 杜绝 mass-assignment；
- `cart.price` 直接返回 `Decimal` 对象（避免 0.1+0.2 精度问题）；
- `cart.user`、`cart.store` 直接惰性加载，避免重复 N+1 查询；
- IDE 可补全。

### 1.3 `StoreExtend` 静态全局状态

```php
private static $goodList = [];
private static $afterList = [];
// ...
public static function getValidate(String $key) { return new $class; }
```

**问题**：
- **完全脱离 DI**：抽象基类无法用构造器注入 `$translator/$settings/$cache`，注释里提到这些依赖但拿不到；
- 每次 `getValidate` 都 `new` 一次 → 商品列表渲染（N 个 cart）时反复 new；
- 静态状态在测试场景中难以隔离/重置。

**优化空间**：

```php
class StoreExtend implements ExtenderInterface, LifecycleInterface {
    public function extend(Container $container, Extension $extension = null) {
        // 用容器注册命名服务
        $container->singleton("store.validators.{$this->key}", $this->validateClass);
        $container->singleton("store.afters.{$this->key}", $this->afterClass);
        // ...
    }
    public static function getValidate(string $key): ?Validate {
        $service = "store.validators.{$key}";
        return resolve($service);
    }
}
```

**收益**：
- 走 Flarum 容器 → 抽象基类可构造器注入服务；
- singleton 缓存避免重复 new；
- 测试时可 `app()->instance(...)` 覆盖。

### 1.4 事件总线滥用作为内部业务调度

5 个 `Mattoid\Store\Event\*` 既被本插件 listener 监听，又被外部插件可监听。**职责混淆**：
- `StoreCartAddEvent` 是"通知外部购买开始" or "通知本插件创建 cart"？两者都是；
- 修改 listener 时容易破坏外部插件兼容性；
- 错误处理路径分散在 listener 与 controller 之间。

**优化空间**：拆分为两套事件
- **内部事件**：`Mattoid\Store\Internal\Event\*`（不对外承诺兼容性，可随版本变更）；
- **外部事件**：`Mattoid\Store\Event\*`（仅在业务完成后通知，保证向后兼容）。

或者把内部"事件驱动"改为直接函数调用（Service 层），仅保留"购买成功/失败"两个外部事件。

---

## 2. 数据库与性能

### 2.1 索引不足

当前迁移仅声明：
- `store_goods.code` unique
- `store_goods.created_at` idx
- `store.code` idx
- `store_cart.user_id` idx
- `store_cart.store_id` idx
- `store_cart.outtime` idx
- `store_goods_icon.uuid` unique

**缺失的高频查询索引**：

| 查询 | 来源 | 推荐索引 |
|------|------|----------|
| `BuyGoodsController` 查重购买 `where user_id, store_id, status, type, outtime` | controller:82-91 | `INDEX store_cart_dup (user_id, store_id, status)` |
| `GoodsInvalidCommand` 查失效 `where type='limit', status=1, outtime<=now` | command:59 | `INDEX store_cart_invalid (status, type, outtime)` |
| `ListCartController` 用户 cart + filter | controller | `INDEX store_cart_user (user_id, status, type)` |
| `ListStoreController` 分页 `where status, type` | controller | `INDEX store_list (status, type, created_at)` |

**收益**：cart 数据量上 10w+ 时，每分钟的 InvalidCommand 从全表扫描降至索引扫描。

### 2.2 N+1 查询

`ListCartController::data()`:
```php
foreach ($cartList as $cart) {
    $cart->enableType = StoreExtend::getEnable($cart->code) ? 1 : 0;
}
```
- 每条 cart 调一次 `StoreExtend::getEnable`，内部 `new $class` → 100 条 cart = 100 次反射 new；
- 若 `getEnable` 内部还要查 DB（虽目前不查），就是 N+1。

**优化空间**：
```php
// 批量预计算
$codes = $cartList->pluck('code')->unique();
$enableMap = $codes->mapWithKeys(fn($code) => [$code => StoreExtend::getEnable($code) ? 1 : 0]);
foreach ($cartList as $cart) {
    $cart->enableType = $enableMap[$cart->code];
}
```

### 2.3 GoodsInvalidCommand 全量扫描

```php
$invalidList = StoreCartModel::query()->where(...)->get();   // 一次全量加载
```

当过期 cart 数量大（如平台大促后批量失效），单次内存爆炸 + 单 worker 串行处理慢。

**优化空间**：分批 chunk 处理
```php
StoreCartModel::query()->where(...)->chunkById(200, function ($carts) {
    foreach ($carts as $cart) {
        // ...
    }
});
```

且引入并发执行（如 dispatch 到队列）：
```php
foreach ($carts as $cart) {
    dispatch(new AutoDeductionJob($cart->id));
}
```

### 2.4 `discount_price` 字段类型不当

`integer` 列存 decimal 字符串（`bcdiv` 返回 `"9.99"`） → 强转丢小数。

```sql
ALTER TABLE store MODIFY discount_price decimal(10,2) NOT NULL DEFAULT 0;
```

### 2.5 部分字段冗余

`store_cart` 中 `title / price / code` 都是 `store` 表的快照。这是合理的（防止 store 变更或删除导致购物车数据丢失），但：
- `title` 无索引也无搜索需求 → OK；
- `code` 在 query 中频繁参与 `StoreExtend::getEnable($code)` → 可加 `INDEX store_cart_code (code)` 便于按商品类型聚合分析。

---

## 3. 可维护性

### 3.1 代码风格不统一

- `function() {` vs `function () {`；
- `!$x` vs `! $x`；
- `String $key` vs `string $key`（PHP 8 大小写敏感弃用）；
- 单引号 vs 双引号；
- `import {X} from "y"` vs `import { X } from 'y'`；

对照仓库 B 已用 Prettier + StyleCI 统一格式。**建议同步并加入 pre-commit 钩子**。

### 3.2 缺失 PHPDoc / 类型注解

大部分方法没有 `@param/@return`，部分 `@var $name any` 也没。当函数返回值复杂（如 `BuyGoodsController` 返回 `$store` 而 serializer 是 `GoodsSerializer`）会很容易出错。

**优化空间**：开启 PHPStan level 5，配合 Flarum 官方 baseline。

### 3.3 死代码

- `StoreModel` 等 4 个 model 中保留了空的 `getFormatter()/setFormatter()` 接口槽位，**全项目无调用**；
- `BuyGoodsController.php:26` `use Mockery\Exception;`（测试库，不应在业务代码出现）；
- README 提到 5 个事件但代码里有 7 个（含 `StoreBuyEvent`、`StoreInvalidEvent`）；
- `extend.php` 中两条 `commented` 的 Setting 注册被注释保留。

**优化空间**：删除/清理。

### 3.4 命名不一致

- `Listeners` 文件夹（正确是单数 `Listener`，Flarum 官方风格）；
- `LimitUnitEnum` 用 `Enum` 后缀但实际不是 PHP `enum` 类，只是 class with static array；
- `Model` 后缀（`StoreModel/StoreCartModel/...`）与 Flarum 主流（`User`、`Discussion`、`Post`）风格不一致；
- `StoreUpdateIconController` 命名容易误读（实际是 upload，不是 update）。

**优化空间**：保留命名（避免 BC break），但新代码遵循 Flarum 主流风格。

### 3.5 i18n 不彻底

详见 04-security-audit.md V-14 与 01-project-analysis.md 第 5.4 节。

**优化空间**：
1. 建立 i18n 一致性 CI：对比 en.yml / zh-Hans.yml 的 key 集合；
2. 修正拼写：`tital` → `title`（需提供别名兼容）；
3. 移除所有硬编码中文（CartItem.tsx 状态文案、Enum/LimitUnitEnum）；
4. 补全 en.yml 中的 `item-cart-status-*` / `item-cart-type-*` 等翻译；
5. 对应英文 key `cannot-Goods-repeatedly` 改为 `cannot-purchase-repeatedly`（与 backend 抛出的一致）。

### 3.6 没有自动化测试

- `tests/` 仅有 `.gitkeep`；
- composer.json scripts 声明了 `test:unit / test:integration` 但实际没用例；
- CI workflows 跑的也都是 lint。

**优化空间**：分层补测试
| 层 | 测试目标 | 覆盖度 |
|---|---------|--------|
| Unit | Utils (StringUtil/ObjectsUtil) / Serializer / Event payload | 80%+ |
| Service (重构后) | BuyGoodsService / StockService / RefundService | 90%+ |
| Integration | API route 端到端（含权限、错误码） | 60%+ |
| Feature | 第三方插件集成场景（注册 StoreExtend + 完整购买） | 主路径 |

---

## 4. 可观测性

### 4.1 日志不足

整个项目几乎无 `Log::info/error`，异常都被 `throw ValidationException` 吞掉：
- `BuyGoodsController::data()` catch 中无日志；
- `GoodsInvalidCommand::handle()` 异常仅依靠 `appendOutputTo` 输出，**线上很难定位 race**；
- 资金、库存、扣费成功失败无审计 trail（除非装了 MoneyHistory 插件）。

**优化空间**：
```php
use Psr\Log\LoggerInterface;
// 注入到 controller / service
$this->logger->info('store.buy.start', ['store_id'=>$id,'user_id'=>$actor->id]);
$this->logger->info('store.buy.success', ['cart_id'=>$cart->id,'price'=>$price]);
$this->logger->error('store.buy.after_failed', ['cart_id'=>$cart->id,'exception'=>$e]);
```

### 4.2 监控埋点

- 没有 metrics（购买成功率、超时锁数量、自动续费失败率）；
- 没有 alert 通道（资金不平账、库存超卖触发时管理员看不到）；

**优化空间**：暴露 `/api/store/health` 给监控（剩余库存接近 0 的商品、当前持有失效卡的用户数 等）。

---

## 5. 用户体验

### 5.1 错误信息粒度粗

```php
throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.validate-fail')]);
```

`validate-fail` 同时用于"分布式锁未取到（重复点击）"、"插件 validate 返回 false（业务校验）"、"商品参数错误"。**用户看不出原因**。

**优化空间**：拆分错误码，前端针对不同 code 显示更精确的提示。

### 5.2 购买成功后 `location.reload()`

强制全页刷新，损失 SPA 体验：
- 翻页/筛选状态丢失；
- 闪烁明显；
- 大型论坛页面慢。

**优化空间**：返回更新后的 store/cart 数据，前端局部更新 `app.store.push(data)` + `m.redraw()`。

### 5.3 缺少购买确认弹窗

直接点击商品 → 弹动态表单 → 提交即扣费。**没有最终"确认扣费 X 元"提示**。

**优化空间**：StoreBox 增加 final confirm step，特别是高价商品。

### 5.4 商品图标 webm 视频自动播放策略不确定

允许 `webm` 扩展名上传但前端如何渲染、是否 autoplay/muted、移动端兼容性未确认。可能产生流量与 UX 问题。

---

## 6. 扩展性

### 6.1 商品插件钩子不够丰富

当前 5 个钩子：`Goods / Validate / After / Invalid / Enable`，缺失：
- **BeforeBuy**：在前端弹出表单前的钩子（动态调整 popUp schema）；
- **CalculatePrice**：动态计算价格（如 VIP 折扣、活动叠加）；
- **AfterRefund**：退款后的回调（与 After 对称）；
- **OnView**：商品被浏览时（埋点）；
- **OnExpire**：到期前 N 天提醒。

### 6.2 不支持多币种 / 多余额池

`user.money` 是 antoinefr/money 单一余额。如果论坛有多种积分（"金币"、"贡献值"），无法选择支付币种。

**优化空间**：通过 `payment_method` 字段路由到不同的余额扣减实现。

### 6.3 不支持商品组合 / 套餐

每次只能买一种商品，不能 "买 A 送 B"、"购买 5 件 9 折"等。

**优化空间**：引入 `store_combo` 表与对应 service。

### 6.4 不支持赠送 / 转赠

只能给 actor 自己购买，不能"为他人购买"。

**优化空间**：BuyGoodsController 接收 `target_user_id` 参数 + 鉴权。

---

## 7. 部署与运维

### 7.1 cache 驱动假设

5 秒分布式锁、调度 withoutOverlapping 依赖宿主有靠谱的 cache。建议在 README 中明确："Redis cache 推荐"。

### 7.2 时区配置易踩坑

`Asia/Shanghai` 默认 + 可被 `mattoid-store.storeTimezone` 覆盖。但项目内部多处都执行 `Carbon::now()->tz(...)`，**易出现一处忘记加 tz()** 导致跨日折扣判断错误。

**优化空间**：封装 `StoreClock::now()` 全局工具，统一处理时区。

### 7.3 文件存储路径硬编码

```php
'root'   => "$paths->public/assets/mattoid/store",
```
跨环境（如 CDN、对象存储）无法切换。

**优化空间**：通过 disk 配置注入：
```php
->disk('mattoid-store', function (Paths $paths, UrlGenerator $url) {
    $disk = $this->settings->get('mattoid-store.disk', 'public');
    return $this->disksManager->disk($disk);
});
```

---

## 8. 文档

### 8.1 README 信息密度低

- 没有架构图 / ER 图；
- 没有"商品插件开发指南"完整示例；
- 没有"购买流程"时序图；
- 仅有事件名清单，缺 payload 示例。

**优化空间**：本系列文档已开始补全；建议进一步：
- 增加 `docs/extension-developer-guide.md`（含完整示例插件源码）；
- 用 mermaid 绘制时序图（购买流程、自动扣费流程）；
- 提供 `docs/upgrade-guide.md`（迁移 BalanceManager 等破坏性变更）。

### 8.2 CHANGELOG 缺失

无版本变更日志，使用者难以判断升级影响。

**优化空间**：建立 `CHANGELOG.md`，遵循 [Keep a Changelog](https://keepachangelog.com/) 格式。

---

## 9. 量化的优化收益（粗略）

| 优化项 | 投入（工时） | 收益 |
|---|---|---|
| 修上传安全（V-01/V-02） | 1h | 消除 RCE/XSS |
| 修异常 catch（V-05） | 0.5h | 资金不一致风险大幅下降 |
| 修 UseGoods 权限 + NPE | 1h | 普通用户功能恢复可用 |
| 同步对照仓库 BalanceManager | 4h | 资金安全 + 简化逻辑 |
| 同步对照仓库 TS 类型化 | 6h | IDE 补全 + 减少前端 bug |
| 引入 DB 事务 | 2h | 杜绝 partial failure |
| 引入 Service 层 | 12-20h | 架构清晰、可测试性大幅提升 |
| 补充 i18n | 4h | 英文用户可用 |
| 引入索引 | 2h（含上线灰度） | 大流量稳定性 |
| 单元测试覆盖 60% | 30-50h | 长期质量护栏 |
| 加日志 + metrics | 4h | 线上可观测性 |
| 文档 + ER 图 | 4-8h | 降低社区学习门槛 |

> 总计：核心安全/资金修复（P0 + P1）≈ 8-10 小时即可显著降险；架构重构（P2）需要 1-2 周。

---

## 10. 不建议做的事

为了避免无效优化，**这些事在当前阶段先不动**：

1. **不要把 Eloquent 改为原生 SQL** —— Flarum 全栈基于 Eloquent，破坏一致性；
2. **不要为商品类型搞 microservice 拆分** —— 业务规模不足；
3. **不要引入复杂的 CQRS / EventSourcing** —— 当前事件模式已经够用；
4. **不要预制 GraphQL** —— Flarum 自身是 JSON:API，破坏生态；
5. **不要把所有 i18n key 改名** —— 会破坏已发布的商品插件兼容；
6. **不要把扣费/库存逻辑拆到队列** —— 用户点击购买需要同步反馈；可异步的只有自动扣费定时任务。

---

> **下一步**：参见 [03-optimization-roadmap.md](./03-optimization-roadmap.md) 的优先级编排和落地步骤。
