<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 邮箱验证码(审计, 主流程用 Redis)
 * 表: email_verifies (仅 create_time)
 * 类型: 1注册 2登录 3重置密码
 */
class EmailVerify extends Model
{
    protected $name = 'email_verifies';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $type = [
        'type' => 'int',
        'used' => 'int',
    ];
}
