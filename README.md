# 网盘资源搜索引擎 (Pan Search)

> 轻量级网盘资源聚合搜索引擎 —— 原生 PHP 模板 + ThinkPHP 8 + MySQL 8 + Redis 7
>
> 一键搜索 15+ 主流与小众网盘的公开分享资源，免登录即可搜，登录后按积分消耗查看完整链接。

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-8892BF.svg)](https://www.php.net/)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-8.0-red.svg)](https://www.thinkphp.cn/)
[![MySQL](https://img.shields.io/badge/MySQL-%3E%3D8.0-4479A1.svg)](https://www.mysql.com/)
[![Redis](https://img.shields.io/badge/Redis-%3E%3D6.0-DC382D.svg)](https://redis.io/)

---

## 项目特色

- **混合搜索模式**：爬虫自动采集 + 用户手动提交，统一索引去重
- **三角色体系**：游客 / 注册用户 / 超级管理员
- **积分商业模式**：注册赠送 + 签到奖励 + 充值消耗 + 提交奖励
- **新拟物柔和 UI**：浅色柔和背景 + 双向阴影 + 圆角 + 温暖色调
- **15 家网盘覆盖**：百度/阿里/夸克/迅雷/123/115/蓝奏/天翼/移动/微云/UC/芒果/奶牛/Cookie/FirefoxSend
- **生产级安全**：CSRF 防护 / SQL 注入防御 / XSS 转义 / 限流 / 支付验签 / 防重放
- **高并发安全**：乐观锁 + 分布式锁 + Lua 原子限流 + 订单 CAS 流转
- **完整异步队列**：采集 / 邮件 / 统计 三通道独立队列

## 技术栈

| 组件 | 版本 | 用途 |
|------|------|------|
| PHP | >= 8.2 | 运行时 |
| ThinkPHP | 8.0 | MVC 框架 |
| MySQL | >= 8.0 | 主数据库（FULLTEXT ngram 中文索引） |
| Redis | >= 6.0 | 缓存 / 限流 / 会话 / 队列 / 分布式锁 |
| PHPMailer | 6.x | SMTP 邮件发送 |
| Guzzle | 7.x | HTTP 客户端（爬虫） |
| think-queue | 3.x | 队列（Redis 驱动） |

## 环境要求

- PHP >= 8.2（ext-pdo, ext-redis, ext-json, ext-mbstring, ext-openssl）
- MySQL >= 8.0（需配置 `ngram_token_size=2`, `innodb_ft_min_token_size=2`）
- Redis >= 6.0
- Composer 2.x

## 快速开始

### 1. 克隆仓库

```bash
git clone https://github.com/laobi465/xiaosou.git
cd xiaosou
```

### 2. 安装依赖

```bash
composer install
```

### 3. 一键安装

```bash
php think install
```

安装命令会交互式引导你完成：
- 数据库连接配置（host/port/database/username/password）
- Redis 连接配置（host/port/password）
- SMTP 邮件配置（host/port/user/pass/from）
- 彩虹易支付配置（pid/key/api）
- 管理员账号密码设置
- 自动生成 `.env`、建库建表、导入种子数据、预热缓存

### 4. 启动开发服务器

```bash
php -S 127.0.0.1:8000 -t public/
```

访问 http://127.0.0.1:8000

### 5. 后台访问

访问 http://127.0.0.1:8000/admin/login

默认管理员账号：`admin` / `admin123`（**部署后请立即修改**）

## 部署

提供两种部署方式，按需选择：

### 方式一：Docker 一键部署（推荐）

最快 5 分钟即可上线，无需手动配置 PHP / Nginx / MySQL / Redis 环境。

```bash
# 1. 克隆代码
git clone https://github.com/laobi465/xiaosou.git
cd xiaosou

# 2. 配置环境变量
cp .env.docker.example .env.docker
vi .env.docker            # 修改数据库密码、邮件、支付、管理员账号等

# 3. 一键启动
chmod +x docker-deploy.sh
./docker-deploy.sh up
```

启动完成后访问 `http://服务器IP:8080` 即可。

详细教程请参阅 **[Docker 部署教程](docs/Docker部署教程.md)**。

包含服务：
- `pansou-app` — PHP-FPM + Nginx（Web 入口）
- `pansou-worker` — 队列消费者 + Crontab 定时任务
- `pansou-mysql` — MySQL 8.0（ngram 中文索引）
- `pansou-redis` — Redis 7（缓存/队列/会话/锁）

部署脚本命令：

```bash
./docker-deploy.sh up       # 构建并启动
./docker-deploy.sh down     # 停止
./docker-deploy.sh logs     # 查看日志
./docker-deploy.sh restart  # 重启
./docker-deploy.sh status   # 查看状态
./docker-deploy.sh shell    # 进入容器
./docker-deploy.sh reset    # 重置数据（清空数据库）
```

### 方式二：宝塔面板部署

适合已有宝塔面板的服务器，需要手动配置环境。

请参阅 **[宝塔面板部署教程](docs/宝塔面板部署教程.md)**。

主要步骤：
1. 宝塔面板安装 LNMP 环境（PHP 8.2+ / MySQL 8.0 / Redis）
2. 配置 MySQL `ngram_token_size=2`
3. 添加站点，运行 `php think install`
4. 配置 Supervisor 守护队列消费者
5. 配置 Crontab 定时任务
6. 配置 SSL 证书

## 目录结构

```
├── app/                    # 应用目录
│   ├── index/              # 前台应用 (C端)
│   │   ├── controller/     # 10 个前台控制器
│   │   ├── middleware/     # 前台中间件 (UserAuth/RateLimit/PayIpWhitelist/VisitorLog)
│   │   └── view/           # 前台模板
│   ├── admin/              # 后台应用 (管理端)
│   │   ├── controller/     # 13 个后台控制器
│   │   ├── middleware/     # 后台中间件 (AdminAuth)
│   │   └── view/           # 后台模板
│   ├── api/                # API 应用 (预留)
│   ├── common/             # 公共层
│   │   ├── model/          # 27 个数据模型
│   │   ├── service/        # 12 个业务服务
│   │   ├── crawler/        # 15 个网盘采集器
│   │   ├── enum/           # 9 个枚举常量
│   │   ├── validate/       # 9 个验证器
│   │   └── exception/      # 业务异常
│   ├── middleware/         # 全局中间件 (CheckCsrf/GlobalLog/LoadConfig/RequestId)
│   ├── command/            # 6 个命令行工具
│   └── BaseController.php  # 控制器基类
├── config/                 # 配置文件
├── route/                  # 路由 (index/admin/api)
├── public/                 # Web 根
│   └── static/             # 静态资源 (css/js/img)
├── database/               # 数据库脚本
│   ├── install.sql         # 建库建表 DDL (27 张表)
│   └── data.sql            # 种子数据 (15 家网盘源/4 广告位/4 套餐)
├── extend/Pansou/          # 扩展类库
│   ├── Pay/                # 彩虹易支付 SDK
│   ├── Mail/               # SMTP 封装
│   ├── Search/             # 搜索驱动 (MySQL FULLTEXT / Elasticsearch)
│   ├── Helper/             # 工具类 (加密/签名/哈希)
│   └── Sensitive/          # 敏感词 DFA 过滤
├── docker/                 # Docker 部署配置
│   ├── nginx.conf          # Nginx 配置
│   ├── supervisord.conf    # App 容器 Supervisor 配置
│   ├── worker-supervisord.conf  # Worker 容器 Supervisor 配置
│   ├── php.ini             # PHP 自定义配置
│   ├── php-fpm.conf        # PHP-FPM 配置
│   ├── crontab             # 定时任务清单
│   ├── mysql-conf.cnf      # MySQL 自定义配置 (ngram)
│   ├── entrypoint.sh       # App 容器入口脚本
│   └── worker-entrypoint.sh  # Worker 容器入口脚本
├── Dockerfile              # Docker 镜像构建文件
├── docker-compose.yml      # Docker Compose 服务编排
├── docker-deploy.sh        # 一键部署脚本
├── .env.docker.example     # Docker 环境变量样例
└── docs/                   # 设计文档
    ├── PRD-网盘资源搜索引擎.md
    ├── 架构设计文档.md
    ├── 数据库设计文档.md
    ├── 宝塔面板部署教程.md
    └── Docker部署教程.md
```

## 功能模块

### 前台（C 端）

| 模块 | 功能 |
|------|------|
| 首页 | 热搜词、最新资源、广告位 |
| 搜索 | 关键词搜索、网盘源筛选、文件大小筛选、时间筛选、分页 |
| 资源详情 | 资源信息、相关推荐、查看链接（消耗积分）、举报失效 |
| 用户认证 | 邮箱验证码注册/登录、密码登录、Session 固定防护 |
| 用户中心 | 个人资料、积分流水、签到、我的订单、我的提交 |
| 资源提交 | 用户提交网盘资源，审核通过奖励积分 |
| 积分充值 | 套餐选择、彩虹易支付、订单管理 |
| 广告系统 | 广告位展示、点击统计、曝光统计 |

### 后台（管理端）

| 模块 | 功能 |
|------|------|
| 仪表盘 | 资源数/用户数/订单数/收入统计 |
| 资源管理 | 增删改查、批量操作、标记失效、链接转移 |
| 网盘源管理 | 15 家网盘源配置、采集器类名、启用/禁用 |
| 采集任务 | 定时采集任务管理、手动触发、采集日志 |
| 提交审核 | 用户提交审核、通过/拒绝、奖励发放 |
| 用户管理 | 用户列表、积分调整、启用/封禁、详情 |
| 订单管理 | 订单列表、手动补单、退款处理 |
| 套餐管理 | 积分套餐增删改查、上下架、推荐 |
| 广告管理 | 广告位、投放管理、点击/曝光统计 |
| 系统配置 | KV 配置、分组管理、白名单校验 |
| 敏感词管理 | 敏感词增删改查、批量导入 |
| 日志系统 | 管理员操作日志、用户登录日志、支付日志、异常日志 |

## 命令行工具

```bash
php think install          # 一键安装
php think crawl:dispatch   # 采集任务分发（定时）
php think crawl:consume    # 采集队列消费（守护）
php think mail:consume     # 邮件队列消费（守护）
php think ad:agg           # 广告统计聚合 + 热搜词归档（定时）
php think order:close      # 关闭超时未支付订单（定时）
php think route:list       # 查看路由清单
php think list             # 查看所有命令
```

## 异步任务配置

### Supervisor（队列消费者）

```ini
[program:pan-crawl]
command=php /path/to/xiaosou/think crawl:consume
directory=/path/to/xiaosou
numprocs=4
autostart=true
autorestart=true
user=www
redirect_stderr=true
stdout_logfile=/path/to/xiaosou/runtime/log/supervisor-crawl.log

[program:pan-mail]
command=php /path/to/xiaosou/think mail:consume
directory=/path/to/xiaosou
numprocs=2
autostart=true
autorestart=true
user=www
redirect_stderr=true
stdout_logfile=/path/to/xiaosou/runtime/log/supervisor-mail.log
```

### Crontab（定时任务）

```bash
# crontab -e -u www
* * * * * cd /path/to/xiaosou && php think crawl:dispatch
* * * * * cd /path/to/xiaosou && php think order:close
0 0 * * * cd /path/to/xiaosou && php think ad:agg
0 * * * * cd /path/to/xiaosou && php think verify:clean
```

## 安全特性

| 特性 | 实现 |
|------|------|
| CSRF 防护 | 全局中间件 + 模板 token + JS 自动注入 |
| SQL 注入防御 | 全部参数化查询，无 raw 拼接 |
| XSS 防御 | 模板 `htmlspecialchars` 转义 |
| 限流 | Redis 滑动窗口 + Lua 原子操作 |
| 密码加密 | `password_hash(PASSWORD_BCRYPT)` |
| AES 加密 | AES-256-GCM，32 字节密钥 |
| 支付安全 | 签名校验 + 金额一致 + 防重放 + IP 白名单 |
| Session 安全 | httponly + secure + samesite + regenerate |
| 敏感词过滤 | DFA 算法 + fail-closed 策略 |
| 并发安全 | 乐观锁 + 分布式锁 + CAS 状态流转 |
| 幂等性 | 支付回调 + 邮件 + 采集任务 |

## 文档

- [PRD 需求文档](docs/PRD-网盘资源搜索引擎.md)
- [架构设计文档](docs/架构设计文档.md)
- [数据库设计文档](docs/数据库设计文档.md)
- [Docker 部署教程](docs/Docker部署教程.md)（推荐）
- [宝塔面板部署教程](docs/宝塔面板部署教程.md)

## 默认配置

| 配置项 | 默认值 |
|--------|--------|
| 注册赠送积分 | 10 |
| 签到奖励 | 1 积分/天 |
| 查看链接消耗 | 1 积分/次 |
| 提交审核通过奖励 | 5 积分 |
| 订单过期时间 | 30 分钟 |
| 登录失败锁定 | 5 次失败锁 15 分钟 |
| 后台分页 | 15 条/页 |
| 前台分页 | 15 条/页 |
| 慢请求阈值 | 1000ms |

## 浏览器支持

- Chrome >= 90
- Firefox >= 88
- Safari >= 14
- Edge >= 90

## License

[MIT](LICENSE)

## 致谢

- [ThinkPHP](https://www.thinkphp.cn/)
- [PHPMailer](https://github.com/PHPMailer/PHPMailer)
- [Guzzle](https://github.com/guzzle/guzzle)
- 思源黑体 / 思源宋体 / JetBrains Mono
