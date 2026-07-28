<?php
namespace app\index\middleware;

use Closure;
use think\App;
use think\facade\Cache;
use think\facade\Session;
use think\Request;
use think\Response;
use app\common\model\User;

/**
 * 前台用户登录校验
 * 未登录跳转 /auth/login;每次请求校验用户状态(缓存 60s),被封禁则强制登出。
 */
class UserAuth
{
    protected App $app;

    /**
     * 用户状态缓存时长(秒)
     */
    protected const STATUS_CACHE_TTL = 60;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return $this->unauthorized($request);
        }

        $userId = (int) $userId;

        // 每次请求校验用户状态(缓存 60s),被封禁则强制登出
        if (!$this->isStatusValid($userId)) {
            $this->clearUserSession();
            return $this->unauthorized($request, '账号已被封禁');
        }

        $request->userId = $userId;
        return $next($request);
    }

    /**
     * 校验用户状态(正常=1)
     * 优先读 Redis 缓存(60s),缓存未命中查库;
     * Redis 不可用降级查库,DB 异常时降级放行避免锁死用户中心。
     *
     * 封禁即时性: 管理员封禁时写入 user_ban:{id} 标记,此处优先检查,
     * 绕过状态缓存(60s)的延迟,实现封禁后立即下线。
     */
    protected function isStatusValid(int $userId): bool
    {
        // 优先检查封禁标记(管理员封禁时立即写入,绕过状态缓存延迟)
        try {
            if (Cache::get('user_ban:' . $userId)) {
                return false;
            }
        } catch (\Throwable $e) {
            // 缓存读取异常,降级到下方状态校验流程
        }

        $cacheKey = 'user_status:' . $userId;

        // 读缓存(异常降级查库)
        try {
            $cached = Cache::get($cacheKey);
            if ($cached !== null && $cached !== false) {
                return (int) $cached === 1;
            }
        } catch (\Throwable $e) {
            trace('user_status_cache_read_error: ' . $e->getMessage(), 'error');
        }

        // 查库(User 模型启用软删除,find 自动过滤已删除用户)
        try {
            $user = User::field('id,status')->find($userId);
            if (!$user) {
                return false;
            }
            $status = (int) $user->status;
            // 仅缓存正常状态:避免"封禁后解封"时被旧缓存(0)反复误踢 60s
            if ($status === 1) {
                try {
                    Cache::set($cacheKey, $status, self::STATUS_CACHE_TTL);
                } catch (\Throwable $e) {
                    trace('user_status_cache_write_error: ' . $e->getMessage(), 'error');
                }
            }
            return $status === 1;
        } catch (\Throwable $e) {
            // DB 异常:降级放行(避免 DB 抖动锁死用户中心),记录日志待排查
            trace('user_status_db_error: ' . $e->getMessage(), 'error');
            return true;
        }
    }

    /**
     * 清除用户会话
     */
    protected function clearUserSession(): void
    {
        Session::delete('user_id');
        Session::delete('email');
        Session::delete('nickname');
    }

    /**
     * 未登录/会话失效统一响应
     * 页面请求跳转登录时,redirect 参数只保留 path 部分(不含 host),防开放重定向。
     */
    protected function unauthorized(Request $request, string $message = '请先登录'): Response
    {
        if ($request->isAjax() || str_contains($request->header('accept', ''), 'json')) {
            return Response::create([
                'code'       => 1002,
                'message'    => $message,
                'data'       => [],
                'request_id' => $request->header('X-Request-Id', ''),
                'timestamp'  => time(),
            ], 'json')->code(401);
        }

        // 页面请求跳转登录:redirect 仅取 path(不含 host),防开放重定向
        $redirect = urlencode($request->url());
        return Response::create('/auth/login?redirect=' . $redirect, 'redirect')->code(302);
    }
}
