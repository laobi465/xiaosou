<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 积分套餐
 * 表: credit_packages
 * 状态: 1上架 0下架; is_recommended: 1推荐 0否
 */
class CreditPackage extends Model
{
    protected $name = 'credit_packages';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $type = [
        'price'          => 'decimal',
        'credits'        => 'int',
        'bonus'          => 'int',
        'is_recommended' => 'int',
        'status'         => 'int',
        'sort'           => 'int',
    ];

    /**
     * 一对多: 关联订单
     */
    public function orders(): \think\model\relation\HasMany
    {
        return $this->hasMany(Order::class, 'package_id');
    }

    /**
     * 查询范围: 上架套餐(语义化,推荐使用)
     */
    public function scopeListed($query)
    {
        return $query->where('status', 1);
    }

    /**
     * 查询范围: 上架套餐(兼容旧调用,语义不精确,推荐使用 scopeListed)
     * @deprecated 请使用 scopeListed
     */
    public function scopeNormal($query)
    {
        return $query->where('status', 1);
    }
}
