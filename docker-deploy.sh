#!/bin/bash
# =============================================================================
# Docker 一键部署脚本 - 网盘资源搜索引擎
# 用法：
#   ./docker-deploy.sh              # 默认部署
#   ./docker-deploy.sh build        # 仅构建镜像
#   ./docker-deploy.sh up           # 启动服务
#   ./docker-deploy.sh down         # 停止服务
#   ./docker-deploy.sh logs         # 查看日志
#   ./docker-deploy.sh restart      # 重启服务
#   ./docker-deploy.sh status       # 查看状态
#   ./docker-deploy.sh reset        # 重置数据（危险！会清空数据库）
# =============================================================================

set -e

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${PROJECT_DIR}"

ENV_FILE="${ENV_FILE:-.env.docker}"
COMPOSE="docker-compose --env-file ${ENV_FILE}"

# 检查 docker 与 docker-compose
if ! command -v docker >/dev/null 2>&1; then
    echo "[错误] 未检测到 docker，请先安装 Docker"
    exit 1
fi

if ! docker compose version >/dev/null 2>&1 && ! command -v docker-compose >/dev/null 2>&1; then
    echo "[错误] 未检测到 docker-compose，请先安装 Docker Compose"
    exit 1
fi

# 兼容 docker compose v2
if docker compose version >/dev/null 2>&1; then
    COMPOSE="docker compose --env-file ${ENV_FILE}"
fi

# 首次部署：从样例生成 .env.docker
init_env() {
    if [ ! -f "${ENV_FILE}" ]; then
        if [ -f .env.docker.example ]; then
            cp .env.docker.example "${ENV_FILE}"
            echo "[部署] 已从样例生成 ${ENV_FILE}"
            echo "[部署] 请编辑 ${ENV_FILE} 修改密码、邮件、支付等配置后重新运行本脚本"
            echo ""
            echo "  vi ${ENV_FILE}"
            echo ""
            exit 0
        else
            echo "[错误] 未找到 .env.docker.example 样例文件"
            exit 1
        fi
    fi
}

case "${1:-up}" in
    init)
        init_env
        ;;

    build)
        echo "[部署] 构建 Docker 镜像 ..."
        ${COMPOSE} build
        ;;

    up)
        init_env
        echo "[部署] 构建镜像并启动服务 ..."
        ${COMPOSE} up -d --build
        echo ""
        echo "[部署] 等待服务就绪（30 秒）..."
        sleep 30
        ${COMPOSE} ps
        echo ""
        WEB_PORT=$(grep -oP 'WEB_PORT=\K\d+' "${ENV_FILE}" 2>/dev/null || echo 8080)
        echo "[部署] 完成！"
        echo "  前台访问：http://localhost:${WEB_PORT}"
        echo "  后台访问：http://localhost:${WEB_PORT}/admin/login"
        echo "  默认管理员：admin / admin123（请尽快修改）"
        echo ""
        echo "查看日志："
        echo "  ${COMPOSE} logs -f"
        ;;

    down)
        echo "[部署] 停止服务 ..."
        ${COMPOSE} down
        ;;

    logs)
        ${COMPOSE} logs -f --tail=200
        ;;

    restart)
        ${COMPOSE} restart
        ${COMPOSE} ps
        ;;

    status|ps)
        ${COMPOSE} ps
        ;;

    reset)
        read -p "确认重置所有数据（数据库+Redis+上传文件）？此操作不可逆！[y/N]: " ans
        if [ "${ans}" = "y" ] || [ "${ans}" = "Y" ]; then
            echo "[部署] 停止服务并清空所有卷 ..."
            ${COMPOSE} down -v
            echo "[部署] 数据已清空，重新执行 ./docker-deploy.sh up 即可重新部署"
        else
            echo "[部署] 已取消"
        fi
        ;;

    shell|exec)
        ${COMPOSE} exec app /bin/bash
        ;;

    *)
        echo "用法: $0 {init|build|up|down|logs|restart|status|reset|shell}"
        echo ""
        echo "  init    - 生成 .env.docker 配置文件（首次部署）"
        echo "  build   - 构建 Docker 镜像"
        echo "  up      - 构建并启动所有服务（默认）"
        echo "  down    - 停止所有服务"
        echo "  logs    - 查看实时日志"
        echo "  restart - 重启服务"
        echo "  status  - 查看服务状态"
        echo "  reset   - 清空所有数据卷并停止服务"
        echo "  shell   - 进入 app 容器 shell"
        exit 1
        ;;
esac
