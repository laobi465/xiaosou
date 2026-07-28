<?php
namespace app;

use think\Request as ThinkRequest;

/**
 * 请求类扩展 - 附加 userId/adminId 等业务字段
 */
class Request extends ThinkRequest
{
    /** @var int|null 前台用户ID */
    public ?int $userId = null;

    /** @var int|null 后台管理员ID */
    public ?int $adminId = null;
}
