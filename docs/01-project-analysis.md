# 01 - 项目结构与功能分析

> 仓库：`/Users/liufei/work/mattoid/php/flarum-ext/flarum-ext-store/`
> 包名：`mattoid/flarum-ext-store`
> 命名空间：`Mattoid\Store\`
> 类型：Flarum 扩展（依赖 `flarum/core ^1.2.0`）

---

## 1. 项目定位

为 Flarum 论坛提供一个**可扩展的"积分商店"框架**：

- 自身不内置任何商品类型；
- 所有商品类型由"商品插件"通过 `StoreExtend` 注册；
- 提供完整的购买、库存、续费、自动扣费、退款、商品使用 等通用基础设施；
- 通过事件与 5 个 abstract 基类（`Goods/Validate/After/Invalid/Enable`）暴露给"商品插件"。

### 已知配套商品插件

- [Invitation Code（审核版邀请码）](https://github.com/Mattoids/flarum-ext-store-invite)
- [Check-in Card（补签卡）](https://github.com/Mattoids/flarum-ext-store-check-in)
- [Auto Check-in Card（自动签到卡）](https://github.com/Mattoids/flarum-ext-store-auto-check-in)

---

## 2. 目录树

```
flarum-ext-store/
├── composer.json             # PSR-4 自动加载、Flarum 扩展定义
├── extend.php                # 入口：路由 / 事件 / 调度 / Filesystem
├── README.md / docs/         # 文档（已新增本系列分析文档）
├── LICENSE.md (LPL-1.02)
├── .styleci.yml / .editorconfig / .gitattributes / .gitignore
├── .github/workflows/        # 后端 / 前端 CI
├── migrations/               # 7 个数据库迁移
├── src/                      # 后端 PHP 业务代码
├── js/                       # 前端 TS/TSX 源码 + Webpack 构建
├── less/{forum.less, admin.less}
├── locale/{en.yml, zh-Hans.yml}
└── tests/                    # phpunit 占位（无实际测试）
```

---

## 3. 后端 PHP（`src/`）

### 3.1 src/ 子目录树

```
src/
├── Attributes/UserAttributes.php       # 注入 canStoreView 到 BasicUserSerializer
├── Console/
│   ├── Command/GoodsInvalidCommand.php # 定时任务：失效检测 + 自动续费
│   └── PublishSchedule.php             # 调度策略：每分钟 + withoutOverlapping
├── Controller/                         # 10 个 REST 控制器
├── Enum/LimitUnitEnum.php              # 折扣期限单位（含硬编码中文）
├── Event/                              # 7 个事件类
├── Extend/StoreExtend.php              # 商品插件注册扩展点
├── Goods/                              # 5 个抽象基类
│   ├── After.php / Enable.php / Goods.php / Invalid.php / Validate.php
├── Listeners/                          # 5 个事件监听器
├── Model/                              # 4 个空壳 Eloquent 模型
├── Serializer/                         # 5 个 JSON:API 序列化器
├── Upload/{StoreUploader, StoreValidator}.php
└── Utils/{ObjectsUtil, StringUtil}.php
```

> **缺失**：无 `Repository/`、`Service/`、`Middleware/`、`Provider/`。业务逻辑全部塞在 Controller 与 Listener。

### 3.2 API 路由表

`extend.php` 注册了 10 条 API 路由：

| Method | Path | 路由名 | Controller | 权限 |
|--------|------|--------|------------|------|
| GET | `/store/list` | store.list | ListStoreController | group-view |
| GET | `/store/goods` | store.goods.list | ListGoodsController | group-view |
| GET | `/store/icon/list` | store.icon.list | ListIconController | group-view |
| GET | `/store/cart/list` | store.cart.list | ListCartController | group-view |
| POST | `/store/use/goods` | store.use.goods | UseGoodsController | group-moderate ⚠ |
| PUT | `/store/goods` | store.goods.put | PutStoreController | group-moderate |
| DELETE | `/store/goods` | store.goods.delete | DeleteStoreController | group-moderate |
| POST | `/store/upload/icon` | store.upload.icon | StoreUpdateIconController | **无校验** ⚠ |
| POST | `/store/buy/goods` | store.buy.goods | BuyGoodsController | group-view |
| POST | `/store/goods` | store.goods.post | PostStoreController | group-moderate |

### 3.3 Controllers 详解

#### `BuyGoodsController`（核心 - 购买）
路径：`src/Controller/BuyGoodsController.php`
- 注入 `SettingsRepositoryInterface, UserRepository, Dispatcher, Translator, CacheContract`。
- 流程（见第 5 节调用链）：权限 → 5 秒分布式锁 → 库存/重复购买/Validate 校验 → 折扣价计算 → 余额扣减（**乐观锁** `where('money',$money)`）→ `StoreCartAddEvent`（监听器扣库存）→ `MoneyHistoryEvent`（可选）→ `After::after()`（失败则 `StoreBuyFailEvent` 回滚）→ `StoreCartEditEvent(status=1)` → `StoreBuyEvent`。
- **未使用 DB 事务**。

#### `PostStoreController` / `PutStoreController`（管理 - 创建/编辑）
- `removeEmptySql($parseBody)` 把驼峰键转下划线后直接进 `insert/update` → mass-assignment 隐患。
- `bcdiv(bcmul(price, discount), 100, 2)` 计算折扣价（无 bcmath 则 `round(...,2)`）。
- 时间戳用 `Carbon::now()->tz($this->storeTimezone)`。
- ⚠ Put 中若 `$goods` 为 null 且 `status != 1`，仍然访问 `$goods->pop_up` 会 NPE。

#### `DeleteStoreController`
- 硬删除 `store` 行；**未级联清理 `store_cart`**（孤儿记录）。

#### `ListStoreController` / `ListGoodsController` / `ListIconController`
- 标准分页 `skip($offset)->take($limit+1)`。
- `ListIconController` 用 `group-view` 权限（普通用户也能列出全部图标 URL）。

#### `ListCartController`
- `where('user_id', $actor->id)` 严格隔离用户。
- 每条 cart 经 `StoreExtend::getEnable($code)` 反射查询，注入 `enableType`（N+1 类型反射）。

#### `UseGoodsController`
- ⚠ 要求 `group-moderate` 权限——**普通用户用不了自己的卡片**（拷自删除路径 bug）。
- `$cart` 未判空：`$cart->code` 在 cart 不存在时 NPE。

#### `StoreUpdateIconController`
- ⚠ **完全无权限检查**，任意已登录用户即可上传。
- 文件命名 `time()."_".random.ext`，`ext` 直接来源于 `pathinfo(getClientFilename, EXT)`（**客户端可控**）。
- `uuid = md5(content)` 唯一约束去重；重复上传抛 `file-exist`。

### 3.4 Model 层

`StoreModel / StoreGoodsModel / StoreCartModel / StoreGoodsIconModel`，**均为空壳**：仅 `protected $table`，**无** `$fillable / $casts / $relations / $guarded`。所有关联查询均在 Controller 中手写。

### 3.5 `Extend/StoreExtend`（核心扩展机制）

- 实现 `ExtenderInterface, LifecycleInterface`。
- 第三方插件通过链式 API 注册 5 类能力：
  ```php
  (new StoreExtend('your-code'))
    ->addStoreGoods(Goods::class)
    ->addValidate(Validate::class)
    ->addAfter(After::class)
    ->addInvalid(Invalid::class)
    ->addEnable(Enable::class);
  ```
- 内部 5 个**静态私有数组**寄存：`$goodList/$afterList/$validateList/$invalidList/$enableList`（key=商品 code）。
- 每个 getter 都 `new $class`（**不走 DI 容器、无缓存**），故子类无法构造器注入服务。
- `onEnable()`：把 Goods 元信息（含 JSON 的 `popUp`）插入 `store_goods` 表。
- `onDisable()`：把对应 code 的所有 `store.status=0`（下架）+ 删除 `store_goods` 元数据；`store_cart` 历史保留。

### 3.6 `Goods/` 抽象基类

| 类 | 抽象方法 | 用途 |
|----|---------|------|
| `Goods` | 属性 `$name, $popUp, $className='store-buy Modal--small'` | 商品元信息（弹窗 schema） |
| `Validate` | `static validate(User, StoreModel, $params): bool` | 购买前校验 |
| `After` | `static after(User, StoreModel, $params): bool` | 购买后业务 |
| `Invalid` | `static invalid(StoreModel, StoreCartModel): bool` | 商品失效 |
| `Enable` | `static enable(User, StoreModel, StoreCartModel): bool` | 用户使用/取消使用 |

`popUp` 结构示例：
```json
[{"label":"标题i18n","prop":"input|switch|select|textarea","type":"text","value":"field","helpText":"提示i18n","options":{}}]
```

### 3.7 Events + Listeners

| Event | 监听器 | 行为 |
|-------|--------|------|
| `StoreCartAddEvent` | `StoreCartAddListeners` | 创建 cart（status=0, enable=0），limit 类计算 outtime；dispatch `StoreStockSubEvent` |
| `StoreCartEditEvent` | `StoreCartEditListeners` | 刷新 cart.status；status>1 时 dispatch `StoreStockAddEvent` 回滚库存 |
| `StoreStockSubEvent` | `StoreStockSubListeners` | `stock!=-99`：stock<1 抛 `insufficient-inventory`，否则 `decrement('stock')` |
| `StoreStockAddEvent` | `StoreStockAddListeners` | `stock!=-99` 时 `increment('stock')` |
| `StoreBuyFailEvent` | `StoreBuyFailListeners` | cart.status=2 + CartEdit 触发库存回滚；乐观锁退款；可选 MoneyHistoryEvent(STOREBUYGOODSFAIL) |

**外部插件监听**（本项目不注册 listener）：
- `StoreBuyEvent`：购买成功通知。
- `StoreInvalidEvent`：商品失效通知（参数包含成功/失败状态）。

### 3.8 `Console/`

- `PublishSchedule::__invoke($event)`：`$event->everyMinute()->withoutOverlapping()->timezone($timezone)`；日志写入 `storage/logs/mattoid-store.log`。
- `GoodsInvalidCommand`（命令 `mattoid:store:check:date`）：
  1. 查 `store_cart` 中 `type=limit AND status=1 AND outtime<=now`；
  2. 批量取 store map；
  3. 对每条 cart 尝试 `autoDeduction`（需 `cart.auto_deduction=1 && store.status=1`）：分布式锁 + 乐观锁扣 `store.price`，刷新 outtime/pay_amt/created_at；推 `MoneyHistoryEvent(AUTODEDUCTION)`；
  4. 失败则调 `Invalid::invalid(store, cart)` 并把 cart.status=2；
  5. 最后 `dispatch(StoreInvalidEvent($store, $cart, $buyStatus))`。

### 3.9 `Attributes/UserAttributes`

注入到 `BasicUserSerializer`，向前端 user 模型暴露：
- `canStoreView = actor->can('mattoid-store.group-view')`

供前端 `forum/index.ts` 判断是否显示"商店/购物车"入口。

### 3.10 `Upload/`

- `StoreUploader`：使用 `mattoid-store` disk；`upload(File)` 用 `time()_random.ext` 命名；`getFileMd5()` 计算流 md5；`remove()` 按名删除。
- `StoreValidator`：继承 `AvatarValidator`，限 `max:4096` KB，允许扩展名 `png/jpeg/jpg/webm`，但 ⚠ `assertFileMimes` 被注释，实际只校验扩展名。

### 3.11 `Utils/`

- `ObjectsUtil::removeEmpty`：移除空字段（保留 `0/'0'`）。
- `ObjectsUtil::removeEmptySql`：同上，并把 key 由驼峰转下划线。
- `StringUtil::toUnderScore / toCamelCase`。

---

## 4. 数据库（`migrations/`）

7 个迁移文件：

### `store_goods` (商品基类元数据)
```
code (unique, PK) | name | class_name | pop_up (text/json) | created_at(idx)
```
> ⚠ 没有 `id` 列，全靠 `code` 关联。

### `store` (上架商品)
```
id (PK) | code (idx) | title | class_name | price decimal(10,2) | stock int (default 0)
discount int | discount_limit int | discount_limit_unit string | discount_price int ⚠应为decimal
type string | status int default 1 | outtime int
icon | hide | repeat | desc text | pop_up text | auto_deduction int default 0
timestamps
```

### `store_cart` (购物车 / 购买记录)
```
id (PK) | user_id(idx) | store_id(idx) | code | title
price decimal(10,2) | pay_amt decimal(10,2)
type | outtime datetime(idx) | status default 0
auto_deduction int default 0 | enable int default 0
timestamps
```
`status` 取值：0未支付 / 1已支付 / 2已失效 / 9超时失效

### `store_goods_icon` (图标库)
```
id | uuid char(32) unique | url | count int | timestamps
```

> **数据库约束观察**：
> - 全部外键裸 `int/string`，**无 FK 约束**；
> - **无联合索引**（如 `store_cart(user_id, store_id, status)` 或 `store_cart(status, outtime, type)`）；
> - `store.discount_price` 是 `integer`，但代码以 decimal 写入 → 精度丢失。

---

## 5. 前端（`js/`）

### 5.1 入口

- `forum.ts → ./src/forum`，`admin.ts → ./src/admin`。
- `forum/index.ts`：注册 `app.routes.store` / `app.routes.myCartPage`；通过 `extend(UserPage/IndexPage, 'navItems')` 注入侧栏入口（依赖 `canStoreView`）。
- `admin/index.tsx`：注册 `ExtensionPage(StoreListPage)`；注册两条权限 `group-view` 与 `group-moderate`（⚠ 均 `allowGuest: true`）。

### 5.2 文件结构

```
js/src/
├── admin/
│   ├── index.tsx
│   └── components/
│       ├── StoreListPage.tsx           # 商品列表 + 设置
│       ├── StoreListItem.tsx           # 单条商品（编辑/删除/上下架按钮）
│       ├── AddStoreGoods.tsx           # 添加商品弹窗（选 code）
│       ├── StoreGoodsDetailModal.tsx   # 商品编辑大表单（含图库切换）
│       └── StoreModal.tsx              # 通用确认弹窗
└── forum/
    ├── index.ts
    └── components/
        ├── pages/
        │   ├── StorePage.tsx           # 商店主页
        │   └── MyCartPage.tsx          # 购物车页（多条件筛选）
        ├── component/
        │   ├── StoreItem.tsx           # 商品卡片
        │   └── CartItem.tsx            # 购物车条目（使用/取消按钮）
        └── modal/
            └── StoreBox.tsx            # 购买弹窗（动态渲染 popUp）
```

### 5.3 关键 UI 交互

- **购买**：`StoreBox` 用 `JSON.parse(storeData.popUp)` 动态渲染表单 → `POST /store/buy/goods {id, ...customFields}` → 成功 `location.reload()`。
- **使用商品**：`CartItem`（`enableType==1` 时）"使用/取消"按钮 → `POST /store/use/goods {id}`。
- **图标上传**：`StoreGoodsDetailModal` 通过 `<input type=file>` 直 POST `/store/upload/icon`，回填 `params.icon`；含"切换图库"二级视图（`/store/icon/list`）。
- **隐藏商品**：`StorePage` 检查 `app.session.user?.attribute('can'+CamelCode+'View')`——**约定式权限注入**，但后端 **未自动注册**该权限。

### 5.4 i18n

- `locale/en.yml` + `locale/zh-Hans.yml`。
- ⚠ **en.yml 与 zh-Hans.yml 不对齐**：
  - en：`cannot-Goods-repeatedly`，zh：`cannot-purchase-repeatedly`，**backend 抛的是后者**（英文站会显示找不到翻译）。
  - en 缺 `item-cart-status-*` / `item-cart-type-*` / `item-cart-auto-deduction-*`。
  - 英文环境下 forum 标题 i18n key 为 `tital`（拼写错误）。
- `CartItem.tsx` / `StoreListItem.tsx` 含大量**硬编码中文**："未支付/已支付/已失效/超时失效/限时有效/永久有效/是/否/天/小时" 等。
- `Enum/LimitUnitEnum.php` 是硬编码中文字典 `['days'=>'天', 'hour'=>'小时', ...]`。

---

## 6. 关键业务流程（完整调用链）

### 6.1 购买流程

```
[Forum] StoreBox onsubmit
   ↓ POST /store/buy/goods { id, ...popUpFields }
[BE] BuyGoodsController::data()
 ├─ 权限：actor->can('mattoid-store.group-view')
 ├─ cache->add(md5(id-actorId), 5s)             ※ 防重锁
 ├─ StoreModel::where(id, status=1)             ※ 查商品
 ├─ stock check (-99 = 无限)
 ├─ if !repeat → 查同店有效订单 → 409 cannot-purchase-repeatedly
 ├─ StoreExtend::getValidate(code)?->validate() ※ 商品插件钩子
 ├─ 折扣计算（updated_at + discount_limit unit < now → discount_price）
 ├─ 余额校验（user.money - price ≥ 0）
 ├─ dispatch(StoreCartAddEvent)
 │     → CartAddListener: new cart(status=0) → dispatch(StoreStockSubEvent)
 │                                                    → stock--（或抛 insufficient-inventory）
 ├─ user.money -= price; user->where('money',oldMoney)->save()  ※ 乐观锁
 │     失败 → dispatch(StoreStockAddEvent) 回滚库存 → 抛 user-balance-low
 ├─ (可选) MoneyHistoryEvent(STOREBUYGOODS, -price)
 ├─ StoreExtend::getAfter(code)?->after()       ※ 商品插件钩子
 │     try/catch → 失败 → dispatch(StoreBuyFailEvent)
 │                          → BuyFailListener:
 │                                cart.status=2 → CartEdit → StockAdd 回滚
 │                                user.money += pay_amt 退款（乐观锁）
 │                                (可选) MoneyHistoryEvent(STOREBUYGOODSFAIL)
 ├─ cart.status=1; dispatch(StoreCartEditEvent)
 ├─ dispatch(StoreBuyEvent)                      ※ 外部插件订阅
 └─ cache->delete(key); return GoodsSerializer($store)
```

### 6.2 自动扣费/失效（定时）

```
[PublishSchedule] schedule:run / cron 每分钟
   ↓
GoodsInvalidCommand::fire()
 ├─ 查 store_cart where outtime<=now AND type=limit AND status=1
 ├─ 批量取 storeMap
 └─ foreach cart:
      try autoDeduction:
        ├─ require cart.auto_deduction=1
        ├─ require store.status=1
        ├─ cache lock 5s
        ├─ 乐观锁扣 user.money -= store.price（无折扣）
        ├─ cart.outtime += store.outtime days
        ├─ cart.pay_amt=store.price; cart.created_at=now
        ├─ (可选) MoneyHistoryEvent(AUTODEDUCTION)
        └─ release lock
      catch:
        buyStatus=false
        StoreExtend::getInvalid(code)?->invalid(store, cart)
        cart.status=2; save
      dispatch(StoreInvalidEvent(store, cart, buyStatus))
```

### 6.3 商品使用 / 取消

```
[Forum] CartItem 「使用/取消」按钮
   ↓ POST /store/use/goods { id }
[BE] UseGoodsController::data()
 ├─ 权限：actor->can('mattoid-store.group-moderate')   ⚠ 错配
 ├─ cart = StoreCartModel::where(id=id, user_id=actor)
 ├─ enable = StoreExtend::getEnable($cart->code)
 ├─ cart->enable = ! cart->enable
 ├─ enable::enable($actor, $store, $cart)              ※ 商品插件钩子
 └─ cart->save()
```

---

## 7. 技术栈

| 维度 | 选型 |
|------|------|
| PHP | ≥7.4（实际用了 PHP 7 风格） |
| Flarum | `^1.2.0` |
| ORM | Eloquent |
| 缓存 | `Illuminate\Contracts\Cache\Repository`（默认 file/array，多机需 Redis） |
| 时区 | `Asia/Shanghai`（可通过 `mattoid-store.storeTimezone` 配置） |
| 资金记录 | 可选集成 `Mattoid\MoneyHistory\Event\MoneyHistoryEvent`（`class_exists` 检查） |
| 前端 | TypeScript 4.7 + Mithril + flarum-webpack-config |
| 样式 | LESS |
| 测试 | phpunit（**仅占位骨架**） |
| CI | GitHub Actions（backend.yml / frontend.yml） |
| 风格 | StyleCI + Prettier + EditorConfig |

---

## 8. 扩展机制（第三方插件接入约定）

1. composer.json 依赖 `mattoid/flarum-ext-store`；
2. 自家 `extend.php` 返回：
   ```php
   (new \Mattoid\Store\Extend\StoreExtend('your-code'))
       ->addStoreGoods(MyGoods::class)
       ->addValidate(MyValidate::class)
       ->addAfter(MyAfter::class)
       ->addInvalid(MyInvalid::class)
       ->addEnable(MyEnable::class);
   ```
3. 5 个类继承 `Mattoid\Store\Goods\{Goods,Validate,After,Invalid,Enable}`；
4. `Goods` 定义 `$name` (i18n key) / `$popUp` / `$className`；
5. 插件启用时框架自动把 Goods 元数据写入 `store_goods` 表，管理端"添加商品"列表即可看到；
6. 插件禁用时自动下架对应 `store` + 删除 `store_goods` 元数据（cart 历史保留）；
7. 可监听 `StoreBuyEvent` / `StoreInvalidEvent`；
8. 可选集成 `Mattoid\MoneyHistory\Event\MoneyHistoryEvent`。

---

## 9. 设计亮点 vs 缺陷概览

### 亮点

1. **扩展点设计清晰**：5 个抽象基类 + onEnable/onDisable 钩子让第三方插件可挂载完整生命周期。
2. **事件驱动**：库存、购物车、退款解耦到事件层，第三方可订阅。
3. **乐观锁防并发扣费**：`$user->where('money', $oldMoney)->save()` 在并发场景能避免余额透支（虽非完美方案）。
4. **5 秒分布式锁防重复点击**：`cache->add(md5(id-userId), time(), 5)`。
5. **无限库存约定值** `-99`：避免特殊状态分支耦合。
6. **图标 MD5 唯一去重**：避免重复存储。

### 缺陷（详见 04-security-audit.md）

1. 上传无权限 + MIME 检查失效。
2. 购买全流程无 DB 事务。
3. 部分权限 `allowGuest: true` 错误开放。
4. `UseGoodsController` 权限错配 + NPE 风险。
5. mass-assignment：管理表单字段全字段写入。
6. i18n 不彻底（硬编码中文 + en/zh 不对齐 + 拼写错误 `tital`）。
7. 无 `Repository/Service` 层，业务逻辑分散。
8. 无单元测试。
9. Model 无 `$fillable/$casts/$relations`。
10. `StoreExtend` getter 每次 `new`，无缓存且不走 DI。

---

> **下一步**：参见 [02-project-comparison.md](./02-project-comparison.md) 了解与对照仓库的差异。
