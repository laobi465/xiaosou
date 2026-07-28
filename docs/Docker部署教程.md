# Docker 一键部署教程

> 网盘资源搜索引擎 — 基于 Docker / Docker Compose 的生产级一键部署方案
>
> 版本：v1.0  |  适用：v1.0+  |  更新：2026-07-28

---

## 一、方案概览

### 1.1 架构拓扑

```
                  [浏览器 / 客户端]
                        │
                        ▼
        ┌──────────────────────────────┐
        │   Host :8080 (可改)           │
        │   pansou-app 容器             │
        │   ├─ Nginx (80)               │
        │   └─ PHP-FPM (9000)           │
        └──────────┬───────────────────┘
                   │
        ┌──────────┴───────────────────┐
        ▼                              ▼
┌────────────────┐            ┌────────────────┐
│ pansou-mysql   │            │ pansou-redis   │
│  MySQL 8.0     │            │  Redis 7       │
│  :3306         │            │  :6379         │
│  ngram_token=2 │            │  AOF 持久化     │
└────────────────┘            └────────────────┘
        ▲
        │
        ▼
┌────────────────────────────────────────┐
│   pansou-worker 容器                    │
│   ├─ php think crawl:consume (x4)      │  采集队列消费
│   ├─ php think mail:consume  (x2)      │  邮件队列消费
│   └─ crond                             │  定时任务（采集分发/订单关闭/广告聚合）
└────────────────────────────────────────┘
```

### 1.2 服务清单

| 服务名 | 镜像 | 端口 | 职责 |
|--------|------|------|------|
| `pansou-app` | 自构建（PHP-FPM+Nginx） | `8080:80` | Web 入口 |
| `pansou-worker` | 自构建（与 app 共用） | — | 队列消费 + Cron |
| `pansou-mysql` | `mysql:8.0` | `3306:3306` | 主数据库（ngram 中文索引） |
| `pansou-redis` | `redis:7-alpine` | `6379:6379` | 缓存 / 队列 / 会话 / 锁 |

### 1.3 镜像与依赖

- 基础镜像：`php:8.2-fpm-alpine`（约 50MB）
- PHP 扩展：`pdo_mysql` / `redis` / `gd` / `bcmath` / `opcache` / `zip` / `intl` / `mbstring` / `pcntl` / `sockets`
- 系统组件：`nginx` / `supervisor` / `mysql-client` / `crond` / `composer`
- 总镜像体积：约 300MB

> **注意**：Docker 部署路径**不走** Web 安装向导 `/install`。MySQL 容器启动时自动执行建库 SQL，app 容器 entrypoint 自动生成 .env 与管理员账号。如需手动重新初始化，请执行 `./docker-deploy.sh reset`。
>
> **SMTP 邮件 / 彩虹易支付配置说明**：Docker 部署通过 `.env.docker` 环境变量注入（容器内为 `.env`），**不走后台 system_configs 表**。这样保证容器无状态、可重建。如需修改：编辑宿主机 `.env.docker` 后执行 `./docker-deploy.sh restart`。登录后台 `/admin → 系统配置` 修改的 SMTP/支付配置仅写入容器内 MySQL，重建容器（`reset` 或删除卷）会丢失，建议优先使用 `.env.docker`。

---

## 二、环境准备

### 2.1 系统要求

| 项目 | 最低 | 推荐 |
|------|------|------|
| CPU | 1 核 | 2 核+ |
| 内存 | 1 GB | 2 GB+ |
| 磁盘 | 5 GB | 20 GB+ |
| 操作系统 | Linux（x86_64） | Ubuntu 22.04 / CentOS 8 / Debian 12 |

### 2.2 安装 Docker

#### Ubuntu / Debian

```bash
# 一键安装
curl -fsSL https://get.docker.com | bash -s docker --mirror Aliyun

# 启动并设置开机自启
sudo systemctl enable docker
sudo systemctl start docker

# 验证
docker --version
docker compose version
```

#### CentOS / RHEL

```bash
curl -fsSL https://get.docker.com | bash -s docker --mirror Aliyun
sudo systemctl enable --now docker
```

#### 国内镜像加速（可选）

```bash
sudo mkdir -p /etc/docker
sudo tee /etc/docker/daemon.json <<-'EOF'
{
  "registry-mirrors": [
    "https://docker.m.daocloud.io",
    "https://dockerproxy.com",
    "https://docker.nju.edu.cn"
  ],
  "log-driver": "json-file",
  "log-opts": { "max-size": "100m", "max-file": "3" }
}
EOF
sudo systemctl daemon-reload
sudo systemctl restart docker
```

---

## 三、一键部署

提供两种部署方式，按需选择：

### 3.0 真·一条命令（推荐，零交互）

无需预先 clone 仓库，无需手动编辑配置文件，一条命令完成全部部署：

```bash
curl -fsSL https://raw.githubusercontent.com/laobi465/xiaosou/main/install.sh | bash
```

自定义端口（默认 8080）：

```bash
curl -fsSL https://raw.githubusercontent.com/laobi465/xiaosou/main/install.sh | bash -s -- -p 9000
```

跳过确认提示（适合自动化脚本）：

```bash
curl -fsSL https://raw.githubusercontent.com/laobi465/xiaosou/main/install.sh | bash -s -- -y
```

> **宝塔面板用户专属说明**：脚本会自动检测宝塔环境（`/www/server/panel` 存在），并做以下智能适配，无需额外操作：
>
> | 适配项 | 说明 |
> |--------|------|
> | 部署目录 | 自动改为 `/www/wwwroot/pansou`（符合宝塔站点习惯，便于文件管理） |
> | Docker 安装 | Docker 未装时，提示可通过「宝塔软件商店 → Docker管理器」安装，或命令行自动装 |
> | 端口冲突避让 | 宝塔自带 MySQL(3306)/Redis(6379) 会被占用，脚本自动把 DB/Redis 暴露端口改为空闲高位端口（如 3326/6399），避免容器启动失败 |
> | 防火墙提醒 | 部署完成后提示在「宝塔面板 → 安全 → 防火墙」放行 Web 端口 |
> | 反代配置 | 提示通过「宝塔面板 → 网站 → 反向代理」配置域名 + HTTPS |
>
> 详细的宝塔专属操作见下方 [3.0.1 宝塔面板一键部署（专属指南）](#301-宝塔面板一键部署专属指南)。

#### 3.0.1 宝塔面板一键部署（专属指南）

适用场景：服务器已安装宝塔面板，希望通过 Docker 方式部署本系统。

**第 1 步：安装 Docker（二选一）**

- **方式 A（图形化，推荐新手）**：宝塔面板 → 软件商店 → 搜索「Docker管理器」→ 点击安装
- **方式 B（命令行）**：宝塔终端执行
  ```bash
  curl -fsSL https://get.docker.com | sh
  sudo systemctl enable --now docker
  ```

**第 2 步：宝塔终端执行一键命令**

```bash
curl -fsSL https://raw.githubusercontent.com/laobi465/xiaosou/main/install.sh | bash
```

脚本会自动识别宝塔环境，部署到 `/www/wwwroot/pansou`，并自动避开 3306/6379 端口冲突。

**第 3 步：宝塔防火墙放行端口（必须）**

部署完成后，脚本会输出 Web 端口（默认 8080）。在宝塔面板放行：

> 宝塔面板 → 安全 → 防火墙 → 添加端口规则 → 放行 `8080`（TCP）

> **重要**：宝塔防火墙默认不放行非标准端口，不放行则外网无法访问，但本机 `curl 127.0.0.1:8080` 正常。这是宝塔环境最常见的「部署成功但访问不了」问题。

**第 4 步：配置域名 + HTTPS（可选，推荐生产）**

通过宝塔反向代理，避免直接暴露 8080 端口：

1. 宝塔面板 → 网站 → 添加站点（填入你的域名，纯静态即可）
2. 站点设置 → 反向代理 → 添加反向代理
   - 代理名称：`pansou`
   - 目标 URL：`http://127.0.0.1:8080`
   - 发送域名：`$host`
3. 站点设置 → SSL → Let's Encrypt → 申请免费证书 → 强制 HTTPS

配置完成后，直接通过域名访问，无需带端口号。

**宝塔环境常见问题：**

| 现象 | 原因 | 解决 |
|------|------|------|
| 本机 curl 正常，外网打不开 | 宝塔防火墙未放行端口 | 宝塔面板 → 安全 → 防火墙 → 放行 8080 |
| 容器启动报 `port is already allocated` | 8080 被宝塔其他站点占用 | 重新执行 `install.sh -p 9000` 指定其他端口 |
| MySQL 容器启动失败 `bind: address already in use` | 3306 被宝塔自带 MySQL 占用 | 脚本已自动避让；若仍失败，编辑 `.env.docker` 改 `DB_EXPOSE_PORT` 为 0（不暴露） |
| 部署后想用宝塔自带 MySQL 而非容器 | — | 不建议，容器版已含 ngram 中文索引优化；如必须，请用「方式二：标准部署」并修改 `docker-compose.yml` 移除 mysql 服务 |



**部署完成后输出示例：**

```
=================================================
  部署完成！
=================================================

  前台访问:  http://192.168.1.100:8080
  后台访问:  http://192.168.1.100:8080/admin/login

  管理员账号:  admin
  管理员密码:  aB3xK9mP2qR7

  数据库密码:  见 ~/pansou-deploy/.env.docker （DB_PASSWORD）
  Redis 密码:   见 ~/pansou-deploy/.env.docker （REDIS_PASSWORD）

  请妥善保存以上凭据！

  项目目录:  /home/user/pansou-deploy

  常用运维命令:
    cd /home/user/pansou-deploy
    ./docker-deploy.sh status     # 查看服务状态
    ./docker-deploy.sh logs       # 查看实时日志
    ./docker-deploy.sh restart    # 重启服务
    ./docker-deploy.sh down       # 停止服务

  提醒：邮件 SMTP 与彩虹易支付未配置，
  如需启用注册验证码与积分充值，请编辑 .env.docker 后执行:
    vi .env.docker && ./docker-deploy.sh restart
```

**幂等性说明：**
- 再次执行 `install.sh` 会跳过 `.env.docker` 生成（保留既有密码），直接 `up -d`
- 如需重新生成密码：`rm .env.docker && ./install.sh`
- 如需完全重置（清空数据库）：`./docker-deploy.sh reset`

**卸载：**

```bash
cd ~/pansou-deploy
./docker-deploy.sh down        # 停止并删除容器
docker volume rm xiaosou_mysql-data xiaosou_redis-data xiaosou_app-runtime xiaosou_app-uploads  # 清空数据
cd ~ && rm -rf ~/pansou-deploy
```

---

### 3.1 标准部署（手动配置）

适合需要自定义每个配置项的场景。

#### 3.1.1 获取代码

```bash
git clone https://github.com/laobi465/xiaosou.git
cd xiaosou
```

#### 3.1.2 配置环境变量

```bash
# 复制样例文件
cp .env.docker.example .env.docker

# 编辑配置
vi .env.docker
```

**`.env.docker` 关键配置项：**

```bash
# ===== 必改项 =====

# Web 端口（默认 8080）
WEB_PORT=8080

# 数据库密码（强烈建议修改）
DB_PASSWORD=your_strong_password_here
DB_ROOT_PASSWORD=your_root_strong_password_here

# 管理员账号密码（首次启动自动创建）
ADMIN_USER=admin
ADMIN_PASSWORD=your_admin_password

# ===== 邮件配置（用于注册验证码） =====
MAIL_HOST=smtp.qq.com
MAIL_PORT=465
MAIL_USER=your_qq@qq.com
MAIL_PASS=your_smtp_auth_code    # QQ 邮箱授权码，非登录密码
MAIL_FROM=your_qq@qq.com

# ===== 彩虹易支付（用于积分充值） =====
PAY_PID=your_pid
PAY_KEY=your_key
PAY_API=https://your-pay-gateway.com
PAY_NOTIFY_URL=https://your-domain.com/pay/notify
PAY_RETURN_URL=https://your-domain.com/pay/return

# ===== 可选项 =====
# Redis 密码（留空则不启用）
REDIS_PASSWORD=
# 调试模式（生产环境必须 false）
APP_DEBUG=false
# CORS 跨域
CORS_ALLOW_ORIGIN=
```

#### 3.1.3 一键启动

```bash
chmod +x docker-deploy.sh
./docker-deploy.sh up
```

脚本会自动完成：
1. 读取 `.env.docker` 配置
2. 构建 Docker 镜像（首次约 5-10 分钟）
3. 启动 MySQL / Redis / app / worker 四个容器
4. MySQL 容器自动导入 `install.sql`（建表）+ `data.sql`（种子数据）
5. app 容器自动生成 `.env` 文件并创建管理员账号
6. 等待服务就绪后输出访问地址

#### 3.1.4 访问验证

启动成功后输出：

```
[部署] 完成！
  前台访问：http://localhost:8080
  后台访问：http://localhost:8080/admin/login
  默认管理员：admin / your_admin_password（请尽快修改）
```

打开浏览器访问 `http://服务器IP:8080`，应能看到搜索首页。
访问 `http://服务器IP:8080/admin/login`，使用管理员账号登录后台。

---

## 四、部署脚本命令一览

```bash
./docker-deploy.sh init       # 生成 .env.docker 配置文件（首次部署）
./docker-deploy.sh build      # 构建 Docker 镜像
./docker-deploy.sh up         # 构建并启动所有服务（默认）
./docker-deploy.sh down       # 停止所有服务
./docker-deploy.sh logs       # 查看实时日志
./docker-deploy.sh restart    # 重启服务
./docker-deploy.sh status     # 查看服务状态
./docker-deploy.sh shell      # 进入 app 容器 shell
./docker-deploy.sh reset      # 清空所有数据卷并停止服务（危险！）
```

---

## 五、手动操作（进阶）

### 5.1 直接使用 docker compose

如果不想使用部署脚本，可以直接操作：

```bash
# 启动（后台）
docker compose --env-file .env.docker up -d --build

# 查看状态
docker compose --env-file .env.docker ps

# 查看日志
docker compose --env-file .env.docker logs -f

# 停止
docker compose --env-file .env.docker down

# 重启
docker compose --env-file .env.docker restart

# 进入容器
docker compose --env-file .env.docker exec app /bin/bash
```

### 5.2 单独构建镜像

```bash
docker build -t pansou-app:latest .
```

### 5.3 单独启动 MySQL

```bash
docker run -d \
  --name pansou-mysql \
  -e MYSQL_ROOT_PASSWORD=pansou_root_2024 \
  -e MYSQL_DATABASE=pan_search \
  -e MYSQL_USER=pansou \
  -e MYSQL_PASSWORD=pansou_2024 \
  -v mysql-data:/var/lib/mysql \
  -v ./database/install.sql:/docker-entrypoint-initdb.d/01-install.sql:ro \
  -v ./database/data.sql:/docker-entrypoint-initdb.d/02-data.sql:ro \
  mysql:8.0 \
  --ngram_token_size=2 \
  --innodb_ft_min_token_size=2 \
  --character-set-server=utf8mb4 \
  --collation-server=utf8mb4_unicode_ci
```

---

## 六、数据卷与持久化

### 6.1 数据卷清单

| 卷名 | 挂载点 | 用途 | 备份建议 |
|------|--------|------|----------|
| `mysql-data` | `/var/lib/mysql` | MySQL 数据文件 | 每日全量备份 |
| `redis-data` | `/data` | Redis AOF 持久化 | 视数据重要性 |
| `app-runtime` | `/var/www/html/runtime` | 日志/缓存/会话 | 按需 |
| `app-uploads` | `/var/www/html/public/static/uploads` | 用户上传文件 | 每日增量备份 |

### 6.2 备份

```bash
# 备份 MySQL（全量）
docker exec pansou-mysql \
  mysqldump -uroot -p"${DB_ROOT_PASSWORD}" \
  --single-transaction --routines --triggers \
  pan_search > backup_$(date +%Y%m%d).sql

# 备份上传文件
docker run --rm -v app-uploads:/data -v $(pwd):/backup alpine \
  tar czf /backup/uploads_$(date +%Y%m%d).tar.gz -C /data .

# 备份所有卷
docker run --rm -v mysql-data:/data -v $(pwd):/backup alpine \
  tar czf /backup/mysql-data_$(date +%Y%m%d).tar.gz -C /data .
```

### 6.3 恢复

```bash
# 恢复 MySQL
docker exec -i pansou-mysql \
  mysql -uroot -p"${DB_ROOT_PASSWORD}" pan_search < backup_20260101.sql

# 恢复上传文件
docker run --rm -v app-uploads:/data -v $(pwd):/backup alpine \
  tar xzf /backup/uploads_20260101.tar.gz -C /data
```

---

## 七、生产环境加固

### 7.1 修改默认密码

编辑 `.env.docker`，至少修改以下项：

```bash
DB_PASSWORD=<强密码>
DB_ROOT_PASSWORD=<更强的密码>
REDIS_PASSWORD=<Redis密码>
ADMIN_PASSWORD=<管理员密码>
APP_KEY=<32位随机字符串>
AES_KEY=<32位随机字符串>
```

生成随机密钥：

```bash
# APP_KEY / AES_KEY
openssl rand -hex 16
# 或
php -r 'echo bin2hex(random_bytes(16));'
```

### 7.2 反向代理 + HTTPS

生产环境建议在 Docker 前再加一层宿主机 Nginx 反向代理，负责 SSL 终止：

```nginx
# /etc/nginx/conf.d/pansou.conf
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-domain.com;

    ssl_certificate     /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    client_max_body_size 20m;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

申请 Let's Encrypt 免费证书：

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
```

### 7.3 防火墙配置

仅开放必要端口：

```bash
# 开放 SSH / HTTP / HTTPS
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable

# 不要开放 3306 / 6379 到公网！
# 如需远程访问数据库，使用 SSH 隧道：
ssh -L 3306:127.0.0.1:3306 user@your-server
```

### 7.4 限制端口暴露

生产环境建议在 `.env.docker` 中关闭数据库与 Redis 的对外端口：

```bash
# 注释或置空（仅容器间通信，不暴露到宿主机）
# 编辑 docker-compose.yml，移除 mysql 和 redis 的 ports 映射
```

或修改 `docker-compose.yml`：

```yaml
mysql:
  # ports:
  #   - "3306:3306"   # 注释掉，仅容器内网访问
redis:
  # ports:
  #   - "6379:6379"
```

---

## 八、常用运维操作

### 8.1 查看日志

```bash
# 所有服务日志
./docker-deploy.sh logs

# 单个服务日志
docker logs -f pansou-app
docker logs -f pansou-worker
docker logs -f pansou-mysql
docker logs -f pansou-redis

# 应用运行时日志（在容器内）
docker exec pansou-app tail -f /var/www/html/runtime/log/$(date +%Y%m)/$(date +%d).log
```

### 8.2 重启服务

```bash
# 重启所有
./docker-deploy.sh restart

# 重启单个
docker restart pansou-app
docker restart pansou-worker
```

### 8.3 进入容器

```bash
# 进入 app 容器 shell
./docker-deploy.sh shell
# 或
docker exec -it pansou-app /bin/bash

# 进入 MySQL
docker exec -it pansou-mysql mysql -uroot -p

# 进入 Redis
docker exec -it pansou-redis redis-cli
```

### 8.4 执行 ThinkPHP 命令

```bash
# 在 app 容器内执行
docker exec -it pansou-app php think list            # 查看所有命令
docker exec -it pansou-app php think route:list      # 路由列表
docker exec -it pansou-app php think install --force # 重新初始化

# 手动触发采集任务分发
docker exec -it pansou-app php think crawl:dispatch

# 手动关闭超时订单
docker exec -it pansou-app php think order:close

# 手动聚合广告统计
docker exec -it pansou-app php think ad:agg
```

### 8.5 修改配置后重启

```bash
# 1. 修改 .env.docker
vi .env.docker

# 2. 重建并重启
./docker-deploy.sh down
./docker-deploy.sh up
```

---

## 九、升级与更新

### 9.1 拉取最新代码

```bash
git pull origin main
```

### 9.2 重建镜像并重启

```bash
./docker-deploy.sh down
./docker-deploy.sh build
./docker-deploy.sh up
```

### 9.3 执行数据库迁移（如有）

```bash
docker exec -it pansou-app php think migrate:run
```

---

## 十、故障排查

### 10.1 容器无法启动

```bash
# 查看容器状态
docker ps -a

# 查看退出日志
docker logs pansou-app
docker logs pansou-worker
```

### 10.2 数据库连接失败

```bash
# 检查 MySQL 容器健康状态
docker exec pansou-mysql mysqladmin -uroot -p${DB_ROOT_PASSWORD} ping

# 检查 app 容器能否连接 MySQL
docker exec pansou-app php -r "
\$pdo = new PDO('mysql:host=mysql;port=3306', 'pansou', getenv('DB_PASSWORD'));
echo 'MySQL 连接成功' . PHP_EOL;
"
```

### 10.3 Redis 连接失败

```bash
# 测试 Redis 连通性
docker exec pansou-app ping redis -c 3

# 进入 Redis 检查
docker exec -it pansou-redis redis-cli ping
```

### 10.4 队列不消费

```bash
# 查看 worker 状态
docker exec pansou-worker supervisorctl status

# 重启队列消费者
docker exec pansou-worker supervisorctl restart all

# 查看队列积压
docker exec pansou-redis redis-cli LLEN queues:crawl_queue
docker exec pansou-redis redis-cli LLEN queues:mail_queue
```

### 10.5 定时任务不执行

```bash
# 检查 crond 是否运行
docker exec pansou-worker supervisorctl status crond

# 查看定时任务日志
docker exec pansou-worker cat /var/www/html/runtime/log/cron-dispatch.log

# 手动测试 crontab
docker exec pansou-worker crontab -l
```

### 10.6 502 Bad Gateway

通常是 PHP-FPM 未启动或崩溃：

```bash
# 检查 PHP-FPM 状态
docker exec pansou-app supervisorctl status php-fpm

# 重启 PHP-FPM
docker exec pansou-app supervisorctl restart php-fpm

# 检查 PHP 错误日志
docker exec pansou-app cat /proc/self/fd/2 | tail -100
```

### 10.7 文件上传失败

```bash
# 检查上传目录权限
docker exec pansou-app ls -la /var/www/html/public/static/uploads/

# 修复权限
docker exec pansou-app chown -R www-data:www-data /var/www/html/public/static/uploads
docker exec pansou-app chmod -R 0775 /var/www/html/public/static/uploads
```

---

## 十一、性能调优

### 11.1 PHP-FPM 进程数

根据服务器内存调整 `docker/php-fpm.conf`：

```ini
; 内存 1GB
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6

; 内存 2GB+
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 2
pm.max_spare_servers = 10
```

### 11.2 MySQL 缓冲池

编辑 `docker/mysql-conf.cnf`：

```ini
# 内存 2GB
innodb_buffer_pool_size = 512M

# 内存 4GB+
innodb_buffer_pool_size = 2G
```

### 11.3 Redis 内存

编辑 `docker-compose.yml` 中 redis 服务的 `command`：

```yaml
redis:
  command: redis-server --maxmemory 512mb --maxmemory-policy allkeys-lru
```

### 11.4 Worker 进程数

编辑 `docker/worker-supervisord.conf`：

```ini
# 采集队列（CPU 密集型，建议 = CPU 核数）
[program:crawl-consume]
numprocs=4

# 邮件队列（IO 密集型，可适当增加）
[program:mail-consume]
numprocs=4
```

---

## 十二、完全卸载

```bash
# 1. 停止并删除容器
./docker-deploy.sh down

# 2. 删除数据卷（危险！会清空所有数据）
docker volume rm xiaosou_mysql-data xiaosou_redis-data xiaosou_app-runtime xiaosou_app-uploads

# 3. 删除镜像
docker rmi pansou-app:latest

# 4. 删除项目
cd ..
rm -rf xiaosou
```

---

## 十三、常见问题（FAQ）

### Q1：首次启动很慢，卡在 "等待 MySQL 就绪"？

**A**：MySQL 首次启动需要初始化数据文件并导入 SQL，通常需要 30-60 秒。如果超过 2 分钟仍未就绪，请检查服务器内存是否足够（至少 1GB）。

### Q2：如何修改后台默认管理员密码？

**A**：登录后台 → 系统设置 → 修改密码。或命令行：

```bash
docker exec -it pansou-app php -r "
require __DIR__.'/vendor/autoload.php';
\$app = new \think\App(__DIR__);
\$app->initialize();
\think\facade\Db::name('admin_users')
    ->where('username', 'admin')
    ->update(['password' => password_hash('new_password', PASSWORD_BCRYPT)]);
echo '密码已修改' . PHP_EOL;
"
```

### Q3：如何修改 Web 端口？

**A**：编辑 `.env.docker` 中的 `WEB_PORT`，然后 `./docker-deploy.sh down && ./docker-deploy.sh up`。

### Q4：MySQL 数据库如何远程连接？

**A**：不推荐。如必须，使用 SSH 隧道：

```bash
ssh -L 3306:127.0.0.1:3306 user@your-server
# 然后本地用 Navicat 连接 127.0.0.1:3306
```

### Q5：邮件发送失败？

**A**：检查 `.env.docker` 中的 `MAIL_*` 配置。QQ 邮箱使用授权码而非登录密码。测试命令：

```bash
docker exec -it pansou-app php -r "
require __DIR__.'/vendor/autoload.php';
\$app = new \think\App(__DIR__);
\$app->initialize();
\$mail = new \app\common\service\MailService();
\$mail->send('test@example.com', '测试', '这是一封测试邮件');
echo '发送完成' . PHP_EOL;
"
```

### Q6：彩虹易支付回调失败？

**A**：确保 `PAY_NOTIFY_URL` 是公网可访问的 HTTPS 地址，且端口已开放。回调地址格式：`https://your-domain.com/pay/notify`。

### Q7：如何查看慢查询？

**A**：MySQL 容器已开启慢查询日志（>2 秒）：

```bash
docker exec pansou-mysql cat /var/lib/mysql/slow.log | tail -100
```

### Q8：磁盘空间不足？

**A**：清理 Docker 无用资源：

```bash
docker system prune -a --volumes
# 谨慎：会删除所有未使用的镜像和卷
```

清理应用日志：

```bash
docker exec pansou-app find /var/www/html/runtime/log -mtime +30 -delete
```

---

## 十四、文件清单

```
项目根目录/
├── Dockerfile                       # 镜像构建文件
├── docker-compose.yml               # 服务编排
├── docker-deploy.sh                 # 一键部署脚本
├── .env.docker.example              # 环境变量样例
├── .dockerignore                    # Docker 构建忽略清单
└── docker/
    ├── nginx.conf                   # Nginx 配置
    ├── supervisord.conf             # App 容器 Supervisor 配置
    ├── worker-supervisord.conf      # Worker 容器 Supervisor 配置
    ├── php.ini                      # PHP 自定义配置
    ├── php-fpm.conf                 # PHP-FPM 配置
    ├── crontab                      # 定时任务清单
    ├── mysql-conf.cnf               # MySQL 自定义配置
    ├── entrypoint.sh                # App 容器入口脚本
    └── worker-entrypoint.sh         # Worker 容器入口脚本
```

---

## 十五、技术支持

- 项目仓库：https://github.com/laobi465/xiaosou
- 问题反馈：https://github.com/laobi465/xiaosou/issues
- 文档版本：v1.0（2026-07-28）

如部署过程中遇到问题，请提交 Issue 并附上：
1. 服务器操作系统版本
2. Docker 版本（`docker version`）
3. 完整的错误日志（`docker logs pansou-app`）
4. `.env.docker` 配置（请隐去密码）
