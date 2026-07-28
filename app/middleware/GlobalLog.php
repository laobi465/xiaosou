<?php
namespace app\middleware;

use Closure;
use think\App;
use think\Request;
use think\Response;

/**
 * 全局日志 - 慢请求(>阈值)记录
 */
class GlobalLog
{
    protected App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        /** @var Response $response */
        $response = $next($request);
        $duration = (int) ((microtime(true) - $start) * 1000);

        $threshold = (int) ($this->app->config->get('pan.slow_request_ms', 1000));
        if ($duration >= $threshold) {
            trace(sprintf(
                '[SLOW] %s %s | %dms | ip:%s | ua:%s',
                $request->method(),
                $request->url(),
                $duration,
                $request->ip(),
                substr($request->header('user-agent', ''), 0, 100)
            ), 'slow');
        }
        return $response;
    }
}
