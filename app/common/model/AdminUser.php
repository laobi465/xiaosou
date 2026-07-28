<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 管理员
 * 表: admin_users
 * 状态: 1正常 0禁用
 */
class AdminUser extends Model
{
    protected $name = 'admin_users';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $type = [
        'status' => 'int',
    ];

    /**
     * 隐藏敏感字段: 密码哈希(序列化时不再暴露)
     */
    protected $hidden = ['password'];

    /**
     * 一对多: 管理员操作日志
     */
    public function logs(): \think\model\relation\HasMany
    {
        return $this->hasMany(AdminLog::class, 'admin_id');
    }

    /**
     * 查询范围: 正常管理员
     */
    public function scopeNormal($query)
    {
        return $query->where('status', 1);
    }
}
