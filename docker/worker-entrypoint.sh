#!/bin/bash
# =============================================================================
# Worker 容器入口脚本
# 与 app 容器共用入口逻辑，但启动 worker-supervisord.conf（队列 + cron）
# =============================================================================

set -e

export CONTAINER_ROLE=worker

PROJECT_DIR="/var/www/html"
ENV_FILE="${PROJECT_DIR}/.env"

log() {
    echo "[worker-entrypoint] $1"
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

# 安装 nc
if ! command -v nc >/dev/null 2>&1; then
    apk add --no-cache netcat-openbsd >/dev/null 2>&1 || true
fi

# 等待 MySQL / Redis
wait_for "${DB_HOST:-mysql}" "${DB_PORT:-3306}" "MySQL" 90
wait_for "${REDIS_HOST:-redis}" "${REDIS_PORT:-6379}" "Redis" 30

# 生成 .env（与 app 容器逻辑一致）
if [ ! -f "${ENV_FILE}" ]; then
    log "生成 .env 文件 ..."
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
fi

# 安装 crontab
if [ -f /etc/crontab ]; then
    crontab /etc/crontab 2>/dev/null && log "crontab 已安装" || true
fi

# 校正权限
chown -R www-data:www-data "${PROJECT_DIR}/runtime" 2>/dev/null || true
chmod -R 0775 "${PROJECT_DIR}/runtime" 2>/dev/null || true

log "启动 worker supervisor ..."
exec supervisord -c /etc/worker-supervisord.conf -n
