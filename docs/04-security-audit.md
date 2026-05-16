# 04 - 安全审计报告

> 范围：`/Users/liufei/work/mattoid/php/flarum-ext/flarum-ext-store/`
> 时间：2026-05-16
> 审计方法：人工代码审查 + 调用链分析（无运行时测试 / 渗透测试）

---

## 风险分级

| 级别 | 标识 | 说明 |
|------|------|------|
| 🔴 高危 | P0 | 可直接被利用造成实际损害（RCE、越权写文件、绕过支付） |
| 🟠 中危 | P1 | 需特定条件 / 较高权限才能利用，但影响明显 |
| 🟡 低危 | P2 | 难直接利用或仅影响数据完整性、可读性 |

---

## 漏洞清单（17 项）

### 🔴 V-01：文件上传缺失权限校验（P0）

**文件**：`src/Controller/StoreUpdateIconController.php:42-66`

```php
protected function data(ServerRequestInterface $request, Document $document){
    $result = [];
    $file = Arr::get($request->getUploadedFiles(), 'file');
    $this->validator->assertValid(['file' => $file]);
    // ⚠ 全程无任何 $actor->can(...) 校验
    ...
}
```

**风险**：API 路由 `POST /store/upload/icon` **没有任何权限检查**，任意已登录用户即可调用。结合 V-02 可实现任意类型文件上传到论坛的 `public/assets/mattoid/store/` 目录。

**利用场景**：
1. 注册新账号；
2. 直接 POST 一个名为 `evil.php` 的文件；
3. 文件被保存至 web 可访问目录。

**修复**：增加权限校验，应限定 `mattoid-store.group-moderate`：
```php
if (!$actor->can('mattoid-store.group-moderate')) {
    throw new PermissionDeniedException();
}
```

---

### 🔴 V-02：文件 MIME 校验被注释失效（P0）

**文件**：`src/Upload/StoreValidator.php:27-33`

```php
public function assertValid(array $attributes){
    $this->laravelValidator = $this->makeValidator($attributes);
    $this->assertFileRequired($attributes['file']);
//  $this->assertFileMimes($attributes['file']);   // ⚠ 关键校验被注释
    $this->assertFileSize($attributes['file']);
}
```

**文件**：`src/Upload/StoreUploader.php:16-18`

```php
public function upload(UploadedFileInterface $file) {
    $ext = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);  // ⚠ 完全信任客户端
    $filename = time()."_".Str::random().'.'.$ext;
    ...
}
```

**风险**：
1. **MIME 类型未校验**，`getAllowedTypes()` 形同虚设；
2. 文件扩展名来自 `getClientFilename()`，**客户端可控**；
3. 文件名拼接 `time()_random.ext` 后直接写入磁盘，攻击者可上传 `evil.php`、`evil.svg`（含 XSS）、`evil.html`（含 JS）等。

**利用场景**：
- 若 web 服务器（nginx/apache）配置错误，将 `public/assets/mattoid/store/*.php` 解析为 PHP → **RCE**；
- 上传 `.svg` 含 `<script>` → 通过 `<img src="...">` 引用时不触发，但通过 `<embed>` 或直接打开 URL 可触发 stored XSS；
- 上传 `.html` → 利用浏览器渲染做钓鱼 / cookie 窃取。

**修复**：
```php
public function assertValid(array $attributes){
    $this->laravelValidator = $this->makeValidator($attributes);
    $this->assertFileRequired($attributes['file']);
    $this->assertFileMimes($attributes['file']);           // 恢复
    $this->assertFileSize($attributes['file']);
}
```
并在 `StoreUploader::upload` 中**强制扩展名白名单**：
```php
$allowed = ['png','jpg','jpeg','webm'];
$ext = strtolower(pathinfo($file->getClientFilename(), PATHINFO_EXTENSION));
if (!in_array($ext, $allowed, true)) {
    throw new ValidationException(['message' => 'invalid-extension']);
}
```

---

### 🟠 V-03：管理员权限对游客开放（P1）

**文件**：`js/src/admin/index.tsx:21-31`

```tsx
.registerPermission({
  permission: 'mattoid-store.group-moderate',
  allowGuest: true,            // ⚠ 管理权限允许游客
}, 'moderate')
```

**风险**：在 Flarum 的 ACP（管理后台 - 权限）页面，`mattoid-store.group-moderate` 这条权限可被设置为"游客可用"。若管理员误勾，**未登录用户即可调用商品 CRUD、删除商品等 moderate API**。

**修复**：`allowGuest: false`。

---

### 🟠 V-04：购买流程缺失数据库事务（P1）

**文件**：`src/Controller/BuyGoodsController.php` 整体

**风险**：购买流程包含 6 步关键写操作：
1. 创建 cart（status=0）
2. 扣减 store.stock
3. 扣减 user.money
4. 写 MoneyHistory
5. 商品插件 `After::after()`
6. 更新 cart.status=1

**6 步无 DB 事务**，依赖事件链 + 乐观锁 + try/catch 做"补偿型"回滚：
- 步骤 3 失败可触发 stockAdd 回滚步骤 2；
- 步骤 5 失败可触发 StoreBuyFailEvent 回滚步骤 1、2、3；
- 但**步骤 4 失败时未回滚 1、2、3**（MoneyHistory 抛错会冒泡到 controller，但前面已扣钱扣库存）；
- **步骤 6 失败也无补偿**（save 失败时 cart.status 仍为 0，用户钱已扣但购物车显示未支付）。

**利用场景**：
- 并发竞态下，乐观锁可能短时间内仍允许两次扣费 → 多笔订单成功但只扣一次余额；
- After 中调用了远端 API 抛 `\Error`（非 Exception），`catch (Mockery\Exception)` 漏接 → 已扣钱但未发货。

**修复**：用 `DB::transaction(function () { ... })` 包裹整个 `data()` 方法体；或迁移到 BalanceManager（对照仓库已做）。

---

### 🟠 V-05：异常 catch 类型错误（P1）

**文件**：`src/Controller/BuyGoodsController.php:26, 143`

```php
use Mockery\Exception;        // ⚠ Mockery 是单元测试 mock 库
...
try {
    if (!$after->after($actor, $store, $params)) {
        $this->events->dispatch(new StoreBuyFailEvent(...));
        throw new ValidationException([...]);
    }
} catch (Exception $e) {       // ⚠ 实际类型是 Mockery\Exception，永远不会被命中
    $this->events->dispatch(new StoreBuyFailEvent(...));
    throw new ValidationException([...]);
}
```

**风险**：`Mockery\Exception` 是测试 mock 库的异常，业务代码绝不会抛出。这意味着 `After::after()` 抛出任何**真实异常**（含 `\RuntimeException`、`\Throwable`）都**不会被 catch**，直接冒泡到 Flarum 全局错误处理：
- 用户余额已扣减（且 MoneyHistory 已记账）；
- 库存已扣减；
- cart.status 还是 0；
- **没有触发 StoreBuyFailEvent 回滚**；
- 用户看到 500 错误，但客观上"钱被扣了，没拿到货"。

**修复**：
```php
use Throwable;            // 或直接 catch (\Throwable $e)
...
} catch (\Throwable $e) {
    app('log')->error('store buy after failed', ['exception' => $e]);
    $this->events->dispatch(new StoreBuyFailEvent(...));
    throw new ValidationException([...]);
}
```

---

### 🟠 V-06：`UseGoodsController` 权限错配（P1）

**文件**：`src/Controller/UseGoodsController.php:48-56`

```php
if (!$actor->can('mattoid-store.group-moderate')) {     // ⚠ 应为 group-view
    throw new PermissionDeniedException();
}
$cart = StoreCartModel::query()->where('id', $id)->where('user_id', $actor->id)->first();
$enable = StoreExtend::getEnable($cart->code);          // ⚠ $cart 可能为 null
```

**风险**：
1. **权限错配**：要求 `group-moderate`（管理员），但操作的是 `user_id = actor->id` 自有 cart，普通用户根本无法调用此 API → **使用商品功能对普通用户瘫痪**。
2. **NPE**：若 cart 不存在或 user_id 不匹配，`$cart` 为 `null`，下一行 `$cart->code` 触发 `Error: Attempt to read property "code" on null`。

**修复**：
```php
if (!$actor->can('mattoid-store.group-view')) {
    throw new PermissionDeniedException();
}
$cart = StoreCartModel::query()->where('id', $id)->where('user_id', $actor->id)->first();
if (!$cart) {
    throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.cart-not-found')]);
}
```

---

### 🟠 V-07：管理表单 Mass-Assignment（P1）

**文件**：`src/Controller/PostStoreController.php` / `PutStoreController.php`

```php
$params = $parseBody;
// ... 设置 stock/discount_price/pop_up/class_name ...
$params = ObjectsUtil::removeEmptySql($params);
StoreModel::query()->insert($params);
// 或 update($params)
```

**风险**：`$parseBody` 全字段（driveByte 客户端 JSON 提交）经 `removeEmptySql` 转下划线后**直接作为 SQL insert/update 的字段集**：
- 攻击者可注入 `created_at: '1970-01-01'`、`updated_at: ...`；
- 可写入未列入 UI 表单的字段（如未来新增字段）；
- 虽然 Eloquent 的 `insert/update` 仍走参数化绑定（不触发 SQLi），但**越权写入**属性是真实风险。

**特别危险点**：`PostStoreController` 路径中虽然有 `$parseBody['stock']==0 → -99` 的改写，但攻击者可以传 `stock: 99999`（管理员意图无限库存却传巨大正数）→ 实际上不算严重，但若管理员**只想给某用户折扣**：当前 schema 无 `user_id` 约束，问题不大。

**修复**：白名单：
```php
$allowed = ['code','title','desc','price','stock','discount','discount_limit','discount_limit_unit','type','outtime','icon','hide','repeat','status','auto_deduction'];
$params = array_intersect_key($parseBody, array_flip($allowed));
```
或在 Model 中定义 `$fillable` 后用 Eloquent `create()/save()` 替代 `query()->insert()`。

---

### 🟠 V-08：PutStoreController 空指针（P1）

**文件**：`src/Controller/PutStoreController.php`

```php
$goods = StoreGoodsModel::query()->where('code', $parseBody['code'])->first();
if (!$goods && $parseBody['status'] == 1) {
    throw new ValidationException([...]);
}
// ⚠ 若 $goods 为 null 但 status != 1，则跳过 throw，但下方仍访问 $goods->pop_up
$params['pop_up'] = $goods->pop_up;
$params['class_name'] = $goods->class_name;
```

**风险**：调用方传 `status=0` 且 code 已被禁用时，触发 NPE。属于业务异常，可被构造调用。

**修复**：
```php
if (!$goods) {
    throw new ValidationException([...]);
}
```

---

### 🟡 V-09：缓存锁在多机部署下失效（P2）

**文件**：`src/Controller/BuyGoodsController.php:69-70`

```php
$key = md5("{$params['id']}-{$actor->id}");
if (!$this->cache->add($key, time(), 5)) {
    throw new ValidationException([...]);
}
```

**风险**：使用 Laravel `Cache` 作为分布式锁。Flarum 默认 cache driver 是 `file`：
- 单机：OK；
- 多 PHP-FPM worker：可能因 file lock 不严而失效；
- 多机：**完全失效**（每机独立 file cache）；

**修复**：要求宿主配置 Redis cache driver 后才安全；或使用数据库唯一索引做幂等键。

---

### 🟡 V-10：库存 increment/decrement 非原子（P2）

**文件**：`src/Listeners/StoreStockSubListeners.php:30-39`

```php
$store = StoreModel::query()->where('id', $event->store->id)->first();
if ($store->stock != -99) {
    if ($store->stock < 1) {
        throw new ValidationException([...]);
    }
    $store->where('id', $store->id)->decrement('stock');
}
```

**风险**：先 select 再 decrement，select 与 decrement 之间无锁，**并发超卖**风险。例如：
- 库存为 1；
- 两请求同时进入，都读到 stock=1；
- 都判断 ≥ 1 通过；
- 都 decrement，最终 stock=-1。

**修复**：
```php
$updated = StoreModel::query()
    ->where('id', $store->id)
    ->where(function ($q) { $q->where('stock', '>', 0)->orWhere('stock', '=', -99); })
    ->decrement('stock');
if ($updated === 0) {
    throw new ValidationException([...]);
}
// -99 时 decrement 也会让无限库存"减 1"，需额外保护
```
更稳妥的做法是用 `DB::raw('stock = CASE WHEN stock = -99 THEN -99 ELSE stock - 1 END')`。

---

### 🟡 V-11：DeleteStoreController 未级联清理（P2）

**文件**：`src/Controller/DeleteStoreController.php:47`

```php
StoreModel::query()->where('id', $parseBody['id'])->delete();
```

**风险**：删除商品后 `store_cart` 历史保留（这是合理的，便于审计），但：
- `store_cart.store_id` 指向已删除的 store_id；
- 已购用户的 cart 显示空白 title/desc（因为 `StoreCartModel` 没有快照那么完整的数据）；
- 用户使用 / 续费时会因找不到 store 而异常。

实际上 cart 表已经快照了 `title/price/code/pay_amt`，所以 UI 上不会空白，但**自动续费** `GoodsInvalidCommand::autoDeduction()` 中：
```php
if ($store->status != 1) { throw ...; }
```
若 store 已删除，`storeMap[$cart->store_id]` 会 undefined → 在对照仓库 B 已加 `?? null + continue` 防御，**A 还没修**。

**修复**：参考 B 项目，加 `null` 防御 + 软删除（`deleted_at`）。

---

### 🟡 V-12：discount_price 精度丢失（P2）

**文件**：`migrations/2024_06_25_000001_create_store_table.php`

```php
$table->integer('discount_price');     // ⚠ 应为 decimal(10,2)
```

vs `PostStoreController.php`：
```php
$discountPrice = bcdiv(bcmul($price, $discount), 100, 2);   // 保留 2 位小数
$params['discount_price'] = $discountPrice;
```

**风险**：`bcdiv(...,2)` 返回 `"9.99"` 写入 `int` 列被强转为 `9` → **折扣价丢失小数**。

**修复**：迁移补丁 `ALTER TABLE store MODIFY discount_price decimal(10,2)`。

---

### 🟡 V-13：`discount_limit_unit` 通过 `Carbon::modify` 拼接（P2）

**文件**：`src/Controller/BuyGoodsController.php:105`

```php
$endTime = Carbon::parse($store->updated_at)
    ->tz($this->storeTimezone)
    ->modify('+' . $store->discount_limit . ' ' . $store->discount_limit_unit)
    ->getTimestamp();
```

**风险**：`discount_limit_unit` 来自管理员可填字段（前端是 Select 4 选 1，但后端无强校验）。
若管理员账户被攻陷或前端可绕过，可传入任意 modifier 字符串导致 `DateTime::modify` 抛 `Exception`，间接 DoS。
风险面较小（需要 moderate 权限），但属于"信任边界外的字符串拼接"。

**修复**：在 `PostStoreController` / `PutStoreController` 中白名单校验：
```php
if (!in_array($parseBody['discount_limit_unit'], ['days','hour','minute','second'], true)) {
    throw new ValidationException([...]);
}
```

---

### 🟡 V-14：错别字 / 路由名暴露内部约定（P2）

**文件**：`extend.php:45`、`locale/*.yml`

```php
->route('/store', 'mattoid-store.forum.tital')      // ⚠ tital 拼写错误
```

`tital` 应为 `title`。i18n key 也跟着错。
> 不算安全漏洞，但说明 review 不充分。

---

### 🟡 V-15：缓存 key 仅由 cart->store_id 决定，第三方插件可能冲突（P2）

**文件**：`src/Console/Command/GoodsInvalidCommand.php:106`

```php
$key = md5("{$cart->store_id}-{$cart->user_id}");
```

与 `BuyGoodsController` 的 `md5("{$params['id']}-{$actor->id}")` 共用同一命名空间。**若 store.id 和 cart.id 在某用户下相等，自动续费与手动购买会互相阻塞 5 秒**。

**修复**：
```php
$key = "mattoid-store:auto-deduction:{$cart->store_id}:{$cart->user_id}";
// 与购买锁分离 namespace
```

---

### 🟡 V-16：MoneyHistory 软依赖通过 class_exists 检查（P2）

**文件**：`src/Controller/BuyGoodsController.php:129`、`src/Console/Command/GoodsInvalidCommand.php:138`、`src/Listeners/StoreBuyFailListeners.php:44`

```php
if (class_exists('Mattoid\MoneyHistory\Event\MoneyHistoryEvent')) {
    $this->events->dispatch(new \Mattoid\MoneyHistory\Event\MoneyHistoryEvent(...));
}
```

**风险**：
1. 若 `MoneyHistoryEvent` 类签名变更（参数顺序/类型），扩展更新后会运行时报错；
2. `class_exists` 每次调用有 autoload 开销；
3. 监听器一旦异常，会冒泡到事务外，但没有 catch。

**修复**：声明为 `composer.json: suggest` 软依赖；运行时用 try/catch 包裹 event dispatch；或迁移到 `AntoineFr\Money\BalanceManager`（参考 02-project-comparison.md）。

---

### 🟡 V-17：`store_goods_icon.url` 中存储绝对 URL（P2）

**文件**：`src/Controller/StoreUpdateIconController.php:58`

```php
$icon->url = $this->uploader->upload($file);   // 返回绝对 URL
```

`StoreUploader::upload` 返回 `$this->uploadDir->url($filename)`，即 `https://forum.example.com/assets/mattoid/store/xxx.png`。

**风险**：
- 数据库存绝对 URL → **域名变更 / 跨环境迁移**时全部失效；
- 备份还原至另一域名也会指向旧域名。

**修复**：存相对路径（如 `mattoid/store/xxx.png`），前端拼接：
```php
$icon->path = "mattoid/store/{$filename}";
```
serializer 中按需返回绝对 URL。

---

## 综合风险矩阵

| 风险 | 影响 | 利用难度 | 修复难度 | 优先级 |
|------|------|---------|---------|--------|
| V-01 上传无权限 | RCE / 钓鱼 / DoS | 低（已登录即可） | 低（加一行权限检查） | P0 |
| V-02 MIME 校验失效 | RCE / XSS | 低 | 低（取消注释 + 白名单） | P0 |
| V-04 无 DB 事务 | 资金 / 库存不一致 | 中（并发竞态） | 中（参考对照仓库的 BalanceManager） | P1 |
| V-05 catch 类型错 | 静默失败、资金不一致 | 中 | 极低（改一行 catch） | P1 |
| V-06 Use 权限错配 + NPE | 功能瘫痪 + 500 错误 | 低 | 低（改两行） | P1 |
| V-03 admin 权限允许游客 | 越权 CRUD | 极低 | 极低 | P1 |
| V-07 mass-assignment | 越权写字段 | 中 | 低（白名单） | P1 |
| V-08 PutStore NPE | 500 错误 | 低 | 低 | P1 |
| V-09 cache 锁多机失效 | 重复购买 | 高 | 中（要求 Redis） | P2 |
| V-10 库存非原子 | 超卖 | 高 | 中 | P2 |
| V-11 删除未级联 | 数据孤儿 | – | 中（软删除） | P2 |
| V-12 精度丢失 | 折扣小数损失 | – | 低（migration） | P2 |
| V-13 modify 拼接 | DoS | 高 | 极低 | P2 |
| V-14 拼写 tital | – | – | 低 | P2 |
| V-15 缓存 key 冲突 | 互锁 | 高 | 极低 | P2 |
| V-16 软依赖检查 | 维护成本 | – | 低（改为硬依赖） | P2 |
| V-17 绝对 URL | 迁移困难 | – | 中 | P2 |

---

## 建议立即修复（P0 + P1）的 PR 清单

### PR-A：上传安全加固（修 V-01 + V-02）

```diff
// src/Controller/StoreUpdateIconController.php
+ use Flarum\User\Exception\PermissionDeniedException;
+ use Flarum\Http\RequestUtil;

protected function data(ServerRequestInterface $request, Document $document){
+   $actor = RequestUtil::getActor($request);
+   if (!$actor->can('mattoid-store.group-moderate')) {
+       throw new PermissionDeniedException();
+   }

    $result = [];
    $file = Arr::get($request->getUploadedFiles(), 'file');
    $this->validator->assertValid(['file' => $file]);
    ...
}
```

```diff
// src/Upload/StoreValidator.php
public function assertValid(array $attributes){
    $this->laravelValidator = $this->makeValidator($attributes);
    $this->assertFileRequired($attributes['file']);
-   //  $this->assertFileMimes($attributes['file']);
+   $this->assertFileMimes($attributes['file']);
    $this->assertFileSize($attributes['file']);
}
```

```diff
// src/Upload/StoreUploader.php
public function upload(UploadedFileInterface $file) {
-   $ext = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
+   $ext = strtolower(pathinfo($file->getClientFilename(), PATHINFO_EXTENSION));
+   if (!in_array($ext, ['png','jpg','jpeg','webm'], true)) {
+       throw new \Flarum\Foundation\ValidationException(['message' => 'invalid-extension']);
+   }
    $filename = time()."_".Str::random().'.'.$ext;
    ...
}
```

### PR-B：异常 + 权限 + NPE 修复（修 V-05 + V-06 + V-08）

参见各漏洞条目中的修复建议。

### PR-C：admin permission 收紧（修 V-03）

```diff
// js/src/admin/index.tsx
  .registerPermission({
    permission: 'mattoid-store.group-moderate',
-   allowGuest: true
+   allowGuest: false
  }, 'moderate')
```

### PR-D：迁移到 BalanceManager（修 V-04 同时简化 V-09/V-10/V-16）

参见 02-project-comparison.md 的 PR-2 同步策略。

---

> **下一步**：参见 [03-optimization-roadmap.md](./03-optimization-roadmap.md) 把以上修复编排到迭代路线。
