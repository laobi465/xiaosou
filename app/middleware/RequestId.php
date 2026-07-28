<?php
namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 注入 X-Request-Id 用于链路追踪
 */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id');
        if (empty($requestId)) {
            $requestId = $this->generate();
        }
        $request->withHeader(['X-Request-Id' => $requestId]);

        /** @var Response $response */
        $response = $next($request);
        $response->header(['X-Request-Id' => $requestId]);
        return $response;
    }

    private function generate(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
