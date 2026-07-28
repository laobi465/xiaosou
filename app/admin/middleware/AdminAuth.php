<?php
namespace app\admin\middleware;

use Closure;
use think\App;
use think\facade\Session;
use think\Request;
use think\Response;

/**
 * 后台管理员鉴权
 * Session 与前台完全隔离(独立 cookie 名 + Redis 前缀)
 */
class AdminAuth
{
    protected App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $adminId = Session::get('admin_id');
        if ($adminId) {
            $request->adminId = (int) $adminId;
            return $next($request);
        }

        if ($request->isAjax() || str_contains($request->header('accept', ''), 'json')) {
            return Response::create([
                'code'       => 1003,
                'message'    => '未登录或登录已过期',
                'data'       => [],
                'request_id' => $request->header('X-Request-Id', ''),
                'timestamp'  => time(),
            ], 'json')->code(401);
        }

        return Response::create('/admin/login', 'redirect')->code(302);
    }
}
