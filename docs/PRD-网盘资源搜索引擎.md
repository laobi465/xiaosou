# 轻量网盘资源搜索引擎 - 产品需求文档（PRD）

> 版本：v1.0  |  日期：2026-07-28  |  技术栈：原生 PHP + ThinkPHP8  |  状态：需求确认完成，可进入开发

---

## 一、需求访谈总结（精简版）

### 1.1 项目定位
- **项目类型**：面向 C 端的轻量级网盘资源聚合搜索引擎（Web 站点）
- **核心一句话**：让用户一键搜索全网 15+ 主流与小众网盘的公开分享资源，免登录即可搜，登录后按积分消耗查看完整链接
- **目标用户**：普通资源查找用户（C 端）、广告主（B 端购买广告位）、站点管理员
- **项目规模**：完整商业项目，可长期迭代

### 1.2 关键决策（已与用户确认）

| 维度 | 决策 |
|---|---|
| 搜索机制 | **混合模式**：爬虫自动采集 + 用户手动提交，统一索引去重 |
| 付费模式 | **积分/次数包**（C 端充值消耗）+ **广告位售卖**（B 端售卖），无 VIP 会员、无单资源解锁 |
| 角色体系 | **三角色**：游客 / 注册用户 / 超级管理员 |
| 资源覆盖 | **全网 15+ 家**：百度网盘、阿里云盘、夸克网盘、迅雷云盘、123 云盘、115 网盘、蓝奏云、天翼云盘、移动云盘、腾讯微云、UC 网盘、芒果云、奶牛快传、曲奇云盘、Firefox Send |
| 账户体系 | 邮箱验证码注册登录 + 第三方 SMTP + 授权码 |
| 支付 | 彩虹易支付（聚合支付） |
| 技术栈 | 原生 PHP 模板渲染（前端）+ ThinkPHP8（后端）+ MySQL 8 + Redis |

### 1.3 合理默认假设（如不符可调整）
1. 游客可搜索并查看资源标题/封面/简介，**查看完整网盘链接需登录并消耗积分**
2. 注册赠送初始积分（如 10 积分），每日签到送积分（如 1 积分）
3. 前端采用 ThinkPHP8 模板引擎服务端渲染 + 少量原生 JS 交互（不前后端分离，契合"原生 PHP"定位）
4. 搜索起步使用 MySQL FULLTEXT 全文索引，预留 Elasticsearch 扩展接口
5. 爬虫采用 ThinkPHP8 队列异步执行，避免阻塞请求
6. 资源提交需管理员审核后入库（防垃圾）

---

## 二、完整功能清单（可直接开发用）

### 2.1 前台功能（C 端）

#### 模块 A：搜索模块
- A1. 关键词搜索（标题/提取码/网盘类型多字段）
- A2. 网盘来源筛选（15+ 家多选）
- A3. 资源类型筛选（影视/音乐/软件/文档/图片/压缩包/其他）
- A4. 时间范围筛选（今日/本周/本月/全年）
- A5. 文件大小筛选
- A6. 排序（相关度/最新/热门/文件大小）
- A7. 搜索结果分页
- A8. 搜索历史（登录用户）
- A9. 热门搜索词推荐
- A10. 搜索结果中嵌入广告位（置顶/列表间）

#### 模块 B：资源详情模块
- B1. 资源详情页（标题/封面/简介/大小/格式/分享时间/来源网盘/提取码）
- B2. 查看完整链接（消耗积分，弹窗/跳转）
- B3. 资源失效举报
- B4. 相关资源推荐
- B5. 详情页广告位（banner/弹窗）

#### 模块 C：用户提交模块
- C1. 提交网盘资源（标题/链接/提取码/网盘类型/资源类型/简介/封面）
- C2. 我的提交记录列表
- C3. 提交审核状态查看（待审/通过/驳回）
- C4. 审核通过奖励积分

#### 模块 D：用户账户模块
- D1. 邮箱注册（发送验证码，验证码 5 分钟有效）
- D2. 邮箱登录（验证码登录）
- D3. 密码登录（可选，邮箱+密码）
- D4. 忘记密码（邮件重置）
- D5. 个人中心（资料/头像/昵称）
- D6. 积分余额展示
- D7. 积分流水（充值/消耗/签到/奖励）
- D8. 每日签到
- D9. 退出登录

#### 模块 E：积分充值模块
- E1. 积分套餐列表（如 10 元=100 积分、50 元=600 积分、100 元=1500 积分）
- E2. 选择套餐 → 彩虹易支付下单
- E3. 支付成功回调 → 积分到账
- E4. 订单列表（订单号/金额/套餐/状态/时间）
- E5. 订单详情

#### 模块 F：广告展示模块
- F1. 首页 Banner 广告
- F2. 搜索结果置顶广告
- F3. 详情页弹窗广告
- F4. 底部浮动广告
- F5. 广告点击统计（无感采集）

### 2.2 后台功能（管理端）

#### 模块 G：仪表盘
- G1. 今日搜索量 / 访问量 / 新增用户 / 订单金额
- G2. 资源总量 / 今日新增 / 失效待处理
- G3. 积分消耗趋势图
- G4. 热门网盘来源占比

#### 模块 H：资源管理
- H1. 资源列表（搜索/筛选/分页）
- H2. 编辑资源
- H3. 删除资源（软删除）
- H4. 资源失效标记
- H5. 批量操作（删除/转移类型）
- H6. 资源审核（用户提交的）

#### 模块 I：网盘源管理
- I1. 网盘源列表（15+ 家）
- I2. 新增/编辑网盘源（名称/Logo/匹配规则/采集开关）
- I3. 启用/禁用网盘源
- I4. 单源采集测试

#### 模块 J：爬虫任务管理
- J1. 采集任务列表
- J2. 新建采集任务（目标源/关键词/频率）
- J3. 任务启停
- J4. 采集日志查看
- J5. 失败重试

#### 模块 K：用户管理
- K1. 用户列表（搜索/筛选）
- K2. 用户详情（资料/积分/订单/提交记录/登录日志）
- K3. 调整积分（增减）
- K4. 封禁/解封用户
- K5. 用户状态管理

#### 模块 L：订单管理
- L1. 订单列表（状态筛选）
- L2. 订单详情
- L3. 手动补单（支付异常时）
- L4. 退款处理
- L5. 财务统计报表

#### 模块 M：积分套餐管理
- M1. 套餐列表
- M2. 新增/编辑套餐（价格/积分/是否推荐）
- M3. 上下架套餐

#### 模块 N：广告管理
- N1. 广告位列表（首页 Banner / 搜索置顶 / 详情弹窗 / 底部浮动）
- N2. 新增广告投放（广告位/标题/图片/链接/投放时段/展示权重）
- N3. 编辑/删除广告
- N4. 广告上下线
- N5. 广告数据统计（曝光/点击/CTR）

#### 模块 O：系统配置
- O1. SMTP 配置（主机/端口/账号/授权码/发件人）
- O2. 彩虹易支付配置（PID/KEY/异步通知地址/同步跳转地址）
- O3. 站点基础配置（名称/Logo/SEO/备案号）
- O4. 积分配置（注册赠送/签到赠送/查看消耗/提交奖励）
- O5. 安全配置（限流阈值/IP 黑名单/敏感词）
- O6. 敏感词词库管理

#### 模块 P：日志与安全
- P1. 管理员操作日志
- P2. 用户登录日志
- P3. 支付日志
- P4. 异常访问日志
- P5. 邮件发送日志

---

## 三、业务流程图（文字版）

### 3.1 用户搜索并查看资源流程
```
[用户访问首页]
   ↓
[输入关键词 / 选择筛选条件]
   ↓
[提交搜索请求] → [限流校验] →失败→ [提示操作频繁]
   ↓通过
[MySQL FULLTEXT 检索] + [Redis 缓存命中检查]
   ↓
[返回结果列表（标题/封面/简介，不含完整链接）]
   ↓
[点击某资源] → [资源详情页] → [展示资源信息 + 广告位]
   ↓
[点击"查看完整链接"]
   ↓
[判断登录状态] →未登录→ [跳转登录页] → [邮箱验证码登录] → [回到详情页]
   ↓已登录
[判断积分是否充足] →不足→ [引导充值] → [选择套餐] → [彩虹易支付]
   ↓充足
[扣除积分 + 记录流水] → [展示完整网盘链接 + 提取码]
   ↓
[记录访问日志] → [结束]
```

### 3.2 邮箱验证码注册登录流程
```
[用户输入邮箱] → [点击发送验证码]
   ↓
[校验邮箱格式 + 频率限制（同邮箱 60s 一次，同 IP 10 分钟 5 次）]
   ↓
[生成 6 位验证码] → [存入 Redis（key=verify:email:xxx, ttl=300s）]
   ↓
[调用 PHPMailer + 第三方 SMTP + 授权码发送邮件]
   ↓
[记录邮件发送日志] → [用户输入验证码]
   ↓
[校验验证码] →错误→ [提示并允许重试]（最多 5 次）
   ↓正确
[判断邮箱是否已注册]
   ├─未注册→ [创建用户 + 赠送初始积分] → [生成会话] → [登录成功]
   └─已注册→ [生成会话] → [登录成功]
```

### 3.3 积分充值支付流程
```
[用户选择积分套餐] → [点击购买]
   ↓
[创建订单（status=待支付）] → [调用彩虹易支付下单接口]
   ↓
[跳转彩虹易支付收银台] → [用户完成支付]
   ↓
[彩虹易支付异步回调本站 notify_url]
   ↓
[签名校验] →失败→ [记录异常 + 拒绝]
   ↓通过
[校验订单金额与状态] → [更新订单 status=已支付] → [积分到账 + 流水记录]
   ↓
[返回 success 给彩虹易支付]
   ↓
[用户同步跳转 return_url] → [展示支付成功页]
```

### 3.4 爬虫采集流程
```
[后台创建采集任务（目标网盘源 + 关键词 + 频率）]
   ↓
[ThinkPHP8 定时任务触发] → [投递到队列]
   ↓
[队列 Worker 消费] → [调用对应网盘源采集器]
   ↓
[抓取分享链接列表] → [解析标题/大小/时间/提取码]
   ↓
[敏感词过滤] → [去重校验（URL hash）]
   ↓
[入库 resource_links + 关联 resources] → [记录采集日志]
   ↓
[失败 → 重试 3 次 → 仍失败标记异常]
```

### 3.5 用户提交流程
```
[用户填写资源信息 + 链接] → [提交]
   ↓
[敏感词校验 + 链接格式校验] → [入库 status=待审]
   ↓
[管理员后台审核]
   ├─通过→ [资源正式入库] → [奖励提交者积分] → [站内通知]
   └─驳回→ [记录驳回原因] → [站内通知用户]
```

### 3.6 广告投放流程
```
[广告主联系购买] → [管理员后台创建广告]
   ↓
[选择广告位 + 上传素材 + 设置投放时段 + 权重]
   ↓
[广告上线] → [前台按权重 + 时段轮播展示]
   ↓
[无感采集曝光/点击数据] → [后台统计报表]
```

---

## 四、角色权限矩阵表

| 功能模块 | 游客 | 注册用户 | 超级管理员 |
|---|:---:|:---:|:---:|
| 搜索资源 | ✅ | ✅ | ✅ |
| 查看资源标题/简介 | ✅ | ✅ | ✅ |
| 查看完整网盘链接 | ❌（需登录） | ✅（消耗积分） | ✅（免费） |
| 查看广告 | ✅ | ✅ | ✅ |
| 提交资源 | ❌ | ✅ | ✅ |
| 邮箱注册/登录 | ✅ | — | — |
| 每日签到 | ❌ | ✅ | — |
| 积分流水查看 | ❌ | ✅（本人） | ✅（全部） |
| 积分充值 | ❌ | ✅ | — |
| 订单查看 | ❌ | ✅（本人） | ✅（全部） |
| 个人中心 | ❌ | ✅ | — |
| 后台仪表盘 | ❌ | ❌ | ✅ |
| 资源管理（增删改） | ❌ | ❌ | ✅ |
| 网盘源管理 | ❌ | ❌ | ✅ |
| 爬虫任务管理 | ❌ | ❌ | ✅ |
| 用户管理 | ❌ | ❌ | ✅ |
| 订单管理/补单/退款 | ❌ | ❌ | ✅ |
| 积分套餐管理 | ❌ | ❌ | ✅ |
| 广告管理 | ❌ | ❌ | ✅ |
| 系统配置（SMTP/支付/站点） | ❌ | ❌ | ✅ |
| 日志查看 | ❌ | ❌ | ✅ |

---

## 五、数据库字段整理初稿

### 5.1 用户体系
```sql
-- users 用户表
id              BIGINT PK AUTO_INCREMENT
email           VARCHAR(100) UNIQUE NOT NULL    -- 登录邮箱
password        VARCHAR(255) NULL               -- 密码哈希(可选密码登录)
nickname        VARCHAR(50) NULL
avatar          VARCHAR(255) NULL
status          TINYINT DEFAULT 1               -- 1正常 0封禁
register_ip     VARCHAR(45) NULL
last_login_ip   VARCHAR(45) NULL
last_login_at   DATETIME NULL
create_time     DATETIME
update_time     DATETIME
delete_time     DATETIME NULL                   -- 软删除

-- user_credits 用户积分余额表
id              BIGINT PK
user_id         BIGINT UNIQUE NOT NULL
balance         INT DEFAULT 0                   -- 当前积分余额
total_recharge  INT DEFAULT 0                   -- 累计充值
total_consume   INT DEFAULT 0                   -- 累计消耗
update_time     DATETIME

-- credit_logs 积分流水表
id              BIGINT PK
user_id         BIGINT NOT NULL
type            TINYINT NOT NULL                -- 1充值 2消耗 3签到 4注册赠送 5提交奖励 6管理员调整
amount          INT NOT NULL                    -- 正数增加 负数消耗
balance_after   INT NOT NULL                    -- 变更后余额
related_id      BIGINT NULL                     -- 关联订单/资源ID
remark          VARCHAR(255) NULL
create_time     DATETIME
INDEX(user_id, create_time)

-- sign_in_records 签到记录表
id              BIGINT PK
user_id         BIGINT NOT NULL
sign_date       DATE NOT NULL
continuous_days INT DEFAULT 1                   -- 连续签到天数
credit_amount   INT DEFAULT 0
UNIQUE(user_id, sign_date)
```

### 5.2 邮箱验证码
```sql
-- email_verifies 邮箱验证码表（也可仅用 Redis，此表用于审计）
id              BIGINT PK
email           VARCHAR(100) NOT NULL
code            VARCHAR(10) NOT NULL
type            TINYINT NOT NULL                -- 1注册 2登录 3重置密码
ip              VARCHAR(45) NULL
expire_at       DATETIME NOT NULL
used            TINYINT DEFAULT 0
create_time     DATETIME
INDEX(email, type, used)
```

### 5.3 资源体系
```sql
-- resources 资源主表（去重后的统一资源）
id              BIGINT PK
title           VARCHAR(255) NOT NULL
cover           VARCHAR(255) NULL
intro           TEXT NULL
resource_type   TINYINT NOT NULL                -- 1影视 2音乐 3软件 4文档 5图片 6压缩包 7其他
file_size       BIGINT NULL                     -- 字节
file_format     VARCHAR(20) NULL                -- mp4/mp3/exe/pdf...
source_type     TINYINT NOT NULL                -- 1爬虫采集 2用户提交
submitter_id    BIGINT NULL                     -- 用户提交者ID
status          TINYINT DEFAULT 1               -- 1正常 0失效 2待审 3驳回
view_count      INT DEFAULT 0
link_view_count INT DEFAULT 0                   -- 完整链接查看次数
create_time     DATETIME
update_time     DATETIME
delete_time     DATETIME NULL
FULLTEXT(title, intro)                          -- 全文索引

-- resource_links 资源链接表（一个资源可能多源）
id              BIGINT PK
resource_id     BIGINT NOT NULL
pan_source_id   BIGINT NOT NULL                 -- 网盘源ID
share_url       VARCHAR(500) NOT NULL
extract_code    VARCHAR(20) NULL
url_hash        CHAR(32) UNIQUE NOT NULL        -- MD5(share_url) 去重
status          TINYINT DEFAULT 1               -- 1有效 0失效
create_time     DATETIME
INDEX(resource_id), INDEX(pan_source_id)

-- pan_sources 网盘源配置表
id              BIGINT PK
name            VARCHAR(50) NOT NULL            -- 百度网盘/阿里云盘...
code            VARCHAR(20) UNIQUE NOT NULL     -- baidu/aliyun/quark...
logo            VARCHAR(255) NULL
url_pattern     VARCHAR(500) NULL               -- 链接匹配正则
crawler_class   VARCHAR(100) NULL               -- 采集器类名
enabled         TINYINT DEFAULT 1
sort            INT DEFAULT 0
create_time     DATETIME

-- submissions 用户提交表（独立于 resources，审核通过后迁移）
id              BIGINT PK
user_id         BIGINT NOT NULL
title           VARCHAR(255) NOT NULL
share_url       VARCHAR(500) NOT NULL
extract_code    VARCHAR(20) NULL
pan_source_id   BIGINT NOT NULL
resource_type   TINYINT NOT NULL
intro           TEXT NULL
cover           VARCHAR(255) NULL
status          TINYINT DEFAULT 0               -- 0待审 1通过 2驳回
reject_reason   VARCHAR(255) NULL
reviewer_id     BIGINT NULL
review_at       DATETIME NULL
create_time     DATETIME
```

### 5.4 爬虫体系
```sql
-- crawl_tasks 采集任务表
id              BIGINT PK
name            VARCHAR(100) NOT NULL
pan_source_id   BIGINT NOT NULL
keywords        VARCHAR(500) NULL               -- 采集关键词(逗号分隔)
frequency       INT NOT NULL                    -- 执行间隔(分钟)
enabled         TINYINT DEFAULT 1
last_run_at     DATETIME NULL
next_run_at     DATETIME NULL
create_time     DATETIME

-- crawl_logs 采集日志表
id              BIGINT PK
task_id         BIGINT NOT NULL
status          TINYINT NOT NULL                -- 1成功 0失败
found_count     INT DEFAULT 0                   -- 本轮发现数
new_count       INT DEFAULT 0                   -- 新增入库数
error_msg       TEXT NULL
duration_ms     INT NULL
create_time     DATETIME
INDEX(task_id, create_time)
```

### 5.5 支付与订单
```sql
-- credit_packages 积分套餐表
id              BIGINT PK
name            VARCHAR(50) NOT NULL
price           DECIMAL(10,2) NOT NULL          -- 金额(元)
credits         INT NOT NULL                    -- 积分数
bonus           INT DEFAULT 0                   -- 赠送积分
is_recommended  TINYINT DEFAULT 0
status          TINYINT DEFAULT 1               -- 1上架 0下架
sort            INT DEFAULT 0
create_time     DATETIME

-- orders 订单表
id              BIGINT PK
order_no        VARCHAR(32) UNIQUE NOT NULL     -- 本站订单号
user_id         BIGINT NOT NULL
package_id      BIGINT NULL
amount          DECIMAL(10,2) NOT NULL          -- 实付金额
credits         INT NOT NULL                    -- 应到积分
status          TINYINT DEFAULT 0               -- 0待支付 1已支付 2已退款 3已关闭
pay_type        VARCHAR(20) NULL                -- alipay/wechat/qq
trade_no        VARCHAR(64) NULL                -- 彩虹易支付流水号
pay_at          DATETIME NULL
expire_at       DATETIME NOT NULL               -- 订单过期时间
create_time     DATETIME
update_time     DATETIME
INDEX(user_id, status), INDEX(order_no)

-- payment_logs 支付日志表
id              BIGINT PK
order_no        VARCHAR(32) NOT NULL
event           VARCHAR(20) NOT NULL            -- create/notify/sync/refund
request_data    TEXT NULL                       -- 原始请求/回调数据
response_data   TEXT NULL
ip              VARCHAR(45) NULL
create_time     DATETIME
INDEX(order_no)
```

### 5.6 广告体系
```sql
-- ad_slots 广告位表
id              BIGINT PK
code            VARCHAR(30) UNIQUE NOT NULL     -- home_banner/search_top/detail_popup/bottom_float
name            VARCHAR(50) NOT NULL
description     VARCHAR(255) NULL
enabled         TINYINT DEFAULT 1

-- ad_placements 广告投放表
id              BIGINT PK
slot_id         BIGINT NOT NULL
title           VARCHAR(100) NOT NULL
image_url       VARCHAR(255) NOT NULL
link_url        VARCHAR(500) NOT NULL
start_at       DATETIME NOT NULL
end_at          DATETIME NOT NULL
weight          INT DEFAULT 1                   -- 轮播权重
status          TINYINT DEFAULT 1               -- 1上线 0下线
impressions     BIGINT DEFAULT 0                -- 曝光数
clicks          BIGINT DEFAULT 0                -- 点击数
create_time     DATETIME
INDEX(slot_id, status)

-- ad_stats 广告统计表（按天聚合）
id              BIGINT PK
placement_id    BIGINT NOT NULL
stat_date       DATE NOT NULL
impressions     INT DEFAULT 0
clicks          INT DEFAULT 0
UNIQUE(placement_id, stat_date)
```

### 5.7 系统配置与日志
```sql
-- system_configs 系统配置表（KV 结构）
id              BIGINT PK
group           VARCHAR(30) NOT NULL            -- smtp/payment/site/credit/security
key             VARCHAR(50) UNIQUE NOT NULL
value           TEXT NULL
remark          VARCHAR(255) NULL
update_time     DATETIME

-- admin_users 管理员表
id              BIGINT PK
username        VARCHAR(50) UNIQUE NOT NULL
password        VARCHAR(255) NOT NULL
last_login_ip   VARCHAR(45) NULL
last_login_at   DATETIME NULL
status          TINYINT DEFAULT 1
create_time     DATETIME

-- admin_logs 管理员操作日志
id              BIGINT PK
admin_id        BIGINT NOT NULL
module          VARCHAR(30) NOT NULL
action          VARCHAR(50) NOT NULL
target_id       BIGINT NULL
detail          TEXT NULL                       -- 变更前后 JSON
ip              VARCHAR(45) NULL
create_time     DATETIME
INDEX(admin_id, create_time)

-- user_login_logs 用户登录日志
id              BIGINT PK
user_id         BIGINT NULL
email           VARCHAR(100) NULL
ip              VARCHAR(45) NULL
user_agent      VARCHAR(255) NULL
result          TINYINT NOT NULL                -- 1成功 0失败
create_time     DATETIME

-- sensitive_words 敏感词表
id              BIGINT PK
word            VARCHAR(100) NOT NULL
category        VARCHAR(30) NULL
create_time     DATETIME
```

---

## 六、开发建议 + 风险点提醒

### 6.1 技术架构建议
```
[前端: ThinkPHP8 模板 + 原生JS + 轻量CSS框架]
        ↓
[ThinkPHP8 后端 (多应用: index前台 / admin后台)]
        ↓
[MySQL 8.0 (FULLTEXT全文索引) + Redis 7 (缓存/限流/验证码/队列)]
        ↓
[队列 Worker (think-queue) : 爬虫采集 / 邮件发送 / 统计聚合]
        ↓
[第三方: PHPMailer(SMTP) + 彩虹易支付SDK + 各网盘采集器]
```

**目录结构建议**：
```
app/
├── index/           # 前台应用
│   ├── controller/  (Search, Resource, User, Order, Submit, Pay)
│   ├── model/       (User, Resource, Order, CreditLog...)
│   ├── service/     (SearchService, CreditService, PayService, MailService)
│   └── view/        (模板)
├── admin/           # 后台应用
│   ├── controller/  (Dashboard, Resource, PanSource, Crawl, User, Order, Ad, Config)
│   └── view/
├── common/          # 公共
│   ├── service/     (CrawlerService, SensitiveFilter, RateLimiter)
│   └── crawler/     (各网盘采集器: BaiduCrawler, AliyunCrawler...)
└── middleware/      (Auth, RateLimit, AdminAuth, Logger)
```

### 6.2 关键实现要点
1. **搜索性能**：高频搜索词结果缓存 5 分钟；FULLTEXT 索引覆盖 title+intro；预留 ES 适配层
2. **积分扣减**：使用数据库事务 + 乐观锁（balance_version），防超扣
3. **支付回调**：必须做签名校验 + 订单金额校验 + 幂等性处理（同订单号只处理一次）
4. **邮件发送**：异步队列发送，避免阻塞注册流程；失败自动重试 3 次
5. **爬虫防封**：单源并发控制 + 随机延时 + User-Agent 轮换 + 代理池（可选）
6. **限流策略**：搜索接口 IP 维度 60 次/分钟；发送验证码 60s/邮箱、5 次/10 分钟/IP
7. **敏感词过滤**：注册/提交/搜索均过滤，DFA 算法实现

### 6.3 风险点提醒

| 风险等级 | 风险点 | 应对措施 |
|:---:|---|---|
| 🔴 高 | **版权与合规风险**：聚合网盘分享资源可能涉及侵权，国内监管严格 | 1. 站点声明"仅提供链接索引，不存储文件" 2. 配合版权方 DMCA 下架 3. 不主动采集影视盗版资源 4. 建议海外服务器部署 5. 备案主体与运营主体隔离 |
| 🔴 高 | **爬虫法律风险**：采集网盘平台可能违反其 ToS / robots.txt | 1. 遵守各网盘 robots.txt 2. 仅采集公开分享页 3. 控制频率不构成 DOS 4. 保留可随时关闭各源的开关 |
| 🟡 中 | **彩虹易支付跑路风险**：第三方聚合支付稳定性参差 | 1. 多支付通道预留 2. 小额高频提现 3. 订单对账机制 |
| 🟡 中 | **SMTP 授权码泄露**：邮箱被盗可劫持账户体系 | 1. 授权码加密存储（AES） 2. 限制发信频率 3. 异常发信告警 |
| 🟡 中 | **爬虫被反爬**：网盘平台升级反爬导致采集失效 | 1. 采集器抽象成接口，单源失效不影响整体 2. 监控采集成功率，低于阈值告警 |
| 🟡 中 | **数据库压力**：资源量增长后 FULLTEXT 性能下降 | 1. 早期规划 ES 迁移 2. 历史资源归档分表 3. 搜索结果强缓存 |
| 🟢 低 | **广告位刷量**：广告主作弊刷曝光 | 1. IP+UA 去重 2. 异常流量识别 3. 仅统计有效曝光 |

### 6.4 开发优先级建议
**P0（MVP 必做，2-3 周）**：用户注册登录 + 搜索 + 资源详情 + 积分体系 + 彩虹易支付 + 基础后台
**P1（增强，1-2 周）**：爬虫采集 + 用户提交 + 广告管理 + 统计仪表盘
**P2（优化，持续）**：ES 接入 + 代理池 + 移动端适配 + SEO 优化

### 6.5 后续可迭代方向
- 移动端 H5 / 小程序
- 资源订阅与更新提醒
- 用户评论与评分
- 代理商分销体系
- API 开放平台
- 资源智能推荐（基于用户行为）

---

## 附录：15+ 网盘源清单

| # | 网盘名称 | code | 类型 |
|:---:|---|---|---|
| 1 | 百度网盘 | baidu | 主流 |
| 2 | 阿里云盘 | aliyun | 主流 |
| 3 | 夸克网盘 | quark | 主流 |
| 4 | 迅雷云盘 | xunlei | 主流 |
| 5 | 123 云盘 | pan123 | 主流 |
| 6 | 115 网盘 | pan115 | 主流 |
| 7 | 蓝奏云 | lanzou | 小众 |
| 8 | 天翼云盘 | ecloud | 小众 |
| 9 | 移动云盘 | yidong | 小众 |
| 10 | 腾讯微云 | weiyun | 小众 |
| 11 | UC 网盘 | uc | 小众 |
| 12 | 芒果云 | mango | 小众 |
| 13 | 奶牛快传 | cowtransfer | 小众 |
| 14 | 曲奇云盘 | cookie | 小众 |
| 15 | Firefox Send | firefoxsend | 小众 |

---

**文档完。** 本 PRD 已覆盖访谈六大交付物：访谈总结、功能清单、业务流程图、角色权限矩阵、数据库字段初稿、开发建议与风险提醒。确认无误后可进入架构设计与开发阶段。
