#!/bin/bash
# =============================================================================
# App 容器入口脚本
# 职责：
#   1. 等待 MySQL / Redis 就绪
#   2. 从环境变量生成 .env 文件（如不存在）
#   3. 创建管理员账号（首次启动）
#   4. 校正目录权限
#   5. 启动 supervisor（php-fpm + nginx）
# 注：表结构与种子数据由 MySQL 容器在初始化阶段自动导入
#     （通过 /docker-entrypoint-initdb.d/）
# =============================================================================

set -e

PROJECT_DIR="/var/www/html"
INIT_FLAG="/var/www/html/runtime/.docker_admin_initialized"
ENV_FILE="${PROJECT_DIR}/.env"

log() {
    echo "[entrypoint] $1"
}

wait_for() {
    local host=$1
    local port=$2
    local name=$3
    local timeout=${4:-60}
    local i=0
    log "等待 ${name} (${host}:${port}) 就绪 ..."
    while ! nc -z "$host" "$port" 2>/dev/null; do
        i=$((i + 1))
        if [ $i -ge $timeout ]; then
            log "错误: ${name} 在 ${timeout} 秒内未就绪"
            exit 1
        fi
        sleep 1
    done
    log "${name} 已就绪（等待 ${i} 秒）"
}

# 安装 nc（busybox netcat）
if ! command -v nc >/dev/null 2>&1; then
    apk add --no-cache netcat-openbsd >/dev/null 2>&1 || true
fi

# 1. 等待依赖服务
wait_for "${DB_HOST:-mysql}" "${DB_PORT:-3306}" "MySQL" "${DB_WAIT_TIMEOUT:-90}"
wait_for "${REDIS_HOST:-redis}" "${REDIS_PORT:-6379}" "Redis" "${REDIS_WAIT_TIMEOUT:-30}"

# 2. 生成 .env（如果不存在或来自只读挂载则需要先生成）
if [ ! -f "${ENV_FILE}" ] || [ -w "${ENV_FILE}" ]; then
    if [ ! -f "${ENV_FILE}" ]; then
        log "生成 .env 文件 ..."
    fi

    # 如果是只读挂载，写入失败时跳过
    if [ ! -f "${ENV_FILE}" ] || touch "${ENV_FILE}" 2>/dev/null; then
        cat > "${ENV_FILE}" <<EOF
SESSION_SECURE = ${SESSION_SECURE:-true}
SESSION_SAMESITE = ${SESSION_SAMESITE:-Lax}
QUEUE_DRIVER = redis

[APP]
DEBUG = ${APP_DEBUG:-false}
KEY = "${APP_KEY:-$(php -r 'echo bin2hex(random_bytes(16));')}"

[DATABASE]
TYPE = mysql
HOSTNAME = ${DB_HOST:-mysql}
DATABASE = ${DB_NAME:-pan_search}
USERNAME = ${DB_USER:-pansou}
PASSWORD = "${DB_PASSWORD:-pansou}"
HOSTPORT = ${DB_PORT:-3306}
CHARSET = utf8mb4
PREFIX = ""
DEPLOY = 0
RW_SEPARATE = false

[REDIS]
HOST = ${REDIS_HOST:-redis}
PORT = ${REDIS_PORT:-6379}
PASSWORD = "${REDIS_PASSWORD:-}"
SELECT = 0
PREFIX = pansou:
TIMEOUT = 5

[MAIL]
SMTP_HOST = ${MAIL_HOST:-smtp.qq.com}
SMTP_PORT = ${MAIL_PORT:-465}
SMTP_USER = "${MAIL_USER:-}"
SMTP_PASS = "${MAIL_PASS:-}"
SMTP_FROM = "${MAIL_FROM:-}"
SMTP_FROM_NAME = ${MAIL_FROM_NAME:-网盘搜索}
SMTP_ENCRYPTION = ${MAIL_ENCRYPTION:-ssl}

[PAY]
CAIHONG_PID = "${PAY_PID:-}"
CAIHONG_KEY = "${PAY_KEY:-}"
CAIHONG_API = "${PAY_API:-}"
NOTIFY_URL = "${PAY_NOTIFY_URL:-}"
RETURN_URL = "${PAY_RETURN_URL:-}"

[SECURITY]
AES_KEY = "${AES_KEY:-$(php -r 'echo bin2hex(random_bytes(16));')}"
SESSION_PREFIX = pansou_
ADMIN_SESSION_PREFIX = pansou_admin_

[CORS]
ALLOW_ORIGIN = "${CORS_ALLOW_ORIGIN:-}"
EOF
        log ".env 已生成"
    else
        log ".env 来自只读挂载，跳过生成"
    fi
fi

# 3. 创建管理员账号（首次启动）
if [ ! -f "${INIT_FLAG}" ] && [ -n "${ADMIN_USER:-}" ] && [ -n "${ADMIN_PASSWORD:-}" ]; then
    log "创建管理员账号 ..."
    cd "${PROJECT_DIR}"
    php -r "
require __DIR__.'/vendor/autoload.php';
\$app = new \think\App(__DIR__);
\$app->initialize();
try {
    \$exist = \think\facade\Db::name('admin_users')->where('username', '${ADMIN_USER}')->find();
    if (!\$exist) {
        \think\facade\Db::name('admin_users')->insert([
            'username'     => '${ADMIN_USER}',
            'password'     => password_hash('${ADMIN_PASSWORD}', PASSWORD_BCRYPT),
            'nickname'     => '超级管理员',
            'status'       => 1,
            'create_time'  => date('Y-m-d H:i:s'),
            'update_time'  => date('Y-m-d H:i:s'),
        ]);
        echo '[entrypoint] 管理员账号已创建: ${ADMIN_USER}' . PHP_EOL;
    } else {
        echo '[entrypoint] 管理员账号已存在: ${ADMIN_USER}' . PHP_EOL;
    }
} catch (\Throwable \$e) {
    echo '[entrypoint] 管理员账号创建失败: ' . \$e->getMessage() . PHP_EOL;
}
" 2>/dev/null && touch "${INIT_FLAG}" || log "管理员账号创建失败（数据库可能尚未初始化，请稍后手动创建）"
fi

# 4. 校正权限
log "校正目录权限 ..."
chown -R www-data:www-data "${PROJECT_DIR}/runtime" "${PROJECT_DIR}/public/static/uploads" 2>/dev/null || true
chmod -R 0775 "${PROJECT_DIR}/runtime" "${PROJECT_DIR}/public/static/uploads" 2>/dev/null || true

# 5. 安装 crontab（仅 worker 容器使用）
if [ "${CONTAINER_ROLE:-app}" = "worker" ] && [ -f /etc/crontab ]; then
    crontab /etc/crontab 2>/dev/null && log "crontab 已安装" || true
fi

log "启动 supervisord ..."
exec "$@"
