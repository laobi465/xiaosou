# 项目初始化 - 实施计划

> 项目：轻量网盘资源搜索引擎  |  技术栈：原生 PHP 模板 + ThinkPHP8 + MySQL8 + Redis7
> 依据：[架构设计文档](file:///workspace/docs/架构设计文档.md) + [数据库设计文档](file:///workspace/docs/数据库设计文档.md)
> 决策：遵架构文档（原生 PHP 模板渲染，不引入 Vue3）；范围=项目骨架 + 数据库脚本 + 安装命令

---

## 一、Summary（摘要）

将当前空仓库（仅含 README + docs）初始化为可直接上手开发的 ThinkPHP8 项目骨架。包含：依赖安装、完整目录结构、多环境配置、基类封装、全局中间件、22 个 Model、Service/Controller 桩代码、路由定义、视图布局、数据库 DDL/种子数据 SQL、`php think install` 一键安装命令。**不包含**业务逻辑实现（Service 方法体留 TODO）、Docker 配置、爬虫采集器实际逻辑。

---

## 二、Current State Analysis（当前状态分析）

- 工作区 `/workspace` 仅含：`README.md`（9 字节）、`docs/`（3 份设计文档）、`.agents/`、`.claude/`、`agent/`、`skills-lock.json`
- 无 `composer.json`，无 ThinkPHP 安装，无 `app/`、`config/`、`route/`、`public/`、`database/` 目录
- 环境可用：PHP 8.5.6-dev、Composer 2.9.7
- 已有设计文档作为唯一权威依据，目录结构与文件清单已在 [架构设计文档.md#L101-L292](file:///workspace/docs/架构设计文档.md#L101-L292) 完整列出
- DDL 与种子数据已在 [数据库设计文档.md](file:///workspace/docs/数据库设计文档.md) 完整给出，需落为 `database/install.sql` + `database/data.sql`

---

## 三、Proposed Changes（变更清单）

### 步骤 1：Composer 依赖与 ThinkPHP8 安装
- 创建 `composer.json`：
  - `topthink/framework`: ^8.0
  - `topthink/think-queue`: ^5.0（Redis 驱动）
  - `topthink/think-migration`: ^3.0（迁移工具）
  - `phpmailer/phpmailer`: ^6.9
  - `guzzlehttp/guzzle`: ^7.9（爬虫 HTTP 客户端）
  - `ext-pdo`, `ext-redis`, `ext-json`, `ext-mbstring`, `ext-openssl` 扩展声明
- 执行 `composer install` 生成 `vendor/` 与 `think` CLI 入口
- 创建 `public/index.php`（应用入口）、`public/router.php`（CLI 开发用）、`public/.htaccess`
- **Why**：ThinkPHP8 是技术栈核心，依赖必须先就位

### 步骤 2：完整目录结构创建
按 [架构设计文档.md#L103-L292](file:///workspace/docs/架构设计文档.md#L103-L292) 一次性创建所有目录：
- `app/{index,admin,api}/{controller,middleware,view}`
- `app/common/{model,service,crawler,enum,exception}`
- `app/middleware`、`app/command`
- `config/`、`route/`、`public/static/{css,js,img,uploads}`、`view/`、`runtime/`、`database/{migrations,seeds}`、`extend/Pansou/{Pay,Mail,Search,Helper,Sensitive}`

### 步骤 3：配置文件（多环境分离）
- `.env.example`（含 APP/DATABASE/REDIS/MAIL/PAY/SECURITY 分组，参见 [架构设计文档.md#L778-L820](file:///workspace/docs/架构设计文档.md#L778-L820)）
- `config/app.php`、`config/database.php`（读 env）、`config/cache.php`、`config/redis.php`、`config/queue.php`（含 crawl/mail/stat 通道，参见 [架构设计文档.md#L596-L610](file:///workspace/docs/架构设计文档.md#L596-L610)）、`config/view.php`、`config/route.php`、`config/pan.php`（业务自定义：限流阈值/积分默认值等）
- **Why**：架构文档 ADR 强调零硬编码、环境变量化

### 步骤 4：基类与全局异常处理
- `app/BaseController.php`：封装成功/错误响应、Ajax 响应、视图渲染、用户态获取
- `app/BaseAdminController.php`：继承 BaseController，注入管理员会话、操作日志钩子
- `app/ExceptionHandle.php`：全局异常捕获，生产环境屏蔽详细错误，业务异常按错误码输出，记录到 `runtime/log/`
- `app/Request.php`、`app/AppService.php`：ThinkPHP 标准基类
- `app/middleware.php`：注册全局中间件链
- **统一响应格式**（参见 [架构设计文档.md#L283-L302](file:///workspace/docs/架构设计文档.md#L283-L302)）：`{code,message,data,request_id}`，错误码常量定义在 `app/common/enum/ErrorCode.php`

### 步骤 5：全局中间件
- `app/middleware/RequestId.php`：注入 `X-Request-Id`（UUID）到响应头
- `app/middleware/LoadConfig.php`：启动时从 `system_configs` 表加载配置到内存（带 Redis 缓存）
- `app/middleware/GlobalLog.php`：慢请求（>1s）与异常日志

### 步骤 6：应用级中间件
- `app/index/middleware/UserAuth.php`：Session 校验登录态，未登录跳转/auth/login
- `app/index/middleware/RateLimit.php`：Redis 滑动窗口限流（参见 [架构设计文档.md#L390-L401](file:///workspace/docs/架构设计文档.md#L390-L401)）
- `app/index/middleware/VisitorLog.php`：访问日志
- `app/admin/middleware/AdminAuth.php`：管理员 Session 校验，与前台 Session 隔离（独立 cookie 名 + Redis 前缀）

### 步骤 7：公共 Model 层（22 个，完整实现）
按 [数据库设计文档.md](file:///workspace/docs/数据库设计文档.md) 的 DDL 逐个实现，包含：表名映射、字段类型、软删除（`SoftDelete` trait）、时间自动写入、关联关系、`search` scope。
- 用户体系：`User`、`UserCredit`、`CreditLog`、`SignInRecord`、`UserLoginLog`
- 认证：`EmailVerify`
- 资源：`Resource`、`ResourceLink`、`PanSource`、`Submission`、`ResourceReport`
- 爬虫：`CrawlTask`、`CrawlLog`
- 搜索：`SearchLog`、`HotKeyword`
- 支付：`CreditPackage`、`Order`、`PaymentLog`
- 广告：`AdSlot`、`AdPlacement`、`AdStat`
- 系统：`SystemConfig`、`AdminUser`、`AdminLog`、`SensitiveWord`、`Notification`
- **Why**：Model 是 ORM 机械映射，不涉及业务逻辑，初始化阶段可完整落地

### 步骤 8：公共 Service 层（桩代码）
按 [架构设计文档.md#L177-L189](file:///workspace/docs/架构设计文档.md#L177-L189) 创建 12 个 Service 类，含方法签名 + 参数类型 + TODO 注释：
- `SearchService`、`CreditService`、`PayService`、`MailService`、`VerifyCodeService`、`CrawlerService`、`AdService`、`SensitiveFilter`、`RateLimiter`、`ConfigService`、`AdminLogService`、`StatService`
- `RateLimiter`、`ConfigService`、`AdminLogService` 可完整实现（基础设施类，逻辑简单）

### 步骤 9：枚举与异常类
- `app/common/enum/`：`ResourceType`、`CreditType`、`OrderStatus`、`ResourceStatus`、`SubmissionStatus`、`AdSlotCode`、`ErrorCode`（枚举常量 + 文案映射）
- `app/common/exception/`：`BusinessException`、`CreditNotEnoughException`、`PayException`

### 步骤 10：爬虫抽象层（桩代码）
- `app/common/crawler/CrawlerInterface.php`：接口定义（crawl/validateUrl/parseSharePage）
- `app/common/crawler/AbstractCrawler.php`：抽象基类（httpClient/delay 共用方法）
- 15 个网盘采集器类：仅创建文件 + 继承 AbstractCrawler + TODO，不实现实际采集逻辑
- `extend/Pansou/Sensitive/DfaFilter.php`：DFA 算法骨架

### 步骤 11：扩展类库
- `extend/Pansou/Pay/CaihongPay.php`：彩虹易支付 SDK（buildPayUrl/verifyNotify 完整实现，参见 [架构设计文档.md#L374-L392](file:///workspace/docs/架构设计文档.md#L374-L392)）
- `extend/Pansou/Mail/SmtpMailer.php`：PHPMailer 封装骨架
- `extend/Pansou/Search/SearchDriverInterface.php` + `MysqlFulltextDriver.php`（完整）+ `ElasticsearchDriver.php`（桩）
- `extend/Pansou/Helper/`：`HashHelper`、`SignatureHelper`、`EncryptHelper`（AES-256-GCM）

### 步骤 12：前台应用（index）控制器桩 + 视图布局
- 控制器桩（空 action 返回视图）：`Index`、`Search`、`Resource`、`User`、`Auth`、`Submit`、`Order`、`Pay`、`Ad`、`Ajax`
- 视图：`app/index/view/layout/main.html`（主布局含 header/footer/广告位插槽）+ 各模块占位模板
- `public/static/css/main.css`、`public/static/js/common.js`（最小骨架）

### 步骤 13：后台应用（admin）控制器桩 + 视图布局
- 控制器桩：`Publics`（登录页，完整实现登录逻辑）、`Index`、`Resource`、`PanSource`、`Crawl`、`Submission`、`User`、`Order`、`Package`、`Ad`、`Config`、`Sensitive`、`Log`
- 视图：`app/admin/view/layout/main.html`（侧边栏+顶部导航布局）+ 登录页 + 仪表盘占位

### 步骤 14：API 应用（预留）
- `app/api/controller/Index.php`：健康检查接口 `GET /api/health` 返回 `{status:ok}`，验证路由链路

### 步骤 15：命令行
- `app/command/Install.php`（**完整实现**）：交互式输入数据库连接/SMTP/支付密钥/管理员账号密码 → 写 `.env` → 执行 `install.sql` + `data.sql` → 预热 Redis 缓存
- `app/command/CrawlDispatch.php`、`CrawlConsume.php`、`MailConsume.php`、`AdStatAgg.php`、`OrderClose.php`：桩代码，注册到 `config/console.php`
- 在 `config/console.php` 注册所有命令

### 步骤 16：路由定义
- `route/index.php`：前台路由组（首页/搜索/详情/用户/支付等，参见 [架构设计文档.md#L262-L272](file:///workspace/docs/架构设计文档.md#L262-L272)）
- `route/admin.php`：后台路由组（`/admin/*`，含登录免鉴权白名单）
- `route/api.php`：`/api/health` 等

### 步骤 17：数据库脚本
- `database/install.sql`：从 [数据库设计文档.md#DDL](file:///workspace/docs/数据库设计文档.md) 提取 26 张表 DDL（CREATE DATABASE + 全部建表语句）
- `database/data.sql`：种子数据（15 网盘源 + 4 广告位 + 4 套餐 + 25 项系统配置 + 默认管理员 + 示例采集任务，参见 [数据库设计文档.md#种子数据](file:///workspace/docs/数据库设计文档.md)）
- **Why**：用户明确要求范围包含此项

### 步骤 18：工程化文件
- `.gitignore`：屏蔽 `vendor/`、`runtime/`、`.env`、`public/static/uploads/*`、`composer.lock`（可选保留）、IDE 文件
- `README.md`：项目介绍 + 环境依赖 + 本地启动步骤 + 一键安装说明 + 目录结构说明
- `public/.htaccess`：URL 重写规则

---

## 四、Assumptions & Decisions（假设与决策）

1. **架构取向**：遵架构文档，原生 PHP 模板渲染，不引入 Vue3/Vite/前端构建。`project-init` 技能的 Vue3 部分要求忽略
2. **范围边界**：仅项目骨架 + DB 脚本 + 安装命令。Service/Controller/Crawler 仅桩代码（方法签名 + TODO），不含业务逻辑实现。Model 完整实现（ORM 映射属机械工作）
3. **基础设施完整实现**：中间件、配置、路由、异常处理、统一响应、彩虹易支付 SDK、Install 命令、DFA 过滤器骨架——这些是初始化必备基础设施
4. **环境隔离**：所有敏感配置（DB 密码/SMTP 授权码/支付密钥/AES 密钥）走 `.env`，代码零硬编码
5. **PHP 版本**：环境为 PHP 8.5.6-dev，`composer.json` 声明 `"php": ">=8.2"`（ThinkPHP8 最低要求）
6. **不创建测试**：用户未要求，初始化阶段不写 PHPUnit 测试
7. **不创建 Docker**：用户未选此项，部署配置后续按需补充

---

## 五、Verification Steps（验证步骤）

1. **依赖验证**：`composer install` 无错误，`php think version` 输出 ThinkPHP 8.x
2. **目录完整性**：对照 [架构设计文档.md#L103-L292](file:///workspace/docs/架构设计文档.md#L103-L292) 目录树，所有目录与文件均已创建
3. **入口可访问**：`php -S 127.0.0.1:8000 -t public/` 启动后，访问 `/` 返回首页占位模板（HTTP 200）
4. **健康检查**：`GET /api/health` 返回 `{"code":0,"message":"success","data":{"status":"ok"},"request_id":"..."}`
5. **后台登录页**：`GET /admin/login` 返回登录页（HTTP 200），未登录访问 `/admin/index` 跳转登录页
6. **路由注册**：`php think route:list` 列出前台/后台/api 全部路由
7. **命令注册**：`php think list` 显示 install/crawl:dispatch/crawl:consume/mail:consume/ad:agg/order:close 命令
8. **SQL 语法**：`mysql -e "SOURCE database/install.sql"` 或 `mysql --syntax-check` 验证 DDL 无语法错误
9. **零硬编码检查**：`grep -rE "(127\.0\.0\.1|smtp\.qq\.com|password.*=.*['\"])" app/ config/ extend/` 应仅命中注释或 `.env.example`
10. **Model 完整性**：22 个 Model 类均存在，`php think` 无类加载错误

---

## 六、执行顺序

按步骤 1→18 顺序执行，依赖关系：
- 步骤 1（composer）必须最先
- 步骤 2（目录）在步骤 1 后
- 步骤 3-6（配置/基类/中间件）可并行
- 步骤 7（Model）依赖步骤 2
- 步骤 8-11（Service/枚举/爬虫/扩展）依赖步骤 7
- 步骤 12-14（控制器/视图）依赖步骤 4-6
- 步骤 15（命令）依赖步骤 17
- 步骤 16（路由）依赖步骤 12-14
- 步骤 17（SQL）独立，可并行
- 步骤 18（工程化）最后
