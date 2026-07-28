<?php
namespace app\admin\middleware;

use Closure;
use think\App;
use think\facade\Cache;
use think\facade\Session;
use think\Request;
use think\Response;
use app\common\model\AdminUser;
use app\common\service\AdminLogService;

/**
 * 后台管理员鉴权
 * Session 与前台完全隔离(独立 cookie 名 + Redis 前缀)
 *
 * 每次请求校验管理员状态(缓存 60s 到 Redis: admin_status:{admin_id}),
 * 管理员被禁用则清除会话并跳转登录;暂未实现完整 RBAC,仅暴露 is_super 扩展点。
 */
class AdminAuth
{
    protected App $app;

    /**
     * 管理员状态缓存时长(秒)
     */
    protected const STATUS_CACHE_TTL = 60;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $adminId = Session::get('admin_id');
        if (!$adminId) {
            return $this->unauthorized($request);
        }

        $adminId = (int) $adminId;

        // 每次请求校验管理员状态(缓存 60s),被禁用则强制登出
        if (!$this->isStatusValid($adminId)) {
            $this->logAuthFail($adminId, $request, 'admin_status_invalid');
            $this->clearAdminSession();
            return $this->unauthorized($request, '账号已被禁用');
        }

        // 注入管理员身份信息(供控制器/后续 RBAC 扩展使用)
        $request->adminId      = $adminId;
        $request->adminIsSuper = (int) Session::get('admin_is_super', 0);

        return $next($request);
    }

    /**
     * 校验管理员状态(正常=1)
     * 优先读 Redis 缓存(60s),缓存未命中查库;
     * Redis 不可用降级查库,DB 异常时降级放行避免锁死整个后台。
     */
    protected function isStatusValid(int $adminId): bool
    {
        $cacheKey = 'admin_status:' . $adminId;

        // 读缓存(异常降级查库)
        try {
            $cached = Cache::get($cacheKey);
            if ($cached !== null && $cached !== false) {
                return (int) $cached === 1;
            }
        } catch (\Throwable $e) {
            trace('admin_status_cache_read_error: ' . $e->getMessage(), 'error');
        }

        // 查库
        try {
            $admin = AdminUser::field('id,status')->find($adminId);
            if (!$admin) {
                return false;
            }
            $status = (int) $admin->status;
            // 仅缓存正常状态:避免"禁用后重新启用"时被旧缓存(0)反复误踢 60s
            if ($status === 1) {
                try {
                    Cache::set($cacheKey, $status, self::STATUS_CACHE_TTL);
                } catch (\Throwable $e) {
                    trace('admin_status_cache_write_error: ' . $e->getMessage(), 'error');
                }
            }
            return $status === 1;
        } catch (\Throwable $e) {
            // DB 异常:降级放行(避免 DB 抖动锁死后台),记录日志待排查
            trace('admin_status_db_error: ' . $e->getMessage(), 'error');
            return true;
        }
    }

    /**
     * 清除管理员会话
     */
    protected function clearAdminSession(): void
    {
        Session::delete('admin_id');
        Session::delete('admin_username');
        Session::delete('admin_nickname');
        Session::delete('admin_is_super');
    }

    /**
     * 记录鉴权失败日志(失败降级,不阻断响应)
     */
    protected function logAuthFail(int $adminId, Request $request, string $action): void
    {
        try {
            app(AdminLogService::class)->record(
                $adminId,
                'admin',
                $action,
                null,
                ['path' => $request->pathinfo()],
                $request->ip(),
                $request->header('user-agent', '')
            );
        } catch (\Throwable $e) {
            trace('admin_auth_log_error: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * 未登录/会话失效统一响应
     */
    protected function unauthorized(Request $request, string $message = '未登录或登录已过期'): Response
    {
        if ($request->isAjax() || str_contains($request->header('accept', ''), 'json')) {
            return Response::create([
                'code'       => 1003,
                'message'    => $message,
                'data'       => [],
                'request_id' => $request->header('X-Request-Id', ''),
                'timestamp'  => time(),
            ], 'json')->code(401);
        }

        return Response::create('/admin/login', 'redirect')->code(302);
    }
}
