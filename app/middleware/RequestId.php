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
    /**
     * UUID v4 正则(version=4, variant=8/9/a/b)
     */
    private const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id');
        if (empty($requestId) || !$this->isValidUuidV4($requestId)) {
            $requestId = $this->generate();
        }

        // 合并写入,避免 withHeader 整体覆盖丢失其他 header
        $request->withHeader(array_merge($request->header(), ['X-Request-Id' => $requestId]));

        /** @var Response $response */
        $response = $next($request);
        $response->header(['X-Request-Id' => $requestId]);
        return $response;
    }

    /**
     * 校验客户端传入的 X-Request-Id 是否为合法 UUID v4
     */
    private function isValidUuidV4(string $requestId): bool
    {
        return (bool) preg_match(self::UUID_V4_PATTERN, $requestId);
    }

    private function generate(): string
    {
        // 优先使用 CSPRNG 生成,保证版本号与变体位符合 UUID v4 规范
        if (function_exists('random_bytes')) {
            $data = random_bytes(16);
            $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
            $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant 10
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }

        // 兜底:mt_rand
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
