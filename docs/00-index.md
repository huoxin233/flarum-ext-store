# Flarum-ext-store 项目分析文档索引

本目录汇总了对 `mattoid/flarum-ext-store` 当前仓库（路径：`/Users/liufei/work/mattoid/php/flarum-ext/flarum-ext-store/`）的全面分析。

> 分析时间：2026-05-16
> 当前分支：master
> 最近提交：`1d23336 Merge pull request #1 from huoxin233/huoxin233-patch-1`

---

## 文档清单

| 序号 | 文档 | 内容简介 |
|------|------|----------|
| 01 | [01-project-analysis.md](./01-project-analysis.md) | **项目结构与功能详解**：目录树、模块职责、API、数据库表、关键业务流程调用链、技术栈、扩展机制 |
| 02 | [02-project-comparison.md](./02-project-comparison.md) | **与对照仓库的差异对比**：当前 `flarum-ext/flarum-ext-store` 与 `flarum-ext-store` 在资金处理、类型系统、Bug 修复、代码规范上的具体差异 |
| 03 | [03-optimization-roadmap.md](./03-optimization-roadmap.md) | **优化路径**：按 P0/P1/P2 优先级编排的演进计划与落地步骤 |
| 04 | [04-security-audit.md](./04-security-audit.md) | **安全审计**：发现的 17 类漏洞与威胁，含证据、风险等级与修复建议 |
| 05 | [05-improvement-opportunities.md](./05-improvement-opportunities.md) | **优化空间分析**：从架构、性能、可维护性、测试、国际化等维度的改进点 |
| 06 | [06-plugin-registration-redesign.md](./06-plugin-registration-redesign.md) | **商品插件注入机制重构**：当前 onEnable 写 DB 的脆弱性分析 + 5 种替代方案对比 + 推荐路径 |
| - | [readme_cn.md](./readme_cn.md) | 原项目中文使用说明（已存在） |

---

## 阅读顺序建议

1. **快速了解项目** → 先看 `01-project-analysis.md`
2. **决策是否合并 / 同步对照仓库** → 看 `02-project-comparison.md`
3. **运维 / 安全负责人** → 优先看 `04-security-audit.md`
4. **后续迭代规划** → 综合 `03-optimization-roadmap.md` + `05-improvement-opportunities.md`
5. **商品插件扩展机制重构** → 单独看 `06-plugin-registration-redesign.md`

---

## 核心结论一览

- **项目定位**：Flarum 论坛的"积分商店"框架，自身不内置商品，所有商品类型由第三方插件通过 `StoreExtend` 注册（已知插件：邀请码、签到卡、自动签到卡）。
- **架构层次**：极薄，仅 Controller + Listener + Model，未分 Service/Repository/DTO，业务逻辑分散在 Controller 与 Listener。
- **资金处理**：当前仓库使用**内联** `$user->money -= $price` + 乐观锁；对照仓库已迁移到 `AntoineFr\Money\Service\BalanceManager`。
- **高危漏洞**：
  - `StoreUpdateIconController` **完全无权限校验** + 文件 MIME 校验**被注释**（仅按扩展名）→ 任意已登录用户可上传任意类型 ≤4MB 文件。
  - 购买流程**无 DB 事务**，依赖事件链 + 乐观锁，存在 partial failure 风险。
  - `mattoid-store.group-moderate` 在前端 `allowGuest: true`（管理员权限对游客开放）。
  - `UseGoodsController` 权限校验设错为 `group-moderate`，且 `$cart` 未做 null 检查（NPE）。
- **关键改进方向**：
  1. 引入 `BalanceManager`（同步对照仓库）；
  2. 修复上传权限与 MIME 校验；
  3. 引入 DB 事务包裹购买流程；
  4. 补齐 i18n、类型定义、单元测试；
  5. 拆 Service 层，迁出散落在 Controller 的业务逻辑；
  6. **重构商品插件注入机制**：当前 `onEnable` 写 DB 的方式脆弱（DB 写入失败 → 商品永久不可用），推荐改为运行时聚合 + reconcile 自愈（详见 06）。
