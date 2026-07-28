<?php
namespace app\index\middleware;

use Closure;
use think\App;
use think\facade\Session;
use think\Request;
use think\Response;

/**
 * 前台用户登录校验
 * 未登录跳转 /auth/login
 */
class UserAuth
{
    protected App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $userId = Session::get('user_id');
        if ($userId) {
            $request->userId = (int) $userId;
            return $next($request);
        }

        // Ajax 请求返回 401
        if ($request->isAjax() || str_contains($request->header('accept', ''), 'json')) {
            return Response::create([
                'code'       => 1002,
                'message'    => '请先登录',
                'data'       => [],
                'request_id' => $request->header('X-Request-Id', ''),
                'timestamp'  => time(),
            ], 'json')->code(401);
        }

        // 页面请求跳转登录
        $redirect = urlencode($request->url(true));
        return Response::create('/auth/login?redirect=' . $redirect, 'redirect')->code(302);
    }
}
