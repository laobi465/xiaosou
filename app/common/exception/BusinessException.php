<?php
declare(strict_types=1);

namespace app\common\exception;

/**
 * 业务异常基类
 * 统一携带错误码与文案,供全局异常处理器转换为标准响应
 */
class BusinessException extends \RuntimeException
{
    /**
     * @param string $message 异常文案
     * @param int    $code     错误码(参见 app\common\enum\ErrorCode)
     */
    public function __construct(string $message = '', int $code = 1)
    {
        parent::__construct($message, $code);
    }
}
