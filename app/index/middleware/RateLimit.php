<?php
namespace app\index\middleware;

use Closure;
use think\App;
use think\Request;
use think\Response;
use app\common\service\RateLimiter;

/**
 * 接口限流 - Redis 滑动窗口
 */
class RateLimit
{
    protected App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->controller() . '/' . $request->action();
        $ip    = $request->ip();
        $key   = 'rate:api:' . $route . ':' . $ip;
        $limit = (int) $this->app->config->get('pan.rate_limit.search_per_min', 60);

        try {
            /** @var RateLimiter $limiter */
            $limiter = $this->app->make(RateLimiter::class);
            if (!$limiter->allow($key, $limit, 60)) {
                return Response::create([
                    'code'       => 1003,
                    'message'    => '操作过于频繁,请稍后再试',
                    'data'       => [],
                    'request_id' => $request->header('X-Request-Id', ''),
                    'timestamp'  => time(),
                ], 'json')->code(429);
            }
        } catch (\Throwable $e) {
            // 限流降级:Redis 不可用时放行,不影响业务
            trace('rate_limit_error: ' . $e->getMessage(), 'error');
        }
        return $next($request);
    }
}
