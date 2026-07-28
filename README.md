# 网盘资源搜索引擎 (Pan Search)

> 轻量级网盘资源聚合搜索引擎 - 原生 PHP 模板 + ThinkPHP8 + MySQL8 + Redis7

## 项目简介

一键搜索全网 15+ 主流与小众网盘的公开分享资源，免登录即可搜，登录后按积分消耗查看完整链接。

## 技术栈

- PHP 8.2+ / ThinkPHP 8.0
- MySQL 8.0 (FULLTEXT + ngram 中文分词)
- Redis 7.0 (缓存/限流/会话/队列)
- PHPMailer 6.x / Guzzle 7.x / 彩虹易支付

## 环境要求

- PHP >= 8.2 (ext-pdo, ext-redis, ext-json, ext-mbstring, ext-openssl)
- MySQL >= 8.0 (ngram_token_size=2, innodb_ft_min_token_size=2)
- Redis >= 6.0
- Composer 2.x

## 快速开始

### 1. 安装依赖

```bash
composer install
```

### 2. 一键安装

```bash
php think install
```

交互式输入数据库连接、Redis、SMTP、支付密钥、管理员账号密码。安装命令会：
- 生成 `.env` 配置文件
- 创建数据库并执行 `database/install.sql` 建表
- 导入 `database/data.sql` 种子数据
- 预热 Redis 缓存

### 3. 启动开发服务器

```bash
php -S 127.0.0.1:8000 -t public/
```

访问 http://127.0.0.1:8000

### 4. 后台访问

访问 http://127.0.0.1:8000/admin/login
默认管理员账号：admin / admin123（**部署后请立即修改**）

## 目录结构

```
├── app/                    # 应用目录
│   ├── index/              # 前台应用 (C端)
│   ├── admin/              # 后台应用 (管理端)
│   ├── api/                # API 应用 (预留)
│   ├── common/             # 公共层
│   │   ├── model/          # 26 个数据模型
│   │   ├── service/        # 12 个业务服务
│   │   ├── crawler/        # 15 个网盘采集器
│   │   ├── enum/           # 枚举常量
│   │   └── exception/      # 业务异常
│   ├── middleware/         # 全局中间件
│   ├── command/            # 命令行
│   └── BaseController.php  # 控制器基类
├── config/                 # 配置文件
├── route/                  # 路由
├── public/                 # Web 根
├── database/               # 数据库脚本
│   ├── install.sql         # 建库建表 DDL
│   └── data.sql            # 种子数据
├── extend/Pansou/          # 扩展类库
│   ├── Pay/                # 彩虹易支付 SDK
│   ├── Mail/               # SMTP 封装
│   ├── Search/             # 搜索驱动
│   ├── Helper/             # 工具类
│   └── Sensitive/          # 敏感词过滤
└── docs/                   # 设计文档
    ├── PRD-网盘资源搜索引擎.md
    ├── 架构设计文档.md
    └── 数据库设计文档.md
```

## 命令行工具

```bash
php think install          # 一键安装
php think crawl:dispatch   # 采集任务分发
php think crawl:consume    # 采集队列消费
php think mail:consume     # 邮件队列消费
php think ad:agg           # 广告统计聚合
php think order:close      # 超时订单关闭
php think route:list       # 查看路由
php think list             # 查看所有命令
```

## 定时任务配置

```bash
# crontab -e
* * * * * cd /path && php think crawl:dispatch
* * * * * cd /path && php think order:close
0 0 * * * cd /path && php think ad:agg
0 * * * * cd /path && php think verify:clean
```

## 队列消费（supervisor）

```ini
[program:pan-crawl]
command=php think crawl:consume
numprocs=4
autostart=true
autorestart=true

[program:pan-mail]
command=php think mail:consume
numprocs=2
autostart=true
autorestart=true
```

## 文档

- [PRD 需求文档](docs/PRD-网盘资源搜索引擎.md)
- [架构设计文档](docs/架构设计文档.md)
- [数据库设计文档](docs/数据库设计文档.md)

## 默认配置

- 注册赠送积分：10
- 签到奖励：1 积分/天
- 查看链接消耗：1 积分/次
- 提交审核通过奖励：5 积分
- 订单过期时间：30 分钟

## License

MIT
