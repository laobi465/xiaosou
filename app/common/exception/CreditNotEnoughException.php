<?php
declare(strict_types=1);

namespace app\common\exception;

use app\common\enum\ErrorCode;

/**
 * 积分不足异常
 * 默认错误码 3001
 */
class CreditNotEnoughException extends BusinessException
{
    public function __construct(string $message = '积分不足', int $code = ErrorCode::CREDIT_NOT_ENOUGH)
    {
        parent::__construct($message, $code);
    }
}
