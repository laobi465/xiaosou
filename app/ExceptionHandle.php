<?php
namespace app;

use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;
use app\common\exception\BusinessException;

/**
 * 全局异常处理
 * - 业务异常按错误码输出
 * - 生产环境屏蔽详细错误
 * - 记录到 runtime/log/
 */
class ExceptionHandle extends Handle
{
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ValidateException::class,
        BusinessException::class,
    ];

    /**
     * 上报异常(写日志)
     */
    public function report(Throwable $exception): void
    {
        // 业务异常不写日志
        if ($exception instanceof BusinessException) {
            return;
        }
        parent::report($exception);
    }

    /**
     * 渲染异常为响应
     */
    public function render($request, Throwable $e): Response
    {
        $requestId = $request->header('X-Request-Id', '');

        // 业务异常
        if ($e instanceof BusinessException) {
            return $this->jsonResponse($e->getCode() ?: 1, $e->getMessage(), [], $requestId);
        }

        // 参数验证异常
        if ($e instanceof ValidateException) {
            return $this->jsonResponse(1001, $e->getError(), [], $requestId);
        }

        // Ajax/JSON 请求
        if ($request->isAjax() || str_contains($request->header('accept', ''), 'json')) {
            $debug  = (bool) env('APP.DEBUG', false);
            $message = $debug ? $e->getMessage() : '系统繁忙';
            $data    = $debug ? [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ] : [];
            return $this->jsonResponse(5000, $message, $data, $requestId);
        }

        // 普通页面请求
        if ($e instanceof HttpException && $e->getStatusCode() === 404) {
            return Response::create('', 'view')->assign('request_id', $requestId)
                ->code(404)->view('layout/404');
        }

        return parent::render($request, $e);
    }

    /**
     * 统一 JSON 响应
     */
    protected function jsonResponse(int $code, string $message, mixed $data, string $requestId): Response
    {
        $payload = [
            'code'       => $code,
            'message'    => $message,
            'data'       => $data,
            'request_id' => $requestId,
            'timestamp'  => time(),
        ];
        return Response::create($payload, 'json');
    }
}
