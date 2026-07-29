#!/bin/bash
# =============================================================================
# 网盘资源搜索引擎 - SSH 一键安装脚本（宝塔面板 + Docker）
#
# 用法：
#   1) 远程一条命令（推荐）：
#        curl -fsSL https://raw.githubusercontent.com/laobi465/xiaosou/main/install.sh | bash
#   2) 自定义 Web 端口起始值：
#        curl -fsSL https://raw.githubusercontent.com/laobi465/xiaosou/main/install.sh | bash -s -- -p 9000
#   3) 本地执行：
#        ./install.sh
#        ./install.sh -p 9000
#
# 脚本职责：
#   - 检测宝塔面板，未安装则自动拉取宝塔官方脚本安装
#   - 检测并安装 Docker
#   - 克隆项目到 /www/wwwroot/pansou
#   - Web 端口冲突时自动 +1 递增（8080→8081→...）
#   - DB/Redis 不对外暴露端口（仅容器内通信）
#   - 固定超管账密 admin@example.com / admin123
#   - 部署信息保存到 /root/pansou-deploy-info.txt
# =============================================================================

set -euo pipefail

# ---------- 管道模式自我重启 ----------
# curl|bash 模式下 stdin 是管道(非 tty), 脚本内 read 交互失败且 set -e 遇错即退出
# 会导致 SSH 会话被切断。检测到管道模式时自动重新下载到本地再 exec bash 执行。
if [ ! -t 0 ]; then
    SELF_URL="https://raw.githubusercontent.com/laobi465/xiaosou/main/install.sh"
    TMP_SELF="$(mktemp /tmp/install-pansou.XXXXXX.sh)"
    echo "[install] 检测到管道模式(curl|bash), 自动转为本地执行以避免 SSH 断开 ..."
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL "${SELF_URL}" -o "${TMP_SELF}"
    elif command -v wget >/dev/null 2>&1; then
        wget -qO "${TMP_SELF}" "${SELF_URL}"
    else
        echo "[install][错误] 需要 curl 或 wget, 请先安装" >&2
        exit 1
    fi
    chmod +x "${TMP_SELF}"
    exec bash "${TMP_SELF}" "$@"
fi

# ---------- 默认配置 ----------
REPO_URL="https://github.com/laobi465/xiaosou.git"
DEFAULT_PORT=8080
WEB_PORT_MAX=9999
DEPLOY_DIR="/www/wwwroot/pansou"
INFO_FILE="/root/pansou-deploy-info.txt"
HEALTH_TIMEOUT=240

# 宝塔面板检测
BT_PANEL_DIR="/www/server/panel"
BT_DATA_DIR="${BT_PANEL_DIR}/data"
IS_BAOTA=false

# 固定超管账密（用户要求）
ADMIN_USER="admin@example.com"
ADMIN_EMAIL="admin@example.com"
ADMIN_PASSWORD="admin123"

# 宝塔面板信息（安装后读取）
BT_PANEL_PORT=""
BT_PANEL_USER="admin"
BT_PANEL_PASS=""
BT_PANEL_PATH=""
BT_PANEL_INSTALLED_BY_SCRIPT=false

# 颜色
if [ -t 1 ]; then
    C_RED='\033[0;31m'; C_GREEN='\033[0;32m'; C_YELLOW='\033[0;33m'
    C_BLUE='\033[0;34m'; C_BOLD='\033[1m'; C_OFF='\033[0m'
else
    C_RED=''; C_GREEN=''; C_YELLOW=''; C_BLUE=''; C_BOLD=''; C_OFF=''
fi

# 日志
log()   { echo -e "${C_BLUE}[install]${C_OFF} $*"; }
ok()    { echo -e "${C_GREEN}[install]${C_OFF} $*"; }
warn()  { echo -e "${C_YELLOW}[install]${C_OFF} $*"; }
die()   { echo -e "${C_RED}[install][错误]${C_OFF} $*" >&2; exit 1; }

# 参数解析
PORT="${DEFAULT_PORT}"
NO_CONFIRM=false
while getopts "p:hy" opt; do
    case "$opt" in
        p) PORT="$OPTARG" ;;
        y) NO_CONFIRM=true ;;
        h) sed -n '2,25p' "$0" 2>/dev/null || true; exit 0 ;;
        *) die "未知参数: -$OPTARG  (使用 -h 查看帮助)" ;;
    esac
done

# ---------- 工具函数 ----------
has_cmd() { command -v "$1" >/dev/null 2>&1; }

# 生成随机强密码（优先 openssl, 回退 /dev/urandom）
gen_pass() {
    local len="${1:-24}"
    if has_cmd openssl; then
        openssl rand -hex $((len / 2)) 2>/dev/null || head -c $((len / 2)) /dev/urandom | od -An -tx1 | tr -d ' \n'
    else
        head -c $((len / 2)) /dev/urandom | od -An -tx1 | tr -d ' \n'
    fi
}

install_pkg() {
    local pkg="$1"
    if has_cmd apt-get; then apt-get update -qq && apt-get install -y -qq "$pkg"
    elif has_cmd yum; then yum install -y "$pkg"
    elif has_cmd dnf; then dnf install -y "$pkg"
    elif has_cmd apk; then apk add --no-cache "$pkg"
    else die "无法安装 $pkg：未识别的包管理器，请手动安装"
    fi
}

# 获取服务器主 IP
get_server_ip() {
    hostname -I 2>/dev/null | awk '{print $1}' || echo "服务器IP"
}

# 端口占用检测（返回 0=占用, 1=空闲）
port_in_use() {
    local port="$1"
    if command -v ss >/dev/null 2>&1; then
        ss -tlnH 2>/dev/null | awk '{print $4}' | grep -qE ":${port}\$" && return 0
    elif command -v netstat >/dev/null 2>&1; then
        netstat -tlnH 2>/dev/null | awk '{print $4}' | grep -qE ":${port}\$" && return 0
    fi
    return 1
}

# Web 端口冲突检测：从 PORT 起 +1 递增直到空闲（上限 WEB_PORT_MAX）
pick_web_port() {
    local p="${PORT}"
    while [ "$p" -le "${WEB_PORT_MAX}" ]; do
        if ! port_in_use "$p"; then
            echo "$p"
            return 0
        fi
        warn "端口 ${p} 被占用, 自动 +1 尝试 $((p + 1)) ..."
        p=$((p + 1))
    done
    die "端口 ${PORT}-${WEB_PORT_MAX} 全部被占用，请使用 -p 指定其他起始端口"
}

# ---------- 阶段 1: 检测/安装宝塔面板 ----------
install_baota() {
    log "[1/7] 检测宝塔面板 ..."
    if [ -d "${BT_PANEL_DIR}" ]; then
        IS_BAOTA=true
        ok "已安装宝塔面板: ${BT_PANEL_DIR}"
        read_baota_info
        return
    fi

    # 未安装宝塔 → 自动拉取官方安装脚本
    warn "未检测到宝塔面板, 开始自动安装（耗时 10-30 分钟，会自动装 Nginx/MySQL/PHP 等）..."
    if [ "${NO_CONFIRM}" = false ] && [ -t 0 ]; then
        read -r -p "即将自动安装宝塔面板，是否继续？[Y/n] " ans
        case "${ans:-Y}" in
            [Nn]*) die "已取消。请手动安装宝塔面板后重新运行本脚本" ;;
        esac
    fi

    # 宝塔官方国内版安装脚本（Debian/Ubuntu/CentOS 通用）
    # 用 yes 管道自动确认交互提示
    local bt_install_script="/tmp/bt_install_panel.sh"
    log "下载宝塔安装脚本 ..."
    if has_cmd curl; then
        curl -fsSL https://download.bt.cn/install/install_panel.sh -o "${bt_install_script}"
    elif has_cmd wget; then
        wget -qO "${bt_install_script}" https://download.bt.cn/install/install_panel.sh
    else
        die "需要 curl 或 wget 来下载宝塔安装脚本，请先安装"
    fi

    [ -f "${bt_install_script}" ] || die "宝塔安装脚本下载失败"

    log "执行宝塔面板安装（自动确认，请耐心等待）..."
    # 宝塔安装脚本交互式，用 yes 管道自动确认所有提示
    yes | bash "${bt_install_script}" ed8484bec || {
        warn "宝塔安装脚本返回非零状态，检查是否已实际完成 ..."
    }
    rm -f "${bt_install_script}"

    # 等待宝塔面板目录就绪
    local i=0
    while [ $i -lt 30 ]; do
        [ -d "${BT_PANEL_DIR}" ] && break
        sleep 2
        i=$((i + 1))
    done

    if [ -d "${BT_PANEL_DIR}" ]; then
        IS_BAOTA=true
        BT_PANEL_INSTALLED_BY_SCRIPT=true
        ok "宝塔面板安装完成"
        read_baota_info
    else
        warn "宝塔面板目录未出现，可能安装未完成或需重启。继续后续 Docker 部署（项目仍可用，仅缺宝塔面板管理）"
        IS_BAOTA=false
    fi
}

# 读取宝塔面板信息（端口/密码/入口）
read_baota_info() {
    BT_PANEL_PORT="$(cat "${BT_DATA_DIR}/port.pl" 2>/dev/null || echo "")"
    BT_PANEL_PASS="$(cat "${BT_PANEL_DIR}/default.pl" 2>/dev/null || echo "")"
    BT_PANEL_PATH="$(cat "${BT_DATA_DIR}/admin_path.pl" 2>/dev/null || echo "")"
    # 去除可能的换行
    BT_PANEL_PORT="$(echo "${BT_PANEL_PORT}" | tr -d '[:space:]')"
    BT_PANEL_PASS="$(echo "${BT_PANEL_PASS}" | tr -d '[:space:]')"
    BT_PANEL_PATH="$(echo "${BT_PANEL_PATH}" | tr -d '[:space:]')"
    BT_PANEL_USER="admin"

    if [ -n "${BT_PANEL_PORT}" ]; then
        ok "宝塔面板信息已读取: 端口 ${BT_PANEL_PORT}"
    fi
}

# ---------- 阶段 2: 检测/安装 Docker ----------
ensure_docker() {
    log "[2/7] 检测 Docker ..."
    if has_cmd docker; then
        ok "Docker 已安装: $(docker --version)"
    else
        warn "未检测到 Docker，开始自动安装 ..."
        if has_cmd curl; then
            curl -fsSL https://get.docker.com | sh
        elif has_cmd wget; then
            wget -qO- https://get.docker.com | sh
        else
            die "未检测到 curl/wget，请先安装其中之一再重试"
        fi
        if has_cmd systemctl; then
            systemctl enable --now docker
        fi
        ok "Docker 安装完成: $(docker --version)"
    fi

    # 确认 docker daemon 运行
    if ! docker info >/dev/null 2>&1; then
        warn "Docker daemon 未运行，尝试启动 ..."
        if has_cmd systemctl; then
            systemctl start docker
            echo "[install] Docker daemon 启动中, 等待 5 秒缓冲网络(SSH 若断开请重连后重跑, 已生成配置会保留)..."
            sleep 5
        fi
        docker info >/dev/null 2>&1 || die "Docker daemon 启动失败，请手动执行: systemctl start docker"
    fi
    ok "Docker daemon 运行正常"
}

# ---------- 阶段 3: 确认 Docker Compose ----------
ensure_compose() {
    if docker compose version >/dev/null 2>&1; then
        COMPOSE_CMD="docker compose"; ok "Docker Compose (v2) 可用"
    elif has_cmd docker-compose; then
        COMPOSE_CMD="docker-compose"; ok "docker-compose (v1) 可用"
    else
        die "未检测到 Docker Compose。请安装 Docker Compose v2（新版 Docker 已内置）"
    fi
}

# ---------- 阶段 4: 克隆项目 ----------
ensure_repo() {
    log "[4/7] 准备项目代码 ..."
    # 确保父目录存在
    mkdir -p "$(dirname "${DEPLOY_DIR}")"

    if [ -f "${DEPLOY_DIR}/docker-compose.yml" ] && [ -f "${DEPLOY_DIR}/Dockerfile" ]; then
        log "检测到已存在 ${DEPLOY_DIR}，尝试更新 ..."
        cd "${DEPLOY_DIR}"
        if has_cmd git; then
            git pull --rebase || warn "git pull 失败，继续使用现有代码"
        fi
    else
        if ! has_cmd git; then
            log "安装 git ..."
            install_pkg git
        fi
        log "克隆仓库到 ${DEPLOY_DIR} ..."
        git clone --depth=1 "${REPO_URL}" "${DEPLOY_DIR}"
        cd "${DEPLOY_DIR}"
    fi
    PROJECT_DIR="$(pwd)"
    ok "项目目录: ${PROJECT_DIR}"
}

# ---------- 阶段 5: 生成 .env.docker ----------
generate_env() {
    local env_file="${PROJECT_DIR}/.env.docker"
    if [ -f "${env_file}" ]; then
        warn "检测到已存在 ${env_file}，保留既有配置（如需重置请删除该文件后重跑）"
        PORT="$(grep -oP '^WEB_PORT=\K\d+' "${env_file}" 2>/dev/null || echo "${PORT}")"
        ok "使用既有 WEB_PORT=${PORT}"
        return
    fi

    log "[5/7] 生成配置文件 ${env_file} ..."

    # DB/Redis 密码用 openssl 随机生成（真实强密码，非硬编码占位）
    local db_pass db_root_pass redis_pass app_key aes_key
    db_pass="$(gen_pass 24)"
    db_root_pass="$(gen_pass 32)"
    redis_pass="$(gen_pass 24)"
    app_key="$(gen_pass 32)"
    aes_key="$(gen_pass 32)"

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
# DB/Redis 不对外暴露端口（仅容器内通信，更安全）
DB_EXPOSE_PORT=0

# ---------- Redis ----------
REDIS_PASSWORD=${redis_pass}
REDIS_EXPOSE_PORT=0

# ---------- 应用 ----------
APP_DEBUG=false
APP_KEY=${app_key}
SESSION_SECURE=true
SESSION_SAMESITE=Lax
AES_KEY=${aes_key}
CORS_ALLOW_ORIGIN=

# ---------- 邮件 SMTP（未配置，安装后登录后台 /admin → 系统配置 在线补充） ----------
MAIL_HOST=smtp.qq.com
MAIL_PORT=465
MAIL_USER=
MAIL_PASS=
MAIL_FROM=
MAIL_FROM_NAME=网盘搜索
MAIL_ENCRYPTION=ssl

# ---------- 彩虹易支付（未配置，安装后登录后台 /admin → 系统配置 在线补充） ----------
PAY_PID=
PAY_KEY=
PAY_API=
PAY_NOTIFY_URL=
PAY_RETURN_URL=

# ---------- 管理员账号（首次启动自动创建） ----------
# 登录用户名为 ADMIN_USER（写入 admin_users.username 字段）
ADMIN_USER=${ADMIN_USER}
ADMIN_EMAIL=${ADMIN_EMAIL}
ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
    chmod 600 "${env_file}"
    ok ".env.docker 已生成（权限 600）"
}

# ---------- 阶段 6: 构建并启动容器 ----------
start_services() {
    cd "${PROJECT_DIR}"
    log "[6/7] 构建镜像并启动服务（首次可能需要 5-10 分钟）..."
    ${COMPOSE_CMD} --env-file .env.docker up -d --build
    ok "已发出启动命令"
}

# ---------- 阶段 7: 等待健康检查 ----------
wait_healthy() {
    local container="$1" name="$2" timeout="${3:-120}" i=0
    log "等待 ${name} 容器健康 ..."
    while [ "$i" -lt "$timeout" ]; do
        local status
        status="$(docker inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}no-healthcheck{{end}}' "${container}" 2>/dev/null || echo "missing")"
        case "$status" in
            healthy) ok "${name} 容器健康（等待 ${i} 秒）"; return 0 ;;
            unhealthy)
                warn "${name} 容器状态 unhealthy，输出最近日志："
                docker logs --tail 30 "${container}" 2>&1 || true
                return 1 ;;
        esac
        i=$((i + 5)); sleep 5
    done
    warn "${name} 容器在 ${timeout} 秒内未达到健康状态"
    return 1
}

wait_http() {
    local url="$1" timeout="${2:-60}" i=0
    log "等待 Web 服务响应: ${url}"
    while [ "$i" -lt "$timeout" ]; do
        if curl -fsS --max-time 3 "${url}" >/dev/null 2>&1; then
            ok "Web 服务已就绪（等待 ${i} 秒）"; return 0
        fi
        i=$((i + 3)); sleep 3
    done
    warn "Web 服务在 ${timeout} 秒内未响应"
    return 1
}

wait_services() {
    log "[7/7] 等待服务就绪 ..."
    wait_healthy "pansou-mysql" "MySQL" 120 || warn "MySQL 健康检查未通过，继续尝试 ..."
    wait_healthy "pansou-redis" "Redis" 30  || warn "Redis 健康检查未通过，继续尝试 ..."
    wait_healthy "pansou-app"   "App"  "${HEALTH_TIMEOUT}" || warn "App 容器健康检查超时"
    wait_http "http://127.0.0.1:${PORT}/healthz" 60 || warn "HTTP 探测未通过，请查看日志"
}

# ---------- 保存部署信息到 /root/pansou-deploy-info.txt ----------
save_info_file() {
    local ip server_time
    ip="$(get_server_ip)"
    server_time="$(date '+%Y-%m-%d %H:%M:%S')"

    # 读取实际生成的密码（从 .env.docker）
    local env_file="${PROJECT_DIR}/.env.docker"
    local db_pass db_root_pass redis_pass
    db_pass="$(grep -oP '^DB_PASSWORD=\K.*' "${env_file}" 2>/dev/null || echo '')"
    db_root_pass="$(grep -oP '^DB_ROOT_PASSWORD=\K.*' "${env_file}" 2>/dev/null || echo '')"
    redis_pass="$(grep -oP '^REDIS_PASSWORD=\K.*' "${env_file}" 2>/dev/null || echo '')"

    # 宝塔面板信息
    local bt_section
    if [ "${IS_BAOTA}" = true ] && [ -n "${BT_PANEL_PORT}" ]; then
        local bt_url="http://${ip}:${BT_PANEL_PORT}"
        [ -n "${BT_PANEL_PATH}" ] && bt_url="${bt_url}/${BT_PANEL_PATH}"
        bt_section=$(cat <<BTEOF
面板地址: ${bt_url}
账号: ${BT_PANEL_USER}
密码: ${BT_PANEL_PASS}
BTEOF
)
        [ "${BT_PANEL_INSTALLED_BY_SCRIPT}" = true ] && bt_section="${bt_section}
（由本脚本自动安装）"
    else
        bt_section="未安装宝塔面板（项目已部署，可用 Docker 管理）"
    fi

    cat > "${INFO_FILE}" <<EOF
============================================
 网盘资源搜索引擎 - 部署信息
 生成时间: ${server_time}
 服务器IP: ${ip}
============================================

【宝塔面板】
${bt_section}

【项目访问】
前台地址: http://${ip}:${PORT}/
后台地址: http://${ip}:${PORT}/admin/login
超管账号: ${ADMIN_USER}
超管密码: ${ADMIN_PASSWORD}

【项目目录】
${PROJECT_DIR}

【数据库（容器内，不对外暴露）】
数据库名: pan_search
用户名: pansou
密码: ${db_pass}
Root密码: ${db_root_pass}

【Redis（容器内，不对外暴露）】
密码: ${redis_pass}

【Web 端口】
${PORT}（占用时已自动 +1 递增）

【运维命令】
cd ${PROJECT_DIR}
./docker-deploy.sh status     # 查看服务状态
./docker-deploy.sh logs       # 查看实时日志
./docker-deploy.sh restart    # 重启服务
./docker-deploy.sh down       # 停止服务
./docker-deploy.sh up         # 启动服务

【安全提醒】
1. 默认超管密码 admin123 为弱密码，请登录后台 /admin 立即修改
2. DB/Redis 不对外暴露端口，仅容器内通信
3. 请在宝塔面板 → 安全 → 防火墙 放行 Web 端口 ${PORT}
4. 邮件 SMTP 与彩虹易支付未配置，登录后台 /admin → 系统配置 在线补充
5. 本文件含敏感凭据，请妥善保管（权限已设为 600）

============================================
EOF
    chmod 600 "${INFO_FILE}"
    ok "部署信息已保存: ${INFO_FILE}（权限 600）"
}

# ---------- 终端输出汇总 ----------
print_summary() {
    local ip
    ip="$(get_server_ip)"

    echo ""
    echo -e "${C_GREEN}${C_BOLD}=================================================${C_OFF}"
    echo -e "${C_GREEN}${C_BOLD}  部署完成！${C_OFF}"
    echo -e "${C_GREEN}${C_BOLD}=================================================${C_OFF}"
    echo ""

    # 宝塔面板信息
    if [ "${IS_BAOTA}" = true ] && [ -n "${BT_PANEL_PORT}" ]; then
        local bt_url="http://${ip}:${BT_PANEL_PORT}"
        [ -n "${BT_PANEL_PATH}" ] && bt_url="${bt_url}/${BT_PANEL_PATH}"
        echo -e "  ${C_BOLD}【宝塔面板】${C_OFF}"
        echo -e "    地址: ${bt_url}"
        echo -e "    账号: ${BT_PANEL_USER}"
        echo -e "    密码: ${C_YELLOW}${BT_PANEL_PASS}${C_OFF}"
        [ "${BT_PANEL_INSTALLED_BY_SCRIPT}" = true ] && echo -e "    ${C_YELLOW}(由本脚本自动安装)${C_OFF}"
        echo ""
    fi

    # 项目访问
    echo -e "  ${C_BOLD}【项目访问】${C_OFF}"
    echo -e "    前台: http://${ip}:${PORT}/"
    echo -e "    后台: http://${ip}:${PORT}/admin/login"
    echo ""
    echo -e "  ${C_BOLD}超管账号:${C_OFF}  ${ADMIN_USER}"
    echo -e "  ${C_BOLD}超管密码:${C_OFF}  ${C_YELLOW}${ADMIN_PASSWORD}${C_OFF}"
    echo ""
    echo -e "  ${C_BOLD}项目目录:${C_OFF}  ${PROJECT_DIR}"
    echo -e "  ${C_BOLD}Web 端口:${C_OFF}  ${PORT}"
    echo -e "  ${C_BOLD}部署信息:${C_OFF}  ${INFO_FILE}（含数据库/Redis 密码等完整凭据）"
    echo ""

    # 宝塔防火墙提醒
    if [ "${IS_BAOTA}" = true ]; then
        echo -e "  ${C_YELLOW}${C_BOLD}[必须] 宝塔防火墙放行:${C_OFF}"
        echo -e "    宝塔面板 → 安全 → 防火墙 → 放行端口 ${PORT}（否则外网无法访问）"
        echo ""
    fi

    echo -e "  ${C_YELLOW}提醒：邮件 SMTP 与彩虹易支付未配置，${C_OFF}"
    echo -e "  ${C_YELLOW}登录后台 /admin → 系统配置 在线补充${C_OFF}"
    echo ""
    echo -e "  ${C_YELLOW}安全：默认密码 admin123 为弱密码，请立即登录后台修改！${C_OFF}"
    echo ""
}

# ---------- 主流程 ----------
main() {
    echo ""
    echo -e "${C_BOLD}=================================================${C_OFF}"
    echo -e "${C_BOLD}  网盘资源搜索引擎 - SSH 一键部署${C_OFF}"
    echo -e "${C_BOLD}  （宝塔面板 + Docker）${C_OFF}"
    echo -e "${C_BOLD}=================================================${C_OFF}"
    echo ""

    if [ "${NO_CONFIRM}" = false ] && [ -t 0 ]; then
        read -r -p "即将开始部署（Web 端口起始 ${PORT}），是否继续？[Y/n] " ans
        case "${ans:-Y}" in
            [Yy]*) ;;
            *) warn "已取消"; exit 0 ;;
        esac
    fi

    # 阶段 1: 宝塔面板
    install_baota

    # 阶段 2: Docker
    ensure_docker

    # 阶段 3: Docker Compose
    ensure_compose

    # 阶段 4: 克隆项目
    ensure_repo

    # 阶段 5: Web 端口冲突检测（+1 递增）
    log "[3/7] 检测 Web 端口 ..."
    PORT="$(pick_web_port)"
    ok "使用 Web 端口: ${PORT}"

    # 阶段 6: 生成配置
    generate_env

    # 阶段 7: 构建启动
    start_services

    # 等待就绪
    wait_services

    # 保存信息文件
    save_info_file

    # 终端输出
    print_summary
}

main "$@"
