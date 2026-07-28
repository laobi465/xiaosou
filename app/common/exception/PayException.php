<?php
declare(strict_types=1);

namespace app\common\exception;

use app\common\enum\ErrorCode;

/**
 * 支付异常
 * 默认错误码 3002
 */
class PayException extends BusinessException
{
    public function __construct(string $message = '支付异常', int $code = ErrorCode::ORDER_EXPIRED)
    {
        parent::__construct($message, $code);
    }
}
