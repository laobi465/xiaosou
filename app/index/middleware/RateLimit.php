<?php
namespace app\index\middleware;

use Closure;
use think\App;
use think\Request;
use think\Response;
use app\common\service\RateLimiter;

/**
 * 接口限流 - Redis 滑动窗口
 *
 * 路由挂载示例:
 *   ->middleware(RateLimit::class, '60')                       // 60次/60秒,按 路由+IP
 *   ->middleware(RateLimit::class, '5', '60', 'email')         // 5次/60秒,按 路由+IP+email字段
 *   ->middleware(RateLimit::class, '20', '60')                 // 20次/60秒
 *
 * 参数(均以字符串形式从路由传入):
 *   $limit    窗口内最大次数,留空则读 pan.rate_limit.search_per_min
 *   $window   窗口大小(秒),留空则读 pan.rate_limit.default_window
 *   $keyField 额外 key 维度的请求字段名(如 email),用于按邮箱/用户等细分限流
 */
class RateLimit
{
    protected App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param Request   $request
     * @param Closure   $next
     * @param string|null $limit   路由参数:窗口内最大次数
     * @param string|null $window  路由参数:窗口大小(秒)
     * @param string|null $keyField 路由参数:额外 key 维度的请求字段名
     */
    public function handle(Request $request, Closure $next, $limit = null, $window = null, $keyField = null): Response
    {
        $routeKey = $this->resolveRouteKey($request);
        $ip       = $request->ip();
        $limit    = $this->resolveLimit($limit);
        $window   = $this->resolveWindow($window);

        // 拼 key: rate:api:{route}[:{extra}]:{ip}
        $extra = '';
        if (!empty($keyField)) {
            $fieldValue = (string) $request->param($keyField, '');
            $extra = ':' . ($fieldValue !== '' ? md5($fieldValue) : 'anon');
        }
        $key = 'rate:api:' . $routeKey . $extra . ':' . $ip;

        try {
            /** @var RateLimiter $limiter */
            $limiter = $this->app->make(RateLimiter::class);

            // fail-closed: Redis 不可用时根据配置决定是否拒绝
            if (!$limiter->isAvailable()) {
                if ($this->isFailClosed()) {
                    return $this->deny($request);
                }
                return $next($request);
            }

            if (!$limiter->allow($key, $limit, $window)) {
                return $this->deny($request);
            }
        } catch (\Throwable $e) {
            // DI 异常等:根据 fail_closed 决定放行/拒绝
            trace('rate_limit_error: ' . $e->getMessage(), 'error');
            if ($this->isFailClosed()) {
                return $this->deny($request);
            }
        }
        return $next($request);
    }

    /**
     * 解析路由维度 key:优先路由规则路径,降级 controller/action(校验非空),再降级 pathinfo
     */
    protected function resolveRouteKey(Request $request): string
    {
        $rule = $request->rule();
        if ($rule !== null) {
            $pattern = (string) $rule->getRule();
            if ($pattern !== '') {
                return $this->sanitizeKey($pattern);
            }
        }

        $controller = $request->controller(true);
        $action     = $request->action(true);
        if ($controller !== '' && $action !== '') {
            return $this->sanitizeKey($controller . '/' . $action);
        }

        // 兜底:使用 pathinfo,确保 key 非空
        return $this->sanitizeKey($request->pathinfo() ?: 'unknown');
    }

    /**
     * 解析窗口内最大次数:优先路由参数,降级配置
     */
    protected function resolveLimit($limit): int
    {
        if ($limit !== null && $limit !== '') {
            $val = (int) $limit;
            if ($val > 0) {
                return $val;
            }
        }
        return (int) $this->app->config->get('pan.rate_limit.search_per_min', 60);
    }

    /**
     * 解析时间窗口(秒):优先路由参数,降级配置
     */
    protected function resolveWindow($window): int
    {
        if ($window !== null && $window !== '') {
            $val = (int) $window;
            if ($val > 0) {
                return $val;
            }
        }
        return (int) $this->app->config->get('pan.rate_limit.default_window', 60);
    }

    /**
     * Redis 不可用时是否拒绝(fail-closed)
     */
    protected function isFailClosed(): bool
    {
        return (bool) $this->app->config->get('pan.rate_limit.fail_closed', false);
    }

    /**
     * 清理 key 中的特殊字符,避免冒号分隔冲突
     */
    protected function sanitizeKey(string $value): string
    {
        return str_replace([':', ' ', "\t", "\n", "\r"], ['_', '', '', '', ''], $value);
    }

    /**
     * 拒绝响应(429)
     */
    protected function deny(Request $request): Response
    {
        return Response::create([
            'code'       => 1003,
            'message'    => '操作过于频繁,请稍后再试',
            'data'       => [],
            'request_id' => $request->header('X-Request-Id', ''),
            'timestamp'  => time(),
        ], 'json')->code(429);
    }
}
