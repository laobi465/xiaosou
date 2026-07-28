<?php
namespace app;

use think\App;
use think\exception\HttpResponseException;
use think\Response;

/**
 * 控制器基类
 * 封装统一响应、视图渲染、用户态获取
 */
abstract class BaseController
{
    protected App $app;
    protected \think\Request $request;

    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $app->request;
    }

    /**
     * 成功响应
     */
    protected function success(mixed $data = [], string $message = 'success'): Response
    {
        return $this->apiResponse(0, $message, $data);
    }

    /**
     * 错误响应
     */
    protected function error(string $message = 'error', int $code = 1, mixed $data = []): Response
    {
        return $this->apiResponse($code, $message, $data);
    }

    /**
     * 统一 API 响应格式
     */
    protected function apiResponse(int $code, string $message, mixed $data): Response
    {
        $payload = [
            'code'       => $code,
            'message'    => $message,
            'data'       => $data,
            'request_id' => $this->request->header('X-Request-Id', ''),
            'timestamp'  => time(),
        ];
        return Response::create($payload, 'json');
    }

    /**
     * 抛出业务异常(被全局异常处理器接管)
     */
    protected function fail(string $message = 'error', int $code = 1): void
    {
        throw new \app\common\exception\BusinessException($message, $code);
    }

    /**
     * 获取当前登录用户ID
     */
    protected function userId(): ?int
    {
        $userId = $this->request->userId ?? null;
        return $userId !== null ? (int) $userId : null;
    }

    /**
     * 判断是否登录
     */
    protected function isLogged(): bool
    {
        return $this->userId() !== null;
    }

    /**
     * 跳转
     */
    protected function redirect(string $url, int $code = 302): void
    {
        throw new HttpResponseException(Response::create($url, 'redirect')->code($code));
    }
}
