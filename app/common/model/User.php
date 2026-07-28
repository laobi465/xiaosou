<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;
use think\model\concern\SoftDelete;

/**
 * 用户主表
 * 表: users
 * 状态: 1正常 0封禁
 */
class User extends Model
{
    use SoftDelete;

    protected $name = 'users';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $deleteTime = 'delete_time';

    protected $type = [
        'status' => 'int',
    ];

    /**
     * 一对一: 用户积分余额
     */
    public function credit(): \think\model\relation\HasOne
    {
        return $this->hasOne(UserCredit::class, 'user_id');
    }

    /**
     * 查询范围: 正常用户
     */
    public function scopeNormal($query)
    {
        return $query->where('status', 1);
    }
}
