# 后端一次性完全开发 - 实施计划

> 项目：轻量网盘资源搜索引擎  |  技术栈：原生 PHP 模板 + ThinkPHP8 + MySQL8 + Redis7
> 依据：[PRD](file:///workspace/docs/PRD-网盘资源搜索引擎.md) + [架构设计](file:///workspace/docs/架构设计文档.md) + [数据库设计](file:///workspace/docs/数据库设计文档.md)
> 范围：Service / Controller / Command / Crawler / Validate / 队列 Job / 功能性视图模板

---

## 一、Summary（摘要）

在已就位的项目骨架基础上，一次性完成后端全部业务逻辑开发，使项目达到"可端到端跑通核心流程"的状态。包含：9 个 Service 完整实现、22 个控制器（10 前台 + 12 后台）完整接口、5 个命令行实现、15 个爬虫采集器（通用框架 + 示例实现）、验证器、队列 Job 类、功能性视图模板（最小化样式，确保可渲染可交互）。

**不含**：UI 美化设计（后续单独迭代）、真实爬虫反爬对抗（实现 URL 匹配 + HTML 解析框架，示例 2 家）、PHPUnit 测试、Docker。

---

## 二、Current State Analysis（当前状态）

### 2.1 已完成（无需改动）
- **基础设施**：BaseController / BaseAdminController / ExceptionHandle / Request / AppService / middleware.php
- **全局中间件**：RequestId / LoadConfig / GlobalLog
- **应用中间件**：UserAuth / AdminAuth / RateLimit（核心逻辑已实现）
- **Model 层**：26 个完整实现
- **扩展类库**：CaihongPay（完整）/ SmtpMailer（完整）/ MysqlFulltextDriver（完整）/ DfaFilter（完整）/ HashHelper / SignatureHelper / EncryptHelper
- **DTO**：SearchQuery / SearchResult（已内嵌于 SearchDriverInterface.php）
- **Service（3 个已完整）**：RateLimiter / ConfigService / AdminLogService
- **命令**：Install（完整）
- **后台控制器**：Publics（登录完整）
- **配置 / 路由 / 视图布局骨架 / 数据库脚本**：已就位

### 2.2 待开发（本计划范围）
- 9 个 Service（桩 → 完整）
- 22 个控制器（桩 → 完整）
- 5 个命令行（桩 → 完整）
- 15 个爬虫采集器（桩 → 框架 + 示例）
- 队列 Job 类（缺失）
- 验证器（缺失）
- 功能性视图模板（占位 → 可交互）
- VisitorLog 中间件（确认实现）

---

## 三、Proposed Changes（变更清单）

### 阶段 A：Service 层完整实现（9 个）

#### A1. CreditService（积分服务）
文件：[app/common/service/CreditService.php](file:///workspace/app/common/service/CreditService.php)
- `consume()`：Db::transaction 内 → UserCredit 行锁（lock(true)）→ 余额校验 → 乐观锁 update（where user_id + version）→ 写 CreditLog（balance_after）→ 失败抛并发异常
- `recharge()`：事务内更新 balance + total_recharge，写 CreditLog
- 新增 `getBalance(int $userId): int`：读 UserCredit，缓存 5 分钟
- 新增 `signIn(int $userId): array`：查 SignInRecord 今日是否签到 → 连续天数计算 → recharge 签到积分 → 写签到记录
- 异常：积分不足抛 CreditNotEnoughException

#### A2. SearchService（搜索服务）
文件：[app/common/service/SearchService.php](file:///workspace/app/common/service/SearchService.php)
- `search()`：缓存命中检查（search:md5，TTL 300）→ driver 查询 → 写 SearchLog → 异步 ZINCRBY hot:keywords:{date} → 写缓存
- 新增 `hotKeywords(int $limit = 10): array`：Redis ZREVRANGE hot:keywords:{date} 0 limit WITHSCORES，缓存 5 分钟
- 新增 `archiveHotKeywords(): void`：归档前日 Redis 数据到 HotKeyword 表（供 ad:agg 命令调用）

#### A3. PayService（支付服务）
文件：[app/common/service/PayService.php](file:///workspace/app/common/service/PayService.php)
- `createOrder()`：校验 CreditPackage 有效 → 生成订单号（HashHelper::orderNo）→ 创建 Order（status=0, expire_at=now+30min）→ 写 PaymentLog event=create → 调 CaihongPay::buildPayUrl 返回跳转 URL
- `handleNotify()`：验签 → 幂等检查（订单已支付返回 success）→ 事务（更新 Order status=1 + pay_at + trade_no + CreditService::recharge + 写 CreditLog）→ 写 PaymentLog event=notify → 返回 success
- `handleReturn()`：同步跳转展示，查订单状态返回视图数据
- 新增 `closeExpiredOrders(): int`：扫描 status=0 且 expire_at<now 的订单，更新 status=3，写 PaymentLog event=close

#### A4. MailService（邮件服务）
文件：[app/common/service/MailService.php](file:///workspace/app/common/service/MailService.php)
- `sendVerifyCode()`：渲染模板（注册/登录/重置三种）→ 投递 MailJob 到 mail 队列
- 新增 `sendHtml(string $to, string $subject, string $body): void`：直接同步发送（管理后台用）
- 模板渲染：内联 HTML 模板字符串（避免依赖文件模板）
- 队列消费时调用 Pansou\Mail\SmtpMailer::send，失败重试 3 次指数退避

#### A5. VerifyCodeService（验证码服务）
文件：[app/common/service/VerifyCodeService.php](file:///workspace/app/common/service/VerifyCodeService.php)
- `send()`：限流（邮箱 60s/次 + IP 10min/5 次，调 RateLimiter）→ 生成 6 位数字 → Redis 存 verify:email:{type}:{email} TTL 300 + verify:try:{type}:{email}=5 → 入库 EmailVerify → 投递 MailService
- `verify()`：Redis 取 code 比对 → 错误则尝试次数-1，归零删除 → 正确则删除 key + 标记 EmailVerify.used=1
- 依赖 ConfigService 读取 verify_code 配置

#### A6. CrawlerService（采集服务）
文件：[app/common/service/CrawlerService.php](file:///workspace/app/common/service/CrawlerService.php)
- `dispatch()`：校验 task enabled → 投递 CrawlJob 到 crawl 队列 → 更新 next_run_at = now + frequency
- `execute()`：通过 PanSource.crawler_class 反射实例化采集器 → crawl() → SensitiveFilter 过滤 → HashHelper::urlHash 去重（ResourceLink.url_hash UNIQUE）→ 入库 ResourceLink + 关联 Resource → 写 CrawlLog
- 失败重试 3 次（通过队列 attempts 配置）
- 新增 `dispatchDueTasks(): int`：扫描 crawl_tasks enabled=1 且 next_run_at<=now，逐个 dispatch

#### A7. AdService（广告服务）
文件：[app/common/service/AdService.php](file:///workspace/app/common/service/AdService.php)
- `getPlacements()`：查 AdSlot by code → 查 AdPlacement status=1 且在时段内 → weight 加权随机抽取 N 个 → 缓存 60s
- `impression()`：Redis HINCRBY ad:imp:{date} {placementId} 1
- `click()`：Redis HINCRBY ad:click:{date} {placementId} 1，返回 link_url
- `aggregateDaily()`：读前日 Redis Hash → 写 AdStat（UNIQUE placement_id+stat_date）→ 清理 Redis

#### A8. SensitiveFilter（敏感词过滤）
文件：[app/common/service/SensitiveFilter.php](file:///workspace/app/common/service/SensitiveFilter.php)
- `check()`：加载 SensitiveWord 表词条到 DfaFilter（静态缓存，TTL 10 分钟）→ 调 DfaFilter::check
- 新增 `replace(string $text): string`：调 DfaFilter::replace
- 静态属性缓存 DfaFilter 实例，避免每次查库

#### A9. StatService（统计服务）
文件：[app/common/service/StatService.php](file:///workspace/app/common/service/StatService.php)
- `dashboard()`：聚合资源总数/今日新增/待审数 + 用户总数/今日新增 + 今日搜索量/热搜词 TOP10 + 今日订单数/收入 + 采集任务状态 + 广告展示/点击数
- 缓存 5 分钟（stat:dashboard）

---

### 阶段 B：前台控制器完整实现（10 个）

#### B1. Index（首页）
文件：[app/index/controller/Index.php](file:///workspace/app/index/controller/Index.php)
- `index()`：热搜词 TOP10（SearchService）+ 首页 Banner 广告（AdService）+ 最新资源列表 → 渲染首页

#### B2. Search（搜索）
文件：[app/index/controller/Search.php](file:///workspace/app/index/controller/Search.php)
- `index()`：解析 query 参数（q/type/sources/size/time/cursor）→ 构造 SearchQuery → SearchService::search → 渲染结果页（含广告位）
- `hot()`：返回热搜词 JSON

#### B3. Resource（资源详情）
文件：[app/index/controller/Resource.php](file:///workspace/app/index/controller/Resource.php)
- `detail()`：查 Resource + ResourceLink（隐藏完整链接，仅展示网盘来源）+ 相关推荐 + 广告位 → 渲染详情页
- `viewLink()`：登录校验 → 积分校验（CreditService::consume）→ 返回完整链接 + 提取码 + 写 ResourceReport 记录查看
- `report()`：资源失效举报入库

#### B4. Auth（注册登录）
文件：[app/index/controller/Auth.php](file:///workspace/app/index/controller/Auth.php)
- `login()` / `register()`：渲染页面
- `sendCode()`：校验邮箱格式 → VerifyCodeService::send
- `doLogin()`：校验验证码 → 查/建用户 → 写 Session → 写 UserLoginLog
- `doRegister()`：校验验证码 → 创建用户 + 赠送注册积分（CreditService::recharge）→ 写 Session
- `logout()`：清除 Session

#### B5. User（用户中心）
文件：[app/index/controller/User.php](file:///workspace/app/index/controller/User.php)
- `index()`：个人资料 + 积分余额
- `credits()`：积分流水列表（CreditLog 分页）
- `orders()`：订单列表
- `signIn()`：每日签到（CreditService::signIn）
- `profile()`：资料编辑（昵称/头像）

#### B6. Submit（资源提交）
文件：[app/index/controller/Submit.php](file:///workspace/app/index/controller/Submit.php)
- `index()`：渲染提交页（含网盘源列表）
- `create()`：校验参数 → SensitiveFilter → 校验 URL（PanSource.url_pattern）→ 入库 Submission status=0
- `myList()`：当前用户提交记录列表

#### B7. Order（订单）
文件：[app/index/controller/Order.php](file:///workspace/app/index/controller/Order.php)
- `packages()`：套餐列表（CreditPackage status=1）
- `myList()`：当前用户订单列表
- `detail()`：订单详情

#### B8. Pay（支付）
文件：[app/index/controller/Pay.php](file:///workspace/app/index/controller/Pay.php)
- `create()`：PayService::createOrder → 跳转彩虹易支付收银台
- `notify()`：PayService::handleNotify（异步回调，免鉴权）
- `return()`：PayService::handleReturn（同步跳转展示）

#### B9. Ad（广告）
文件：[app/index/controller/Ad.php](file:///app/index/controller/Ad.php)
- `click()`：AdService::click → 302 跳转 link_url

#### B10. Ajax（公共异步接口）
文件：[app/index/controller/Ajax.php](file:///workspace/app/index/controller/Ajax.php)
- `reportResource()`：资源失效举报
- `adImpression()`：广告曝光上报（AdService::impression）

---

### 阶段 C：后台控制器完整实现（12 个）

> 所有后台控制器继承 BaseAdminController，CRUD 操作记录 AdminLog，列表分页 + 搜索筛选。

#### C1. Index（仪表盘）
文件：[app/admin/controller/Index.php](file:///workspace/app/admin/controller/Index.php)
- `index()`：StatService::dashboard → 渲染仪表盘

#### C2. Resource（资源管理）
文件：[app/admin/controller/Resource.php](file:///workspace/app/admin/controller/Resource.php)
- `index()`：列表（搜索/筛选/分页）
- `add()` / `edit()`：表单 + 保存
- `delete()`：软删除
- `markInvalid()`：标记失效
- `batch()`：批量操作（删除/转移类型）

#### C3. PanSource（网盘源管理）
文件：[app/admin/controller/PanSource.php](file:///workspace/app/admin/controller/PanSource.php)
- CRUD + `toggle()`：启用/禁用

#### C4. Crawl（采集任务）
文件：[app/admin/controller/Crawl.php](file:///workspace/app/admin/controller/Crawl.php)
- CRUD + `logs()`：采集日志 + `trigger()`：手动触发采集

#### C5. Submission（用户提交审核）
文件：[app/admin/controller/Submission.php](file:///workspace/app/admin/controller/Submission.php)
- `index()`：待审列表
- `approve()`：通过 → 资源正式入库 + 奖励提交者积分 + 站内通知
- `reject()`：驳回 → 记录原因 + 站内通知

#### C6. User（用户管理）
文件：[app/admin/controller/User.php](file:///workspace/app/admin/controller/User.php)
- `index()`：用户列表
- `detail()`：用户详情（资料/积分/订单/提交/登录日志）
- `adjustCredit()`：调整积分（CreditService::recharge/consume）
- `toggle()`：封禁/解封

#### C7. Order（订单管理）
文件：[app/admin/controller/Order.php](file:///workspace/app/admin/controller/Order.php)
- `index()`：订单列表（状态筛选）
- `detail()`：订单详情 + 支付日志
- `manualComplete()`：手动补单（支付异常时）
- `refund()`：退款处理

#### C8. Package（积分套餐）
文件：[app/admin/controller/Package.php](file:///workspace/app/admin/controller/Package.php)
- CRUD + `toggle()`：上下架

#### C9. Ad（广告管理）
文件：[app/admin/controller/Ad.php](file:///workspace/app/admin/controller/Ad.php)
- `slots()`：广告位列表
- `placements()`：投放列表
- `create()` / `edit()`：投放表单
- `stats()`：广告统计（曝光/点击/CTR）
- `toggle()`：上下线

#### C10. Config（系统配置）
文件：[app/admin/controller/Config.php](file:///workspace/app/admin/controller/Config.php)
- `index()`：配置项分组展示
- `save()`：保存配置（ConfigService::set）
- 分组：smtp / payment / site / credit / security

#### C11. Sensitive（敏感词管理）
文件：[app/admin/controller/Sensitive.php](file:///workspace/app/admin/controller/Sensitive.php)
- CRUD + `import()`：批量导入

#### C12. Log（日志查看）
文件：[app/admin/controller/Log.php](file:///workspace/app/admin/controller/Log.php)
- `admin()`：管理员操作日志
- `userLogin()`：用户登录日志
- `payment()`：支付日志
- `exception()`：异常访问日志（读 runtime/log）

---

### 阶段 D：命令行完整实现（5 个）

#### D1. CrawlDispatch
文件：[app/command/CrawlDispatch.php](file:///workspace/app/command/CrawlDispatch.php)
- `execute()`：CrawlerService::dispatchDueTasks → 输出分发数量

#### D2. CrawlConsume
文件：[app/command/CrawlConsume.php](file:///workspace/app/command/CrawlConsume.php)
- `execute()`：启动 think-queue Worker 消费 crawl 通道 → 调 CrawlerService::execute
- 配置：--channel=crawl --tries=3 --sleep=3

#### D3. MailConsume
文件：[app/command/MailConsume.php](file:///workspace/app/command/MailConsume.php)
- `execute()`：启动 queue Worker 消费 mail 通道 → 调 MailService 实际发送
- 配置：--channel=mail --tries=3

#### D4. AdStatAgg
文件：[app/command/AdStatAgg.php](file:///workspace/app/command/AdStatAgg.php)
- `execute()`：AdService::aggregateDaily + SearchService::archiveHotKeywords

#### D5. OrderClose
文件：[app/command/OrderClose.php](file:///workspace/app/command/OrderClose.php)
- `execute()`：PayService::closeExpiredOrders → 输出关闭数量

---

### 阶段 E：爬虫采集器实现（15 个）

#### E1. 通用框架完善
文件：[app/common/crawler/AbstractCrawler.php](file:///workspace/app/common/crawler/AbstractCrawler.php)
- 完善 `crawl()` 默认实现：读取 task 配置 → httpClient 请求 → parseSharePage → 返回 ResourceItem[]
- 新增 ResourceItem DTO 类（app/common/crawler/ResourceItem.php）：title/share_url/extract_code/file_size/cover/intro

#### E2. 示例采集器完整实现（2 家）
- **LanzouCrawler**（蓝奏云）：URL 匹配（pan.lanzou.com）+ 分享页 HTML 解析（标题/大小）
- **FirefoxSendCrawler**（Firefox Send）：URL 匹配（send.firefox.com）+ 简单解析

#### E3. 其余 13 家采集器（框架实现）
每家实现：
- `validateUrl()`：基于 pan_sources.url_pattern 正则匹配
- `parseSharePage()`：基础 HTML 解析（提取 title 标签等通用信息）
- `crawl()`：继承 AbstractCrawler 默认实现

涉及文件：BaiduCrawler / AliyunCrawler / QuarkCrawler / XunleiCrawler / Pan123Crawler / Pan115Crawler / EcloudCrawler / YidongCrawler / WeiyunCrawler / UcCrawler / MangoCrawler / CowtransferCrawler / CookieCrawler

---

### 阶段 F：队列 Job 类（新增）

#### F1. MailJob
文件：`app/job/MailJob.php`
- 实现 think-queue ShouldQueue 接口
- `fire()`：调用 SmtpMailer::send，失败抛异常触发重试

#### F2. CrawlJob
文件：`app/job/CrawlJob.php`
- `fire()`：调用 CrawlerService::execute($taskId)

---

### 阶段 G：验证器（新增）

目录：`app/common/validate/`
- `AuthValidate`：sendCode（email/type）/ doLogin（email/code）/ doRegister（email/code）
- `SubmitValidate`：create（title/share_url/pan_source_id/resource_type）
- `ResourceValidate`：add/edit（title/resource_type）
- `OrderValidate`：manualComplete（order_id/trade_no）
- `AdValidate`：create/edit（slot_id/title/image_url/link_url/start_at/end_at/weight）
- `ConfigValidate`：save（group/configs）
- `UserValidate`：adjustCredit（user_id/amount/type/remark）
- `PackageValidate`：add/edit（name/price/credits）

---

### 阶段 H：功能性视图模板（最小化样式）

> 目标：控制器能渲染、表单能提交、列表能展示。不追求 UI 美化，使用最小化 CSS。

#### H1. 前台视图（app/index/view/）
- `index/index.html`：首页（搜索框 + 热搜词 + 最新资源 + Banner 广告位）
- `search/index.html`：搜索结果页（筛选栏 + 结果列表 + 分页 + 广告位）
- `resource/detail.html`：资源详情（信息 + 查看链接按钮 + 广告位）
- `auth/login.html` / `auth/register.html`：登录/注册页（邮箱 + 验证码）
- `user/index.html` / `user/credits.html` / `user/orders.html`：用户中心
- `submit/index.html` / `submit/my_list.html`：资源提交
- `order/packages.html` / `order/my_list.html` / `order/detail.html`：订单
- `pay/return.html`：支付返回页

#### H2. 后台视图（app/admin/view/）
- `index/index.html`：仪表盘（统计卡片 + 热搜词 + 趋势）
- `resource/index.html` / `resource/form.html`：资源管理
- `pan_source/index.html` / `pan_source/form.html`：网盘源管理
- `crawl/index.html` / `crawl/form.html` / `crawl/logs.html`：采集任务
- `submission/index.html`：提交审核
- `user/index.html` / `user/detail.html`：用户管理
- `order/index.html` / `order/detail.html`：订单管理
- `package/index.html` / `package/form.html`：套餐管理
- `ad/slots.html` / `ad/placements.html` / `ad/form.html` / `ad/stats.html`：广告管理
- `config/index.html`：系统配置
- `sensitive/index.html` / `sensitive/form.html`：敏感词管理
- `log/admin.html` / `log/user_login.html` / `log/payment.html`：日志查看

#### H3. 公共资源
- `public/static/css/main.css`：前台基础样式（扩充）
- `public/static/css/admin.css`：后台基础样式（新增，侧边栏 + 表格 + 表单）
- `public/static/js/common.js`：前台公共 JS（验证码倒计时 + Ajax 封装 + 广告点击上报）

---

### 阶段 I：补全 VisitorLog 中间件
文件：[app/index/middleware/VisitorLog.php](file:///workspace/app/index/middleware/VisitorLog.php)
- 记录访问日志到 Redis（异步落库），含 URL/IP/UA/Referer/user_id

---

## 四、Assumptions & Decisions（假设与决策）

1. **架构遵从**：严格遵循架构设计文档，原生 PHP 模板渲染，不引入前端框架
2. **爬虫务实范围**：15 家网盘真实采集涉及反爬/登录态/代理池，超出"后端开发"合理边界。本计划实现通用框架（URL 匹配 + HTML 解析）+ 2 家示例（蓝奏云/Firefox Send），其余 13 家实现 validateUrl + 基础解析，真实采集逻辑留 TODO 待后续按需补充
3. **视图最小化**：功能性模板，确保控制器可渲染、表单可提交、列表可展示。UI 美化（响应式/动画/图标）后续单独迭代
4. **零硬编码**：所有阈值/文案/路径走 config/pan.php 或 system_configs，敏感配置走 .env
5. **安全闭环**：所有写操作验证器校验 + CSRF Token + 敏感词过滤 + 限流
6. **事务保证**：积分扣减/支付回调/资源入库等关键操作使用 Db::transaction
7. **缓存策略**：搜索结果 5 分钟、热搜词 5 分钟、广告 1 分钟、仪表盘 5 分钟、系统配置 1 小时
8. **队列异步**：邮件发送、采集任务走队列，避免阻塞请求
9. **不写测试**：用户未要求，本阶段不写 PHPUnit
10. **不创建 Docker**：用户未要求

---

## 五、Verification Steps（验证步骤）

1. **PHP 语法**：`find app/ extend/ -name "*.php" -exec php -l {} \;` 零错误
2. **路由列表**：`php think route:list` 列出全部前台/后台/api 路由
3. **命令注册**：`php think list` 显示 6 个命令（install + 5 个新实现）
4. **核心流程端到端**（需 MySQL + Redis 环境）：
   - 首页访问 → 搜索 → 资源详情 → 注册登录 → 查看完整链接（扣积分）
   - 套餐选择 → 创建订单 → 支付回调 → 积分到账
   - 资源提交 → 后台审核 → 入库 + 奖励积分
   - 后台仪表盘 → 资源管理 → 用户管理 → 订单管理
5. **队列消费**：`php think mail:consume` / `php think crawl:consume` 能正常启动
6. **零硬编码**：`grep -rE "(127\.0\.0\.1|smtp\.qq\.com|password.*=.*['\"][a-zA-Z0-9]{6,}['\"])" app/ config/ extend/` 仅命中注释或 env 回退默认值
7. **安全检查**：写操作接口均有验证器 + CSRF 保护
8. **缓存命中**：重复搜索相同关键词，第二次耗时显著下降

---

## 六、执行顺序

按依赖关系分阶段执行：

```
阶段 A (Service) ──┬──> 阶段 B (前台 Controller) ──┐
                   ├──> 阶段 C (后台 Controller) ──┤
                   ├──> 阶段 D (Command) ──────────┤
                   └──> 阶段 E (Crawler) ──────────┤
                                                      ├──> 阶段 H (视图) ──> 验证
阶段 F (Job) ─────────────────────────────────────────┤
阶段 G (Validate) ────────────────────────────────────┤
阶段 I (VisitorLog) ──────────────────────────────────┘
```

- **阶段 A 优先**：Service 是 Controller/Command 的依赖
- **阶段 F/G/I 可并行**：与阶段 A 无强依赖
- **阶段 B/C 依赖 A**：Controller 调 Service
- **阶段 D 依赖 A**：Command 调 Service
- **阶段 E 依赖 A**：CrawlerService
- **阶段 H 最后**：视图依赖 Controller action 确定

预计涉及文件：约 80 个（9 Service + 22 Controller + 5 Command + 15 Crawler + 2 Job + 8 Validate + 40 视图模板 + 1 中间件 + 公共资源）。
