# 前端全页面视觉重设计 - 实施计划

> 项目：轻量网盘资源搜索引擎
> 美学方向：新拟物柔和（Neumorphism Soft） + 温暖色调点缀
> 字体策略：CDN 中文字体（思源黑体 / 思源宋体）
> 范围：全部重写视觉层（HTML 结构 + CSS + JS 交互增强），保留所有 ThinkPHP 模板变量/循环/JS 业务逻辑
> 约束：不用 emoji、不用暗黑元素

---

## 一、Summary（摘要）

在已完成的 ThinkPHP8 后端基础上，对前台门户（15 个模板）和后台管理（34 个模板）共 49 个视图模板 + 4 个静态资源文件进行新拟物柔和风格的全面视觉重写。保留所有模板变量绑定（`{$var}`）、循环（`{foreach}`）、条件（`{if}`）、JS 业务逻辑（`App.ajaxPost`、`Admin.request`、`data-action` 属性驱动），仅升级 HTML 语义结构、CSS 视觉表现、JS 微交互。最终产出可立即渲染、风格统一、视觉精致的生产级前端。

**不含**：业务逻辑改动、路由改动、新增页面、后端代码改动。

---

## 二、Current State Analysis（当前状态）

### 2.1 已就位（保留不动）
- **后端**：26 个 Model、9 个 Service、22 个 Controller、5 个 Command、15 个 Crawler、8 个 Validate、2 个 Job 全部完整
- **路由**：[route/index.php](file:///workspace/route/index.php) + [route/admin.php](file:///workspace/route/admin.php) 全部就位
- **模板变量绑定**：所有 `{$var}`、`{foreach}`、`{if}`、`{switch}`、`{:func()}` 业务逻辑保留
- **JS 业务逻辑**：[public/static/js/common.js](file:///workspace/public/static/js/common.js) 的 `App.ajaxPost/ajaxGet/toast/bindSendCode/trackAdImpression` 全部保留；[public/static/js/admin.js](file:///workspace/public/static/js/admin.js) 的 `Admin.request/confirm/prompt/handleResponse` + `data-action` 属性驱动机制全部保留
- **模板替换规则**：[config/view.php](file:///workspace/config/view.php) 的 `__CSS__`→`/static/css`、`__JS__`→`/static/js`、`__IMG__`→`/static/img` 保留
- **布局机制**：`layout_on=true`、`layout_name=layout/main`、`{__CONTENT__}` 占位符保留

### 2.2 待重写（本计划范围）
- **公共资源**（4 个）：
  - [public/static/css/main.css](file:///workspace/public/static/css/main.css)（578 行 → 全部重写）
  - [public/static/css/admin.css](file:///workspace/public/static/css/admin.css)（540 行 → 全部重写）
  - [public/static/js/common.js](file:///workspace/public/static/js/common.js)（169 行 → 保留业务逻辑，增强微交互）
  - [public/static/js/admin.js](file:///workspace/public/static/js/admin.js)（288 行 → 保留业务逻辑，增强微交互）
- **前台模板**（15 个）：`app/index/view/` 下全部
- **后台模板**（34 个）：`app/admin/view/` 下全部

---

## 三、Design System（设计系统）

### 3.1 美学方向：新拟物柔和（Neumorphism Soft）

**核心理念**：浅色柔和背景 + 双向阴影营造微立体凸起/凹陷 + 圆角 + 温暖色调点缀。所有元素仿佛从背景"生长"出来，触感柔和。

**与"不要暗黑元素"的契合**：全程使用浅色系（背景 `#e8eef5`~`#f5f7fa`），无任何深色背景区块；侧边栏采用浅灰白而非深色；阴影使用低饱和度暗色而非纯黑。

### 3.2 色彩系统（CSS 变量）

```css
:root {
  /* 背景层 */
  --bg-base: #e8eef5;          /* 主背景（新拟物经典浅蓝灰） */
  --bg-surface: #f0f4f8;       /* 卡片表面 */
  --bg-elevated: #f5f7fa;      /* 凸起元素 */
  --bg-recessed: #dde4ec;      /* 凹陷元素（输入框等） */

  /* 文字 */
  --text-primary: #2d3748;     /* 主文字（深石墨，非纯黑） */
  --text-secondary: #4a5568;   /* 次要文字 */
  --text-muted: #718096;       /* 弱化文字 */
  --text-on-accent: #ffffff;   /* 强调色上的文字 */

  /* 强调色（温暖色调点缀） */
  --accent-primary: #6c8ebf;   /* 主强调（柔和天蓝） */
  --accent-warm: #ff9a76;      /* 暖橙（CTA/高亮） */
  --accent-pink: #ff7b9c;      /* 粉（推荐/精选） */
  --accent-mint: #4ecdc4;      /* 薄荷绿（成功） */
  --accent-amber: #f6b73c;     /* 琥珀（警告） */
  --accent-coral: #ff6b6b;     /* 珊瑚红（危险） */

  /* 新拟物阴影（核心） */
  --shadow-light: #ffffff;     /* 亮阴影色 */
  --shadow-dark: #c3d0dd;      /* 暗阴影色 */
  --shadow-light-soft: rgba(255,255,255,0.8);
  --shadow-dark-soft: rgba(163,177,198,0.6);

  /* 凸起阴影 */
  --shadow-raised: 6px 6px 12px var(--shadow-dark-soft), -6px -6px 12px var(--shadow-light-soft);
  --shadow-raised-sm: 3px 3px 6px var(--shadow-dark-soft), -3px -3px 6px var(--shadow-light-soft);
  --shadow-raised-lg: 10px 10px 20px var(--shadow-dark-soft), -10px -10px 20px var(--shadow-light-soft);

  /* 凹陷阴影（输入框、按下态） */
  --shadow-inset: inset 4px 4px 8px var(--shadow-dark-soft), inset -4px -4px 8px var(--shadow-light-soft);
  --shadow-inset-sm: inset 2px 2px 4px var(--shadow-dark-soft), inset -2px -2px 4px var(--shadow-light-soft);

  /* 圆角 */
  --radius-sm: 8px;
  --radius-md: 12px;
  --radius-lg: 18px;
  --radius-xl: 24px;
  --radius-pill: 999px;

  /* 间距 */
  --space-xs: 4px;
  --space-sm: 8px;
  --space-md: 16px;
  --space-lg: 24px;
  --space-xl: 40px;
  --space-2xl: 64px;

  /* 字体 */
  --font-display: 'Noto Serif SC', 'Songti SC', serif;
  --font-body: 'Noto Sans SC', -apple-system, 'PingFang SC', 'Microsoft YaHei', sans-serif;
  --font-mono: 'JetBrains Mono', 'Fira Code', 'Consolas', monospace;
}
```

### 3.3 字体加载（CDN）

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@300;400;500;700;900&family=Noto+Serif+SC:wght@400;700;900&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
```

- **显示字体**：Noto Serif SC（思源宋体）- 用于大标题、品牌名、数字大字
- **正文字体**：Noto Sans SC（思源黑体）- 用于正文、按钮、表格
- **等宽字体**：JetBrains Mono - 用于代码、订单号、验证码

### 3.4 微交互原则

- **悬停**：凸起元素 hover 时阴影变小变浅（轻微"按下"感）
- **点击**：active 态切换为 inset 阴影（真实凹陷）
- **聚焦**：输入框 focus 时增加 `--accent-primary` 光晕边框
- **加载**：按钮提交时显示 CSS spinner + 文案变化
- **入场**：页面加载时卡片 staggered fade-up（`animation-delay` 递增）
- **Toast**：从顶部滑入，带柔和阴影，2.6s 后淡出
- **模态框**：背景模糊 + 卡片缩放入场

---

## 四、Proposed Changes（变更清单）

### 阶段 A：公共资源重写（4 个文件）

#### A1. 前台公共样式 main.css（全部重写）
文件：[public/static/css/main.css](file:///workspace/public/static/css/main.css)
- 引入上述 CSS 变量系统
- **重写模块**：
  - reset + base（字体栈、背景色、文字色）
  - 布局：`.container`、`.site-header`（sticky + 新拟物底部阴影）、`.site-main`、`.site-footer`
  - 导航：`.header-inner`、`.logo`（思源宋体大字）、`.header-search`（新拟物凹陷输入框 + 凸起按钮）、`.user-menu`
  - 首页：`.home-hero`（大标题 + 居中搜索）、`.home-search`（大型凹陷搜索框）、`.hot-keywords`（pill 标签）、`.banner-list`、`.resource-list`(grid)、`.resource-card`（新拟物凸起卡片，hover 轻微抬起）
  - 资源类型徽章 `.type-badge.t1-t7`：每种类型对应一种温暖色调
  - 搜索页：`.search-page`、`.search-box`、`.search-filter`（凹陷筛选区）、`.filter-row`、`.result-card`（凸起卡片）、`.empty-tip`
  - 资源详情：`.detail-page`、`.detail-header`（封面 + 信息双栏）、`.detail-block`（凹陷信息块）、`.link-list`、`.report-form`
  - 通用按钮：`.btn`（凸起）、`.btn-primary`（暖橙）、`.btn-small`、`.btn-link`；active 态切 inset
  - 表单：`.form`、`.form-item`、`.form-row`、`input/select/textarea`（凹陷样式）
  - 认证页：`.auth-page`（居中卡片）、`.auth-box`（大凸起卡片）、`.code-row`
  - 用户中心：`.user-center`、`.profile-card`（头像 + 信息）、`.credit-balance`（数字大字）、`.user-tabs`（pill 切换）
  - 数据表格：`.data-table-wrap`（凹陷容器）、`.data-table`（斑马纹 + hover 行高亮）
  - 套餐：`.package-list`、`.package-card`（凸起大卡片，recommended 加粉色调）、`.recommend-tag`
  - 支付结果：`.pay-result`（大图标 + 状态文案）、`.pay-status-success/pending/fail`
  - 分页：`.pagination`（pill 按钮，当前页凸起）
  - Toast：`.toast-wrap`（顶部居中）、`.toast`（凸起小卡片）、`.toast-error/success/info`
  - 浮动广告：`.float-ad-slot`（圆角凸起小卡片）
  - 响应式：`@media (max-width:768px)` 移动端适配

#### A2. 后台公共样式 admin.css（全部重写）
文件：[public/static/css/admin.css](file:///workspace/public/static/css/admin.css)
- 复用前台 CSS 变量系统（同文件内重新声明，保持后台独立）
- **重写模块**：
  - 布局：`.admin-wrapper`、`.admin-sidebar`（浅灰白 `#f0f4f8`，非深色）、`.admin-main`、`.admin-topbar`（sticky + 凹陷下边线）、`.admin-content`
  - 侧边栏：`.sidebar-logo`（思源宋体）、`.sidebar-menu a`（hover 凸起、active 凹陷 + 左侧暖橙边）
  - 登录页：`.admin-login-page`（柔和渐变背景 `#e8eef5`→`#f5f7fa`）、`.admin-login-box`（大凸起卡片）
  - 仪表盘：`.stat-cards`(grid)、`.stat-card`（凸起卡片，左边框彩色条 blue/green/orange/red）
  - 面板：`.panel`（凹陷容器）、`.panel-header`、`.panel-body`
  - 工具栏：`.toolbar`（凹陷筛选区）
  - 表单：`.form-item`、`.form-panel`、`.form-actions`、`input/select/textarea`（凹陷）
  - 按钮：`.btn`（凸起）、`.btn-primary/success/danger/warning`（对应薄荷绿/珊瑚红/琥珀）、`.btn-sm`、`.btn-link`、`.btn-submit`
  - 表格：`.table-wrap`（凹陷容器）、`.table`（hover 行高亮）、`.col-id`、`.col-actions`、`.text-ellipsis`
  - 徽章：`.badge-success/warning/danger/info/secondary`（柔和色调 pill）
  - 分页：`.pagination`
  - 空状态：`.empty`
  - 详情页：`.detail-grid`、`.detail-item`、`.detail-section`
  - 配置页：`.config-layout`、`.config-tabs`（左侧 pill 导航）
  - 日志：`.log-list`、`.log-item`（凹陷块）、`.log-item pre`（浅灰背景，非深色）
  - Toast：`.toast`、`.toast-success/error/info`
  - 模态框：`.modal-mask`（半透明白 + backdrop-blur）、`.modal-box`（凸起大卡片）、`.modal-header/body/footer`
  - 辅助类：`.text-*`、`.mt-10`、`.flex`、`.flex-between`
  - 响应式：`@media (max-width:768px)` 侧边栏抽屉式

#### A3. 前台公共脚本 common.js（保留逻辑 + 增强交互）
文件：[public/static/js/common.js](file:///workspace/public/static/js/common.js)
- **保留**：`getCsrfToken`、`ajaxPost`、`ajaxGet`、`toast`、`startCountdown`、`bindSendCode`、`trackAdImpression`、`initAdImpressions`、`window.App`
- **增强**：
  - `toast` 增强：添加入场/出场动画 class、支持图标（CSS 绘制，非 emoji）
  - 新增 `initCardHover()`：卡片 hover 时添加 `.is-hover` class 触发 CSS 抬起动画
  - 新增 `initStaggeredReveal()`：页面加载时对 `[data-reveal]` 元素依次 fade-up
  - 新增 `initButtonLoading()`：为 `[data-loading-text]` 按钮自动处理提交中状态
  - DOM Ready 自动调用上述初始化

#### A4. 后台公共脚本 admin.js（保留逻辑 + 增强交互）
文件：[public/static/js/admin.js](file:///workspace/public/static/js/admin.js)
- **保留**：`Admin.toast/confirm/prompt/request/handleResponse/post`、`escapeHtml`、`buildQuery`、`highlightSidebar`、`bindConfirmAjax`、`bindAjaxPost`、`bindAjaxForm`、`bindPromptAjax`
- **增强**：
  - `toast` 增强：同前台
  - `confirm`/`prompt` 增强：模态框入场动画（scale + fade）、背景模糊
  - 新增 `initTableHover()`：表格行 hover 增强
  - 新增 `initSidebarToggle()`：移动端侧边栏抽屉切换按钮

---

### 阶段 B：前台布局 + 首页 + 搜索页（3 个文件）

#### B1. 前台布局 layout/main.html
文件：[app/index/view/layout/main.html](file:///workspace/app/index/view/layout/main.html)
- **保留**：`{__CONTENT__}`、`__CSS__`、`__JS__` 占位符
- **重写**：
  - `<head>` 增加 CDN 字体 preconnect + link
  - `<header class="site-header">`：新拟物 sticky 头部，logo 用思源宋体，搜索框凹陷样式，导航 pill 按钮
  - `<main class="site-main">`：内容区容器
  - `<footer class="site-footer">`：柔和版权信息
  - 浮动广告位保留 `id="float-ad"`

#### B2. 首页 index/index.html
文件：[app/index/view/index/index.html](file:///workspace/app/index/view/index/index.html)
- **保留**：`$hotKeywords`、`$banners`、`$resources` 变量；`{foreach}`、`{if}`、`{switch}` 逻辑
- **重写结构**：
  - `.home-hero`：大标题（思源宋体 900）+ 大型搜索框（凹陷）+ 热搜词 pill 标签
  - `.banner-list`：横幅广告卡片（凸起，圆角）
  - `.resource-list`：网格布局，每张 `.resource-card` 凸起卡片，hover 抬起
  - 资源类型 `.type-badge.t1-t7`：7 种温暖色调 pill

#### B3. 搜索页 search/index.html
文件：[app/index/view/search/index.html](file:///workspace/app/index/view/search/index.html)
- **保留**：`$q`、`$type`、`$sources`、`$ads`、`$result` 变量；游标分页逻辑
- **重写结构**：
  - `.search-box`：大型凹陷搜索框
  - `.search-filter`：凹陷筛选区，类型/网盘源/大小/时间四列布局
  - `.ad-top`：置顶广告卡片
  - `.result-stats`：统计信息（思源宋体数字）
  - `.result-list`：结果卡片列表，凸起卡片
  - `.pagination`：pill 分页按钮

---

### 阶段 C：前台资源 + 认证 + 用户中心（7 个文件）

#### C1. 资源详情 resource/detail.html
文件：[app/index/view/resource/detail.html](file:///workspace/app/index/view/resource/detail.html)
- **保留**：`$resource`、`$linkSources`、`$ads`、`$related` 变量；viewLink/report AJAX 逻辑
- **重写**：详情头部（封面 + 信息双栏）、网盘来源列表（凹陷块）、广告位、相关推荐（网格）、举报表单（凹陷）

#### C2. 登录页 auth/login.html
文件：[app/index/view/auth/login.html](file:///workspace/app/index/view/auth/login.html)
- **保留**：`App.bindSendCode`（type=2）、`App.ajaxPost('/ajax/auth/login')`、redirect 逻辑
- **重写**：居中凸起大卡片，邮箱 + 验证码凹陷输入框，发送验证码 pill 按钮，登录大按钮

#### C3. 注册页 auth/register.html
文件：[app/index/view/auth/register.html](file:///workspace/app/index/view/auth/register.html)
- **保留**：`App.bindSendCode`（type=1）、`App.ajaxPost('/ajax/auth/register')`
- **重写**：同登录页结构

#### C4. 用户中心首页 user/index.html
文件：[app/index/view/user/index.html](file:///workspace/app/index/view/user/index.html)
- **保留**：`$user`、`$balance` 变量；signIn/profile AJAX 逻辑
- **重写**：资料卡片（头像 + 昵称）、积分余额大数字、签到 pill 按钮、tab 导航、编辑资料表单

#### C5. 用户中心-积分流水 user/credits.html
文件：[app/index/view/user/credits.html](file:///workspace/app/index/view/user/credits.html)
- **保留**：`$balance`、`$logs` 变量；分页渲染
- **重写**：余额卡片、tab 导航、数据表格（凹陷容器）

#### C6. 用户中心-我的订单 user/orders.html
文件：[app/index/view/user/orders.html](file:///workspace/app/index/view/user/orders.html)
- **保留**：`$orders` 变量；订单状态 switch
- **重写**：tab 导航、数据表格

#### C7. 用户中心-我的提交 submit/my_list.html
文件：[app/index/view/submit/my_list.html](file:///workspace/app/index/view/submit/my_list.html)
- **保留**：`$submissions` 变量；提交状态 switch
- **重写**：tab 导航、数据表格

---

### 阶段 D：前台提交 + 订单 + 支付（6 个文件）

#### D1. 资源提交页 submit/index.html
文件：[app/index/view/submit/index.html](file:///workspace/app/index/view/submit/index.html)
- **保留**：`$panSources` 变量；`App.ajaxPost('/ajax/submit/create')` 逻辑
- **重写**：提交表单（凹陷输入框）+ 网盘源列表表格

#### D2. 套餐列表 order/packages.html
文件：[app/index/view/order/packages.html](file:///workspace/app/index/view/order/packages.html)
- **保留**：`$packages` 变量
- **重写**：套餐卡片网格，凸起大卡片，recommended 加粉色调，价格大数字（思源宋体）

#### D3. 我的订单 order/my_list.html
文件：[app/index/view/order/my_list.html](file:///workspace/app/index/view/order/my_list.html)
- **保留**：`$orders` 变量
- **重写**：tab 导航、数据表格

#### D4. 订单详情 order/detail.html
文件：[app/index/view/order/detail.html](file:///workspace/app/index/view/order/detail.html)
- **保留**：`$order`、`$logs` 变量；支付按钮逻辑
- **重写**：基本信息卡片、支付日志表格

#### D5. 支付返回 pay/return.html
文件：[app/index/view/pay/return.html](file:///workspace/app/index/view/pay/return.html)
- **保留**：`$result` 变量；状态 switch
- **重写**：大图标（CSS 绘制对勾/叉号/时钟，非 emoji）+ 状态文案 + 订单信息

#### D6. 用户中心-订单 user/orders.html（已在 C6 完成）

---

### 阶段 E：后台布局 + 登录 + 仪表盘（3 个文件）

#### E1. 后台布局 layout/main.html
文件：[app/admin/view/layout/main.html](file:///workspace/app/admin/view/layout/main.html)
- **保留**：`{__CONTENT__}`、`__CSS__`、`__JS__` 占位符
- **重写**：
  - `<head>` 增加 CDN 字体
  - `.admin-wrapper`：flex 布局
  - `.admin-sidebar`：浅灰白背景（非深色），logo 思源宋体，菜单 pill 导航
  - `.admin-main`：内容区
  - `.admin-topbar`：sticky 顶栏 + 退出按钮
  - 移动端抽屉切换按钮

#### E2. 后台登录 publics/login.html
文件：[app/admin/view/publics/login.html](file:///workspace/app/admin/view/publics/login.html)
- **保留**：form action="/admin/login" method="post"；username/password 字段
- **重写**：柔和渐变背景 + 居中凸起大卡片 + 凹陷输入框

#### E3. 仪表盘 index/index.html
文件：[app/admin/view/index/index.html](file:///workspace/app/admin/view/index/index.html)
- **保留**：`$stat` 变量及所有子字段
- **重写**：统计卡片网格（7 张凸起卡片，左边框彩色条），热搜词 pill 标签

---

### 阶段 F：后台资源 + 网盘源 + 采集（9 个文件）

#### F1-F3. 资源管理 index/add/edit
文件：[app/admin/view/resource/index.html](file:///workspace/app/admin/view/resource/index.html)、[add.html](file:///workspace/app/admin/view/resource/add.html)、[edit.html](file:///workspace/app/admin/view/resource/edit.html)
- **保留**：`$list`、`$title`、`$resource_type`、`$status`、`$vo` 变量；`data-action="ajax-form"`/`confirm-ajax`；分页渲染
- **重写**：工具栏（凹陷筛选）、表格（凹陷容器）、表单面板（凸起卡片）

#### F4-F6. 网盘源管理 index/add/edit
文件：[app/admin/view/pan_source/index.html](file:///workspace/app/admin/view/pan_source/index.html)、[add.html](file:///workspace/app/admin/view/pan_source/add.html)、[edit.html](file:///workspace/app/admin/view/pan_source/edit.html)
- **保留**：变量与 data-action
- **重写**：同 F1-F3 风格

#### F7-F10. 采集任务管理 index/add/edit/logs
文件：[app/admin/view/crawl/index.html](file:///workspace/app/admin/view/crawl/index.html)、[add.html](file:///workspace/app/admin/view/crawl/add.html)、[edit.html](file:///workspace/app/admin/view/crawl/edit.html)、[logs.html](file:///workspace/app/admin/view/crawl/logs.html)
- **保留**：变量与 data-action
- **重写**：同 F1-F3 风格

---

### 阶段 G：后台用户 + 订单 + 套餐 + 提交审核（8 个文件）

#### G1-G2. 用户管理 index/detail
文件：[app/admin/view/user/index.html](file:///workspace/app/admin/view/user/index.html)、[detail.html](file:///workspace/app/admin/view/user/detail.html)
- **保留**：`$list`、`$vo` 变量；`adjustCredit` form；关联表数据
- **重写**：列表表格 + 详情页多 section（基本信息、积分调整、订单/提交/登录/积分流水表）

#### G3-G4. 订单管理 index/detail
文件：[app/admin/view/order/index.html](file:///workspace/app/admin/view/order/index.html)、[detail.html](file:///workspace/app/admin/view/order/detail.html)
- **保留**：变量；手动补单/退款 data-action
- **重写**：列表 + 详情（订单信息、补单表单、退款按钮、支付日志表）

#### G5-G7. 积分套餐 index/add/edit
文件：[app/admin/view/package/index.html](file:///workspace/app/admin/view/package/index.html)、[add.html](file:///workspace/app/admin/view/package/add.html)、[edit.html](file:///workspace/app/admin/view/package/edit.html)
- **保留**：变量；toggle/delete data-action
- **重写**：列表 + 表单

#### G8. 用户提交审核 index
文件：[app/admin/view/submission/index.html](file:///workspace/app/admin/view/submission/index.html)
- **保留**：`$list` 变量；approve/reject data-action
- **重写**：审核列表表格

---

### 阶段 H：后台广告 + 配置 + 敏感词 + 日志（14 个文件）

#### H1-H5. 广告管理 index/placements/create/edit/stats
文件：[app/admin/view/ad/index.html](file:///workspace/app/admin/view/ad/index.html)、[placements.html](file:///workspace/app/admin/view/ad/placements.html)、[create.html](file:///workspace/app/admin/view/ad/create.html)、[edit.html](file:///workspace/app/admin/view/ad/edit.html)、[stats.html](file:///workspace/app/admin/view/ad/stats.html)
- **保留**：变量；data-action
- **重写**：广告位列表、投放列表、投放表单、统计卡片

#### H6. 系统配置 index
文件：[app/admin/view/config/index.html](file:///workspace/app/admin/view/config/index.html)
- **保留**：`$groups`、`$data`、`$active` 变量；save form
- **重写**：左右布局（pill 分组导航 + 配置面板）

#### H7-H9. 敏感词管理 index/add/edit
文件：[app/admin/view/sensitive/index.html](file:///workspace/app/admin/view/sensitive/index.html)、[add.html](file:///workspace/app/admin/view/sensitive/add.html)、[edit.html](file:///workspace/app/admin/view/sensitive/edit.html)
- **保留**：变量；import form；data-action
- **重写**：列表（含批量导入面板）+ 表单

#### H10-H13. 日志查看 admin/user_login/payment/exception
文件：[app/admin/view/log/admin.html](file:///workspace/app/admin/view/log/admin.html)、[user_login.html](file:///workspace/app/admin/view/log/user_login.html)、[payment.html](file:///workspace/app/admin/view/log/payment.html)、[exception.html](file:///workspace/app/admin/view/log/exception.html)
- **保留**：变量；切换按钮组
- **重写**：日志列表表格 + 异常日志凹陷代码块（浅灰背景，非深色）

---

## 五、Assumptions & Decisions（假设与决策）

1. **保留所有业务逻辑**：ThinkPHP 模板变量、循环、条件、JS 函数、data-action 属性驱动机制全部保留，仅升级视觉层
2. **新拟物柔和风格**：浅色背景 + 双向阴影 + 圆角 + 温暖色调点缀，全程无暗黑元素
3. **无 emoji**：所有图标用 CSS 绘制（对勾、叉号、时钟、箭头等）或 SVG inline
4. **CDN 字体**：思源宋体（显示）+ 思源黑体（正文）+ JetBrains Mono（等宽），通过 Google Fonts CDN 加载
5. **CSS 变量系统**：全部样式通过 CSS 变量驱动，便于维护和主题切换
6. **后台侧边栏浅色**：采用 `#f0f4f8` 浅灰白背景（非传统深色侧边栏），符合"不要暗黑元素"
7. **日志代码块浅色**：异常日志 `<pre>` 使用浅灰背景（`#f0f4f8`），非传统深色终端样式
8. **响应式适配**：前台移动端单列、后台侧边栏抽屉式
9. **微交互增强**：卡片 hover 抬起、按钮 active 凹陷、输入框 focus 光晕、页面加载 staggered reveal
10. **不改路由/控制器/模型**：纯视觉层重写，后端零改动
11. **模板缓存**：开发期间 `APP.DEBUG=true` 自动关闭模板缓存，便于实时预览
12. **分页样式兼容**：保留 ThinkPHP `$list->render()` 输出的 HTML 结构，仅 CSS 美化

---

## 六、Verification Steps（验证步骤）

1. **PHP 语法**：所有 .html 模板无 PHP 语法错误（ThinkPHP 模板引擎解析）
2. **HTTP 链路**：启动 `php -S 127.0.0.1:8765 -t public/`，访问以下页面返回 200：
   - 前台：`/`、`/search?q=test`、`/auth/login`、`/auth/register`、`/order/packages`
   - 后台：`/admin/login`
3. **变量绑定完整性**：检查所有 `{$var}`、`{foreach}`、`{if}`、`{switch}` 与原模板一致
4. **JS 逻辑完整性**：`App.ajaxPost`、`Admin.request`、`data-action` 机制正常工作
5. **CSS 变量覆盖**：所有颜色/阴影/圆角使用 CSS 变量，无硬编码
6. **无 emoji**：grep 检查所有模板和 CSS 无 emoji 字符
7. **无暗黑元素**：检查所有背景色为浅色系（`#e8eef5`~`#f5f7fa`），无 `#1f2d3d` 等深色背景
8. **响应式**：移动端 viewport 下布局正常
9. **字体加载**：CDN 字体 link 正确引入，font-family 正确应用
10. **微交互**：hover/focus/active 状态视觉反馈正常

---

## 七、执行顺序

```
阶段 A (公共资源) ──┬──> 阶段 B (前台布局+首页+搜索) ──┐
                    ├──> 阶段 C (前台资源+认证+用户中心) ──┤
                    ├──> 阶段 D (前台提交+订单+支付) ──────┤
                    ├──> 阶段 E (后台布局+登录+仪表盘) ────┤
                    ├──> 阶段 F (后台资源+网盘源+采集) ────┤
                    ├──> 阶段 G (后台用户+订单+套餐+审核) ─┤
                    └──> 阶段 H (后台广告+配置+敏感词+日志)┤
                                                          └──> 验证
```

- **阶段 A 优先**：CSS/JS 是所有模板的依赖
- **阶段 B-H 可顺序执行**：各阶段内文件独立
- **验证最后**：全部完成后统一验证

**预计涉及文件**：49 个视图模板 + 4 个静态资源 = 53 个文件
