<?php
namespace app\index\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 访问日志记录(轻量化,异步落库由后续队列接管)
 */
class VisitorLog
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        /** @var Response $response */
        $response = $next($request);
        $duration = (int) ((microtime(true) - $start) * 1000);

        // 仅记录非静态资源请求
        if (!str_starts_with($request->pathinfo(), 'static/')) {
            trace(sprintf(
                '[VISIT] %s %s | %d | %dms | ip:%s',
                $request->method(),
                $request->url(),
                $response->getCode(),
                $duration,
                $request->ip()
            ), 'info');
        }
        return $response;
    }
}
