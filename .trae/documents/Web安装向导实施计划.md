# Web 安装向导 /install 实施计划

> 计划类型：新增功能（Web 安装向导替代/补充 CLI 向导）
> 创建时间：2026-07-28
> 触发：用户要求将 `php think install` 命令行向导改为浏览器访问的 `/install` Web 向导

---

## 一、总结

新增 Web 安装向导 `/install`，浏览器访问完成数据库/Redis/SMTP/支付/管理员配置，自动检测环境、实时校验、安装后自检。安装完成后路由自动禁用（基于 `install.lock` 文件），防止恶意重装。保留原 CLI `php think install` 命令不动（两条路径并存）。

---

## 二、当前状态分析

### 2.1 现有 CLI 安装向导

[app/command/Install.php](file:///workspace/app/command/Install.php) 已优化完成，含：
- 输入校验（端口/邮箱/密码）
- 连接预检（MySQL/Redis，3 次重试）
- 写 .env、执行 SQL、建管理员、预热缓存
- 安装后自检（表数量/Redis ping/管理员）
- --force 确认、SMTP/支付可跳过
- 宝塔/通用场景下一步指引

**本次保留不动**，Web 向导复用其核心逻辑（写 .env、执行 SQL、建管理员、自检）。

### 2.2 路由结构

- [route/index.php](file:///workspace/route/index.php) — 前台路由
- [route/admin.php](file:///workspace/route/admin.php) — 后台路由
- 现无 `/install` 路由

### 2.3 CSRF 中间件

[app/middleware/CheckCsrf.php](file:///workspace/app/middleware/CheckCsrf.php) 全局注册，POST 需校验 token。
- 豁免方式：`$except` 数组 或 路由 `->option(['csrf_skip' => true])`
- 安装向导首屏（未生成 session 前）需豁免 CSRF

### 2.4 全局中间件链

[app/middleware.php](file:///workspace/app/middleware.php)：
```
SessionInit → CheckCsrf → RequestId → LoadConfig → GlobalLog → AllowCrossDomain
```

`LoadConfig` 中间件会从 Redis 加载系统配置——但安装前 Redis 未配置，会失败。需让 `/install` 路由豁免 `LoadConfig`，或让 `LoadConfig` 容错。

### 2.5 视图布局

[app/index/view/layout/main.html](file:///workspace/app/index/view/layout/main.html) 含 header/footer/导航，依赖 `$isLogged` 等变量。安装向导应使用**独立布局**（无导航、无登录态、无 Redis 依赖）。

### 2.6 BaseController

[app/BaseController.php](file:///workspace/app/BaseController.php) 构造函数会读取 Session user_id——安装前无登录态，无问题。但向导控制器应继承 BaseController 复用 success/error 响应方法。

---

## 三、实施方案

### 3.1 新增文件清单

| 文件 | 用途 |
|------|------|
| `app/index/controller/Install.php` | 安装向导控制器 |
| `app/index/view/install/index.html` | 安装向导页面（单页应用，分步骤） |
| `app/index/view/install/layout.html` | 安装向导独立布局（无导航） |
| `public/static/js/install.js` | 安装向导交互脚本（AJAX 步骤提交） |
| `install.lock`（运行时生成） | 安装完成标记文件，存在则禁用 `/install` |

### 3.2 路由注册

**文件**：[route/index.php](file:///workspace/route/index.php)

**变更**：在文件顶部（首屏路由之前）新增：

```php
// 安装向导(安装完成后基于 install.lock 自动禁用)
Route::get('/install', '\app\index\controller\Install/index')->option(['csrf_skip' => true, 'skip_load_config' => true]);
Route::post('/install/ajax/:step', '\app\index\controller\Install/ajax')->option(['csrf_skip' => true, 'skip_load_config' => true]);
```

**理由**：
- `csrf_skip=true` — 安装前无 session，无法生成 CSRF token
- `skip_load_config=true` — 跳过 LoadConfig 中间件（Redis 未配置会报错）

### 3.3 LoadConfig 中间件容错

**文件**：[app/middleware/LoadConfig.php](file:///workspace/app/middleware/LoadConfig.php)

**变更**：新增 `skip_load_config` 路由选项支持。在 handle 方法开头：

```php
if ($request->rule() && $request->rule()->getOption('skip_load_config', false)) {
    return $next($request);
}
```

**理由**：避免向 LoadConfig 内部加 try-catch（影响生产路径性能），用路由选项精确跳过。

### 3.4 安装向导控制器

**文件**：`app/index/controller/Install.php`

**职责**：
- `index()` — 渲染安装向导页面（GET）
- `ajax()` — 处理 AJAX 步骤提交（POST `/install/ajax/:step`）

**步骤设计**（5 步）：

| 步骤 | AJAX 端点 | 功能 |
|------|----------|------|
| 1. 环境检测 | `/install/ajax/env` | 检测 PHP 版本/扩展/MySQL 客户端/Redis 扩展，返回通过/缺失列表 |
| 2. 数据库配置 | `/install/ajax/database` | 接收 db_host/port/name/user/password，测试连接 + 建库，成功则暂存 session |
| 3. Redis 配置 | `/install/ajax/redis` | 接收 redis_host/port/password，测试连接 + ping，成功暂存 session |
| 4. SMTP/支付/管理员 | `/install/ajax/config` | 接收 SMTP/支付/管理员配置（SMTP/支付可空），校验，暂存 session |
| 5. 执行安装 | `/install/ajax/run` | 从 session 取所有配置，写 .env、执行 install.sql + data.sql、建管理员、预热缓存、自检、生成 install.lock |

**关键逻辑**：
- `index()` 首先检查 `install.lock` 是否存在，存在则返回 404（路由禁用）
- 每步 AJAX 也检查 install.lock
- 配置暂存 session（key: `install_config`），避免每步重复提交
- 复用 `app\command\Install` 的私有方法？— **不复用**（方法为 protected，无法跨类调用）。改为在控制器内重新实现核心逻辑（写 .env、执行 SQL、建管理员、自检），保持逻辑独立但行为一致
- `--force` 等价物：URL 加 `?force=1` 可跳过 install.lock 检查（用于重装，需手动构造 URL，不暴露入口）

### 3.5 安装向导视图

**文件**：`app/index/view/install/layout.html`

独立布局，无导航、无登录态、无 Redis 依赖：
- 引用 `__CSS__/main.css`（复用新拟物风格）
- 不引用 `common.js`（避免其依赖 Session/CSRF）
- 仅引用 `__JS__/install.js`

**文件**：`app/index/view/install/index.html`

单页面，5 个步骤面板（JS 控制显隐）：
1. 环境检测（自动执行，显示 PHP/扩展/MySQL/Redis 检测结果）
2. 数据库配置表单
3. Redis 配置表单
4. SMTP/支付/管理员配置表单
5. 安装进度（实时显示当前阶段：写 .env → 建库建表 → 导入种子 → 建管理员 → 预热缓存 → 自检）

**设计要点**：
- 不使用 emoji（遵循项目规范）
- 新拟物柔和风格（浅色背景 + 双向阴影 + 圆角）
- 每步实时校验（失焦/输入即校验）
- 安装进度用步骤列表 + 颜色区分（绿✓/黄!/红✗）
- 完成后显示自检报告 + 后续步骤指引（宝塔/通用）

### 3.6 安装向导脚本

**文件**：`public/static/js/install.js`

功能：
- 步骤切换控制
- 每步 AJAX 提交（fetch + JSON）
- 实时表单校验
- 安装进度轮询（步骤 5 为同步长请求，前端显示阶段进度）
- 完成后展示自检报告与下一步指引

### 3.7 install.lock 机制

- 安装成功后在项目根目录生成 `install.lock`（内容为安装时间戳）
- `Install::isInstalled()` 检查该文件是否存在
- 存在则 `index()` 与所有 `ajax()` 返回 404
- 重装方式：删除 `install.lock` 或访问 `/install?force=1`

### 3.8 Nginx 配置补充

**文件**：[docker/nginx.conf](file:///workspace/docker/nginx.conf) + 宝塔教程

**变更**：确保 `/install` 路径不被伪静态规则错误重写。现有伪静态规则 `rewrite ^(.*)$ /index.php?s=/$1 last;` 已兼容 `/install`，无需改动。

### 3.9 文档同步

**文件**：
- [docs/宝塔面板部署教程.md](file:///workspace/docs/宝塔面板部署教程.md) — 4.7 章节改为推荐 Web 向导，CLI 作为备选
- [README.md](file:///workspace/README.md) — 快速开始章节改为 Web 向导
- [docs/Docker部署教程.md](file:///workspace/docs/Docker部署教程.md) — 注明 Docker 路径不走 /install

---

## 四、假设与决策

| 项 | 决策 | 理由 |
|---|---|---|
| 是否保留 CLI `php think install` | 保留 | 两条路径并存，CLI 适合无浏览器/自动化场景，Web 适合新手 |
| 是否复用 Install.php 命令的逻辑 | 不复用，独立实现 | 命令方法为 protected，跨类调用需改可见性或反射，破坏封装；Web 控制器逻辑独立更清晰 |
| 配置暂存方式 | Session | 安装向导分步提交，需暂存中间配置；Session 已由 SessionInit 中间件初始化 |
| CSRF 处理 | 豁免 | 安装前无 session，无法生成 CSRF token；安装完成后路由禁用，无安全风险 |
| LoadConfig 中间件 | 路由选项跳过 | 避免修改中间件核心逻辑影响生产路径 |
| 安装后禁用方式 | install.lock 文件 | 简单可靠，文件存在即禁用；删除文件或 ?force=1 可重装 |
| 认证保护 | 不认证 | 用户决策；依赖 install.lock 防重装 |
| 步骤设计 | 5 步 | 环境检测 → 数据库 → Redis → SMTP/支付/管理员 → 执行安装；前端单页，后端分步 AJAX |
| 视图布局 | 独立布局 | 避免 main.html 依赖登录态/Redis 缓存配置 |

---

## 五、验证步骤

1. **环境检测**：访问 `/install`，应显示环境检测结果（PHP 版本、扩展列表、MySQL 客户端、Redis 扩展）
2. **数据库配置**：输入错误密码，应实时提示连接失败；输入正确配置，应通过
3. **Redis 配置**：输入错误密码，应提示；输入正确配置或留空跳过，应通过
4. **SMTP/支付/管理员**：留空 SMTP/支付应允许继续；管理员密码 <6 位应提示
5. **执行安装**：应显示阶段进度（写 .env → 建库建表 → 导入种子 → 建管理员 → 预热缓存 → 自检）
6. **自检报告**：应显示表数量(27)、Redis ping、管理员账号
7. **install.lock**：安装完成后再次访问 `/install` 应返回 404
8. **重装**：删除 install.lock 或访问 `/install?force=1` 应可重新安装
9. **CLI 并存**：`php think install` 仍可正常使用
10. **Docker 路径**：Docker 部署不受影响（不走 /install）

---

## 六、执行清单

- [ ] 1. 新增 `app/index/controller/Install.php` 控制器（index + ajax + 5 步逻辑 + install.lock 检查）
- [ ] 2. 新增 `app/index/view/install/layout.html` 独立布局
- [ ] 3. 新增 `app/index/view/install/index.html` 安装向导页面（5 步面板）
- [ ] 4. 新增 `public/static/js/install.js` 交互脚本
- [ ] 5. 修改 [route/index.php](file:///workspace/route/index.php) 注册 `/install` 路由（csrf_skip + skip_load_config）
- [ ] 6. 修改 [app/middleware/LoadConfig.php](file:///workspace/app/middleware/LoadConfig.php) 支持 skip_load_config 路由选项
- [ ] 7. 同步文档：宝塔教程 4.7、README 快速开始、Docker 教程注释
- [ ] 8. 验证：php -l 语法校验、模拟访问 /install 流程
- [ ] 9. git commit + push origin main
