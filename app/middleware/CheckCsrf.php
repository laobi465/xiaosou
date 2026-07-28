<?php
declare(strict_types=1);

namespace app\middleware;

use think\Request;
use think\Response;
use think\facade\Session;
use think\facade\View;

/**
 * CSRF 校验中间件
 * - 首次访问生成 csrf_token 写入 session 并共享到视图
 * - GET/OPTIONS/HEAD 放行
 * - 豁免路径(支付回调等)放行:支持路由参数标记 csrf_skip 与内置公开接口白名单
 * - 其余写操作校验 X-CSRF-Token 头或 _token 表单字段
 * - 失败返回 JSON {code:1, message:'CSRF token invalid'} (419)
 */
class CheckCsrf
{
    // 内置豁免路径(公开接口,无需 CSRF):验证码发送等首屏 API
    protected array $except = [
        'ajax/auth/sendCode',
        'pay/notify',
    ];

    public function handle(Request $request, \Closure $next)
    {
        // 确保 csrf_token 存在
        if (!Session::get('csrf_token')) {
            Session::set('csrf_token', bin2hex(random_bytes(32)));
        }
        // 共享到视图(供 meta[name=csrf-token] 渲染)
        View::assign('csrf_token', Session::get('csrf_token'));

        // GET/OPTIONS/HEAD 请求放行
        if (in_array($request->method(), ['GET', 'OPTIONS', 'HEAD'], true)) {
            return $next($request);
        }

        // 豁免1: 路由参数标记 csrf_skip=true(用于 /pay/notify 等第三方回调)
        if ($this->isRouteSkipped($request)) {
            return $next($request);
        }

        // 豁免2: 内置公开接口白名单
        $path = $request->pathinfo();
        foreach ($this->except as $except) {
            if ($path === $except || str_starts_with($path, $except . '/')) {
                return $next($request);
            }
        }

        // 校验 token: X-CSRF-Token 头优先,降级 _token 表单字段
        $token         = $request->header('X-CSRF-Token') ?: $request->post('_token', '');
        $sessionToken  = Session::get('csrf_token');

        if (!$sessionToken || !$token || !hash_equals($sessionToken, (string) $token)) {
            return $this->deny($request);
        }

        return $next($request);
    }

    /**
     * 路由是否通过 csrf_skip 选项标记豁免
     * 路由侧使用 ->option(['csrf_skip' => true]) 标记
     */
    protected function isRouteSkipped(Request $request): bool
    {
        $rule = $request->rule();
        if ($rule === null || !method_exists($rule, 'getOption')) {
            return false;
        }
        return (bool) $rule->getOption('csrf_skip', false);
    }

    /**
     * 拒绝响应(419)
     */
    protected function deny(Request $request): Response
    {
        if ($request->isAjax() || str_contains($request->header('accept', ''), 'json')) {
            return Response::create([
                'code'    => 1,
                'message' => 'CSRF token invalid',
                'data'    => [],
            ], 'json', 419);
        }
        return Response::create('CSRF token invalid', 'html', 419);
    }
}
