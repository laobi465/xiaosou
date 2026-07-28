<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 用户登录日志
 * 表: user_login_logs (仅 create_time)
 * 登录方式: 1验证码 2密码; 结果: 1成功 0失败
 */
class UserLoginLog extends Model
{
    protected $name = 'user_login_logs';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $type = [
        'login_type' => 'int',
        'result'     => 'int',
    ];

    /**
     * 反向关联: 所属用户(失败可能为空)
     */
    public function user(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
