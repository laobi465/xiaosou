# SSH 一键部署脚本重设计计划

## 一、需求摘要

删除现有 install.sh，重新设计一个 SSH 一键安装脚本（无需上传源码），实现：

1. 检测宝塔面板是否安装，**未安装则自动拉取宝塔官方安装脚本安装**
2. 检测并安装 Docker
3. 把项目部署到宝塔环境下的 Docker 中（部署目录 /www/wwwroot/pansou）
4. **固定超管账密** `admin@example.com / admin123`（用户明确要求）
5. **端口冲突检测**：仅 Web 端口（默认 8080）被占用时自动 +1 递增（8081、8082...）；**DB/Redis 不暴露端口**到宿主机
6. 部署完成后输出：宝塔面板配置 + 项目配置 + 超管账密 + 后台地址
7. 所有凭据与配置**保存到 /root/pansou-deploy-info.txt**
8. 不使用假数据硬编码占位（密码、端口均真实生成/检测）

## 二、当前状态分析（基于 Phase 1 探索）

### 现有文件
- `/workspace/install.sh`（532 行）：现有的一键脚本，需**整体重写**
- `/workspace/docker-compose.yml`（192 行）：mysql/redis 服务有 `ports` 暴露段（`${DB_EXPOSE_PORT:-3306}:3306` / `${REDIS_EXPOSE_PORT:-6379}:6379`），需调整以支持"不暴露"
- `/workspace/docker/entrypoint.sh`：app 容器入口，已支持 `ADMIN_EMAIL`/`ADMIN_PASSWORD` 环境变量创建管理员
- `/workspace/.env.docker.example`：样例，含 `DB_EXPOSE_PORT=3306` 默认值
- `/workspace/docs/Docker部署教程.md`：3.0 与 3.0.1 节有宝塔部署说明，需同步更新

### docker-compose 端口暴露的技术约束
docker-compose 的 override 合并规则对 `ports` 是"唯一资源按目标端口去重"，**无法通过 override 移除主文件的端口映射**。因此"DB/Redis 不暴露"必须直接修改主 docker-compose.yml，将 mysql/redis 的 `ports` 段移除（改为注释说明），默认仅容器内网络通信。

### 宝塔面板安装命令
官方国内版：
```bash
curl -sSO https://download.bt.cn/install/install_panel.sh && bash install_panel.sh ed8484bec
```
安装脚本交互式，需 `echo y |` 管道自动确认。安装耗时 10-30 分钟，会自动装 Nginx/MySQL/PHP/防火墙等。
安装完成后信息文件：
- `/www/server/panel/data/port.pl`：面板端口
- `/www/server/panel/default.pl`：默认面板密码
- 面板用户名默认 `admin`，面板入口随机（保存在 `/www/server/panel/data/admin_path.pl`）

### 管理员账号创建机制
entrypoint.sh 通过 `ADMIN_EMAIL`/`ADMIN_PASSWORD` 环境变量，在 app 容器首次启动时用内嵌 PHP 写 `admin_users` 表（`password_hash` BCRYPT）。注意 entrypoint.sh 当前用的是 `ADMIN_USER`（用户名）字段，而用户要的是邮箱 `admin@example.com` 登录——需确认登录字段。

## 三、需要确认的细节（执行时处理，非阻塞）

1. **登录字段**：用户要 `admin@example.com` 登录。需检查 admin_users 表结构与登录控制器，确认用 username 还是 email 字段。若用 username，则 username=admin@example.com；若 email 字段独立，则两者都填。执行时先读 admin_users 表结构与登录控制器确定。

## 四、具体改动（文件级）

### 改动 1：重写 `/workspace/install.sh`（核心）

**整体替换**为全新脚本，主要阶段：

```
[阶段 0] 管道模式自我重启（保留现有机制，避免 curl|bash 的 SSH 断开）
[阶段 1] 检测宝塔面板
         - 探测 /www/server/panel 是否存在
         - 不存在 → 拉取宝塔官方安装脚本，echo y | 自动确认安装
         - 安装后读取 /www/server/panel/data/{port.pl,default.pl,admin_path.pl}
         - 记录宝塔面板地址/账号/密码到变量 BT_PANEL_URL/BT_PANEL_USER/BT_PANEL_PASS
[阶段 2] 检测并安装 Docker
         - 有 docker → 跳过
         - 无 docker → curl -fsSL https://get.docker.com | sh + systemctl enable --now
         - 启动后 sleep 5 缓冲（避免 SSH 断开）
[阶段 3] 克隆项目到 /www/wwwroot/pansou（git clone --depth=1）
[阶段 4] Web 端口冲突检测（+1 递增）
         - pick_web_port: 从 8080 起，被占用则 8081、8082... 直到空闲（上限 8999）
         - 用 ss/netstat 检测
[阶段 5] 生成 .env.docker
         - 固定 ADMIN_EMAIL=admin@example.com / ADMIN_PASSWORD=admin123
         - ADMIN_USER=admin（若登录用 username）
         - DB/Redis 密码用 openssl 随机生成（真实强密码，非硬编码）
         - DB_EXPOSE_PORT=0 / REDIS_EXPOSE_PORT=0（标记不暴露，实际由 compose 不映射）
         - APP_KEY / AES_KEY 用 openssl 随机生成
         - 幂等：已存在 .env.docker 则保留（仅重读 WEB_PORT）
[阶段 6] docker compose up -d --build（在 /www/wwwroot/pansou 执行）
[阶段 7] 等待健康检查（mysql/redis/app 轮询）
[阶段 8] 输出配置到终端 + 写入 /root/pansou-deploy-info.txt
```

**关键函数**：
- `install_baota()`：检测+安装宝塔，读取面板信息
- `pick_web_port()`：8080 起 +1 递增（替代原 pick_free_port 的高位区间逻辑）
- `generate_env()`：固定超管 + 随机 DB/Redis 密码 + DB/Redis 不暴露
- `save_info_file()`：写 /root/pansou-deploy-info.txt（chmod 600）
- `print_summary()`：终端彩色输出

**/root/pansou-deploy-info.txt 内容结构**：
```
============================================
 网盘资源搜索引擎 - 部署信息
 生成时间: YYYY-MM-DD HH:MM:SS
============================================

【宝塔面板】
面板地址: http://IP:端口/入口
账号: admin
密码: xxxxx
（若已预装宝塔，标注"已预装，使用既有账号"）

【项目访问】
前台地址: http://IP:WEB端口/
后台地址: http://IP:WEB端口/admin/login
超管邮箱: admin@example.com
超管密码: admin123

【项目目录】 /www/wwwroot/pansou

【数据库（容器内，不对外暴露）】
数据库名: pan_search
用户名: pansou
密码: <随机生成>
Root密码: <随机生成>

【Redis（容器内，不对外暴露）】
密码: <随机生成>

【运维命令】
cd /www/wwwroot/pansou
./docker-deploy.sh status
./docker-deploy.sh logs
./docker-deploy.sh restart
./docker-deploy.sh down

【安全提醒】
1. 默认超管密码 admin123 为弱密码，请登录后台立即修改
2. DB/Redis 不对外暴露端口，仅容器内通信
3. 请在宝塔面板 → 安全 → 防火墙 放行 Web 端口 <WEB端口>
```

### 改动 2：修改 `/workspace/docker-compose.yml`

**mysql 服务**：移除 `ports` 段（原 `"${DB_EXPOSE_PORT:-3306}:3306"`），改为注释：
```yaml
    # 端口不对外暴露（生产环境仅容器内通信，更安全）
    # 如需外部访问 DB，取消下行注释并指定宿主机端口：
    # ports:
    #   - "3306:3306"
```

**redis 服务**：同上移除 `ports` 段：
```yaml
    # 端口不对外暴露
    # 如需外部访问 Redis，取消下行注释：
    # ports:
    #   - "6379:6379"
```

### 改动 3：修改 `/workspace/.env.docker.example`

- `DB_EXPOSE_PORT=3306` → `DB_EXPOSE_PORT=0`（0=不暴露）
- `REDIS_EXPOSE_PORT=6379` → `REDIS_EXPOSE_PORT=0`
- `ADMIN_EMAIL=admin@example.com`（保持）
- `ADMIN_PASSWORD=admin123`（保持，与脚本一致）
- 顶部注释说明"DB/Redis 默认不对外暴露"

### 改动 4：修改 `/workspace/docs/Docker部署教程.md`

- 3.0 节：更新一条命令说明（强调自动装宝塔、固定账密、Web+1/DB不暴露）
- 3.0.1 宝塔专属指南：更新为"未装宝塔会自动安装"流程，更新端口策略说明
- 新增"部署信息文件"说明（/root/pansou-deploy-info.txt）

## 五、验证步骤

1. **bash 语法检查**：`bash -n install.sh` + `shellcheck install.sh` 零警告
2. **场景模拟测试**（不依赖真实 Docker/宝塔）：
   - Web 端口 +1 递增逻辑（8080 占用→8081）
   - 固定账密写入正确性
   - DB/Redis 不暴露（EXPOSE=0）配置生成
   - /root/pansou-deploy-info.txt 内容生成
   - 幂等性（.env.docker 已存在）
3. **docker-compose 配置验证**：`docker compose config` 不报错（在项目目录）
4. **git commit + push origin main**

## 六、假设与决策

1. **固定超管账密**：admin@example.com / admin123（用户明确要求，脚本输出强提醒改密）
2. **宝塔自动安装**：未装则自动拉取安装（用户明确要求），用 `echo y |` 自动确认交互
3. **端口策略**：仅 Web +1 递增（8080→8081→...），DB/Redis 不暴露（docker-compose 移除 ports）
4. **部署目录**：/www/wwwroot/pansou（宝塔站点习惯）
5. **DB/Redis/App 密码**：用 openssl 随机生成强密码（真实数据，非硬编码占位）
6. **保留管道模式自我重启机制**：避免 curl|bash 的 SSH 断开问题
7. **登录字段**：执行时先读 admin_users 表结构与登录控制器，确认 username/email 字段用法，相应填入

## 七、执行顺序

1. 先读 admin_users 表结构 + 登录控制器（确认邮箱登录字段）
2. 修改 docker-compose.yml（移除 mysql/redis ports）
3. 修改 .env.docker.example
4. 重写 install.sh
5. 语法检查 + 场景模拟自检
6. 更新 Docker 部署教程
7. git commit + push
