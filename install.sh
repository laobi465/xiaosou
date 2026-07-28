#!/bin/bash
# =============================================================================
# 网盘资源搜索引擎 - 真·一条命令一键部署
#
# 用法：
#   1) 远程一条命令（推荐）：
#        curl -fsSL https://raw.githubusercontent.com/laobi465/xiaosou/main/install.sh | bash
#   2) 自定义端口：
#        curl -fsSL https://raw.githubusercontent.com/laobi465/xiaosou/main/install.sh | bash -s -- -p 9000
#   3) 本地执行：
#        ./install.sh
#        ./install.sh -p 9000
#
# 脚本职责：
#   - 自动检测并安装 Docker / Docker Compose / git
#   - 自动克隆仓库（如不在仓库内）
#   - 自动生成随机密码并写入 .env.docker
#   - 自动构建镜像并启动 mysql / redis / app / worker 四个容器
#   - 轮询健康检查直到服务就绪
#   - 输出访问地址与凭据
# =============================================================================

set -euo pipefail

# ---------- 默认配置 ----------
REPO_URL="https://github.com/laobi465/xiaosou.git"
DEFAULT_PORT=8080
CLONE_DIR="${HOME}/pansou-deploy"
HEALTH_TIMEOUT=240

# ---------- 颜色 ----------
if [ -t 1 ]; then
    C_RED='\033[0;31m'
    C_GREEN='\033[0;32m'
    C_YELLOW='\033[0;33m'
    C_BLUE='\033[0;34m'
    C_BOLD='\033[1m'
    C_OFF='\033[0m'
else
    C_RED=''; C_GREEN=''; C_YELLOW=''; C_BLUE=''; C_BOLD=''; C_OFF=''
fi

# ---------- 日志 ----------
log()   { echo -e "${C_BLUE}[install]${C_OFF} $*"; }
ok()    { echo -e "${C_GREEN}[install]${C_OFF} $*"; }
warn()  { echo -e "${C_YELLOW}[install]${C_OFF} $*"; }
die()   { echo -e "${C_RED}[install][错误]${C_OFF} $*" >&2; exit 1; }

# ---------- 参数解析 ----------
PORT="${DEFAULT_PORT}"
NO_CONFIRM=false
while getopts "p:hy" opt; do
    case "$opt" in
        p) PORT="$OPTARG" ;;
        y) NO_CONFIRM=true ;;
        h)
            sed -n '2,20p' "$0" 2>/dev/null || true
            exit 0
            ;;
        *)
            die "未知参数: -$OPTARG  (使用 -h 查看帮助)"
            ;;
    esac
done

# ---------- 工具函数 ----------

# 检测命令是否存在
has_cmd() { command -v "$1" >/dev/null 2>&1; }

# 生成随机密码（优先 openssl，回退 /dev/urandom）
gen_pass() {
    local len="${1:-24}"
    if has_cmd openssl; then
        openssl rand -hex $((len / 2)) 2>/dev/null || head -c $((len / 2)) /dev/urandom | od -An -tx1 | tr -d ' \n'
    else
        head -c $((len / 2)) /dev/urandom | od -An -tx1 | tr -d ' \n'
    fi
}

# 生成管理员密码（12 位，避免特殊字符）
gen_admin_pass() {
    if has_cmd openssl; then
        openssl rand -base64 12 2>/dev/null | tr -d '/+=' | cut -c1-12
    else
        head -c 9 /dev/urandom | base64 | tr -d '/+=' | cut -c1-12
    fi
}

# 检测包管理器并安装
install_pkg() {
    local pkg="$1"
    if has_cmd apt-get; then
        sudo apt-get update -qq && sudo apt-get install -y -qq "$pkg"
    elif has_cmd yum; then
        sudo yum install -y "$pkg"
    elif has_cmd dnf; then
        sudo dnf install -y "$pkg"
    elif has_cmd apk; then
        sudo apk add --no-cache "$pkg"
    else
        die "无法安装 $pkg：未识别的包管理器，请手动安装"
    fi
}

# 安装 Docker
ensure_docker() {
    if has_cmd docker; then
        ok "Docker 已安装: $(docker --version)"
    else
        log "未检测到 Docker，开始自动安装 ..."
        if has_cmd curl; then
            curl -fsSL https://get.docker.com | sh
        elif has_cmd wget; then
            wget -qO- https://get.docker.com | sh
        else
            die "未检测到 curl/wget，请先安装其中之一再重试"
        fi
        # 启动 Docker 守护进程
        if has_cmd systemctl; then
            sudo systemctl enable --now docker
        fi
        ok "Docker 安装完成: $(docker --version)"
    fi

    # 确认 docker daemon 运行
    if ! docker info >/dev/null 2>&1; then
        warn "Docker daemon 未运行，尝试启动 ..."
        if has_cmd systemctl; then
            sudo systemctl start docker
        fi
        sleep 3
        docker info >/dev/null 2>&1 || die "Docker daemon 启动失败，请手动执行: sudo systemctl start docker"
    fi
    ok "Docker daemon 运行正常"
}

# 检测 docker compose
ensure_compose() {
    if docker compose version >/dev/null 2>&1; then
        COMPOSE_CMD="docker compose"
        ok "Docker Compose (v2) 可用"
    elif has_cmd docker-compose; then
        COMPOSE_CMD="docker-compose"
        ok "docker-compose (v1) 可用"
    else
        die "未检测到 Docker Compose，且无法自动安装。请手动安装：https://docs.docker.com/compose/install/"
    fi
}

# 安装 git
ensure_git() {
    if has_cmd git; then
        ok "git 已安装"
        return
    fi
    log "未检测到 git，开始自动安装 ..."
    install_pkg git
    ok "git 安装完成"
}

# 确保在仓库目录内
ensure_repo() {
    if [ -f "docker-compose.yml" ] && [ -f "Dockerfile" ]; then
        ok "当前目录已是项目仓库: $(pwd)"
        PROJECT_DIR="$(pwd)"
        return
    fi

    # 远程模式：克隆到固定目录
    if [ -d "${CLONE_DIR}" ]; then
        log "检测到已存在目录 ${CLONE_DIR}，尝试更新 ..."
        cd "${CLONE_DIR}"
        git pull --rebase || warn "git pull 失败，继续使用现有代码"
    else
        log "克隆仓库到 ${CLONE_DIR} ..."
        git clone --depth=1 "${REPO_URL}" "${CLONE_DIR}"
        cd "${CLONE_DIR}"
    fi
    PROJECT_DIR="$(pwd)"
    ok "项目目录: ${PROJECT_DIR}"
}

# 检测端口是否被占用
check_port() {
    local port="$1"
    if command -v ss >/dev/null 2>&1; then
        if ss -tlnH | awk '{print $4}' | grep -qE ":${port}\$"; then
            die "端口 ${port} 已被占用，请使用 -p 指定其他端口，例如: ./install.sh -p 9000"
        fi
    elif command -v netstat >/dev/null 2>&1; then
        if netstat -tlnH 2>/dev/null | awk '{print $4}' | grep -qE ":${port}\$"; then
            die "端口 ${port} 已被占用，请使用 -p 指定其他端口，例如: ./install.sh -p 9000"
        fi
    fi
}

# 生成 .env.docker
generate_env() {
    local env_file="${PROJECT_DIR}/.env.docker"

    if [ -f "${env_file}" ]; then
        warn "检测到已存在 ${env_file}，保留既有配置（如需重置请删除该文件后重跑）"
        # 读取既有端口用于后续健康检查
        PORT="$(grep -oP '^WEB_PORT=\K\d+' "${env_file}" 2>/dev/null || echo "${PORT}")"
        # 读取既有管理员密码用于输出
        ADMIN_PASSWORD_OUT="$(grep -oP '^ADMIN_PASSWORD=\K.*' "${env_file}" 2>/dev/null || echo "")"
        return
    fi

    log "生成随机密码与配置文件 ${env_file} ..."

    local db_pass db_root_pass redis_pass admin_pass app_key aes_key
    db_pass="$(gen_pass 24)"
    db_root_pass="$(gen_pass 32)"
    redis_pass="$(gen_pass 24)"
    admin_pass="$(gen_admin_pass)"
    app_key="$(gen_pass 32)"
    aes_key="$(gen_pass 32)"

    ADMIN_PASSWORD_OUT="${admin_pass}"

    cat > "${env_file}" <<EOF
# =============================================================================
# Docker 部署配置 - 由 install.sh 自动生成
# 生成时间: $(date '+%Y-%m-%d %H:%M:%S')
# 如需修改：编辑本文件后执行 ./docker-deploy.sh restart
# =============================================================================

# ---------- Web 服务端口 ----------
WEB_PORT=${PORT}

# ---------- 数据库 ----------
DB_NAME=pan_search
DB_USER=pansou
DB_PASSWORD=${db_pass}
DB_ROOT_PASSWORD=${db_root_pass}
DB_EXPOSE_PORT=3306

# ---------- Redis ----------
REDIS_PASSWORD=${redis_pass}
REDIS_EXPOSE_PORT=6379

# ---------- 应用 ----------
APP_DEBUG=false
APP_KEY=${app_key}
SESSION_SECURE=true
SESSION_SAMESITE=Lax
AES_KEY=${aes_key}
CORS_ALLOW_ORIGIN=

# ---------- 邮件 SMTP（未配置，请按需填写后重启） ----------
MAIL_HOST=smtp.qq.com
MAIL_PORT=465
MAIL_USER=
MAIL_PASS=
MAIL_FROM=
MAIL_FROM_NAME=网盘搜索
MAIL_ENCRYPTION=ssl

# ---------- 彩虹易支付（未配置，请按需填写后重启） ----------
PAY_PID=
PAY_KEY=
PAY_API=
PAY_NOTIFY_URL=
PAY_RETURN_URL=

# ---------- 管理员账号（首次启动自动创建） ----------
ADMIN_USER=admin
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=${admin_pass}
EOF

    chmod 600 "${env_file}"
    ok ".env.docker 已生成（权限 600）"
}

# 构建并启动服务
start_services() {
    cd "${PROJECT_DIR}"
    log "构建镜像并启动服务（首次可能需要 5-10 分钟）..."
    ${COMPOSE_CMD} --env-file .env.docker up -d --build
    ok "已发出启动命令"
}

# 等待容器健康
wait_healthy() {
    local container="$1"
    local name="$2"
    local timeout="${3:-120}"
    local i=0
    log "等待 ${name} 容器健康 ..."
    while [ $i -lt $timeout ]; do
        local status
        status="$(docker inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}no-healthcheck{{end}}' "${container}" 2>/dev/null || echo "missing")"
        case "$status" in
            healthy)
                ok "${name} 容器健康（等待 ${i} 秒）"
                return 0
                ;;
            unhealthy)
                warn "${name} 容器状态 unhealthy，输出最近日志："
                docker logs --tail 30 "${container}" 2>&1 || true
                return 1
                ;;
        esac
        i=$((i + 5))
        sleep 5
    done
    warn "${name} 容器在 ${timeout} 秒内未达到健康状态（当前: ${status:-unknown}）"
    return 1
}

# 等待 HTTP 就绪
wait_http() {
    local url="$1"
    local timeout="${2:-60}"
    local i=0
    log "等待 Web 服务响应: ${url}"
    while [ $i -lt $timeout ]; do
        if curl -fsS --max-time 3 "${url}" >/dev/null 2>&1; then
            ok "Web 服务已就绪（等待 ${i} 秒）"
            return 0
        fi
        i=$((i + 3))
        sleep 3
    done
    warn "Web 服务在 ${timeout} 秒内未响应"
    return 1
}

# ---------- 主流程 ----------

main() {
    echo ""
    echo -e "${C_BOLD}=================================================${C_OFF}"
    echo -e "${C_BOLD}  网盘资源搜索引擎 - 一键部署${C_OFF}"
    echo -e "${C_BOLD}=================================================${C_OFF}"
    echo ""

    # 0. 确认（非交互模式跳过）
    if [ "${NO_CONFIRM}" = false ] && [ -t 0 ]; then
        read -r -p "即将开始部署（端口 ${PORT}），是否继续？[Y/n] " ans
        case "${ans:-Y}" in
            [Yy]*) ;;
            *) warn "已取消"; exit 0 ;;
        esac
    fi

    # 1. 环境检测与安装
    log "[1/6] 检测并安装依赖 ..."
    ensure_docker
    ensure_compose
    ensure_git

    # 2. 确保代码就位
    log "[2/6] 准备项目代码 ..."
    ensure_repo

    # 3. 端口检查
    log "[3/6] 检查端口 ${PORT} ..."
    check_port "${PORT}"

    # 4. 生成配置
    log "[4/6] 生成配置文件 ..."
    generate_env

    # 5. 启动服务
    log "[5/6] 构建并启动容器 ..."
    start_services

    # 6. 等待就绪
    log "[6/6] 等待服务就绪 ..."
    wait_healthy "pansou-mysql" "MySQL" 120 || warn "MySQL 健康检查未通过，继续尝试 ..."
    wait_healthy "pansou-redis" "Redis" 30  || warn "Redis 健康检查未通过，继续尝试 ..."
    wait_healthy "pansou-app"   "App"  "${HEALTH_TIMEOUT}" || warn "App 容器健康检查超时"
    wait_http "http://127.0.0.1:${PORT}/healthz" 60 || warn "HTTP 探测未通过，请查看日志"

    # 输出结果
    echo ""
    echo -e "${C_GREEN}${C_BOLD}=================================================${C_OFF}"
    echo -e "${C_GREEN}${C_BOLD}  部署完成！${C_OFF}"
    echo -e "${C_GREEN}${C_BOLD}=================================================${C_OFF}"
    echo ""
    echo -e "  ${C_BOLD}前台访问:${C_OFF}  http://$(hostname -I 2>/dev/null | awk '{print $1}' || echo '服务器IP'):${PORT}"
    echo -e "  ${C_BOLD}后台访问:${C_OFF}  http://$(hostname -I 2>/dev/null | awk '{print $1}' || echo '服务器IP'):${PORT}/admin/login"
    echo ""
    echo -e "  ${C_BOLD}管理员账号:${C_OFF}  admin"
    echo -e "  ${C_BOLD}管理员密码:${C_OFF}  ${C_YELLOW}${ADMIN_PASSWORD_OUT}${C_OFF}"
    echo ""
    echo -e "  ${C_BOLD}数据库密码:${C_OFF}  见 ${PROJECT_DIR}/.env.docker （DB_PASSWORD）"
    echo -e "  ${C_BOLD}Redis 密码:${C_OFF}   见 ${PROJECT_DIR}/.env.docker （REDIS_PASSWORD）"
    echo ""
    echo -e "  ${C_YELLOW}请妥善保存以上凭据！${C_OFF}"
    echo ""
    echo -e "  ${C_BOLD}项目目录:${C_OFF}  ${PROJECT_DIR}"
    echo ""
    echo -e "  ${C_BOLD}常用运维命令:${C_OFF}"
    echo -e "    cd ${PROJECT_DIR}"
    echo -e "    ./docker-deploy.sh status     # 查看服务状态"
    echo -e "    ./docker-deploy.sh logs       # 查看实时日志"
    echo -e "    ./docker-deploy.sh restart    # 重启服务"
    echo -e "    ./docker-deploy.sh down       # 停止服务"
    echo ""
    echo -e "  ${C_YELLOW}提醒：邮件 SMTP 与彩虹易支付未配置，${C_OFF}"
    echo -e "  ${C_YELLOW}如需启用注册验证码与积分充值，请编辑 .env.docker 后执行:${C_OFF}"
    echo -e "  ${C_YELLOW}  vi .env.docker && ./docker-deploy.sh restart${C_OFF}"
    echo ""
}

main "$@"
