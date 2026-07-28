<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 系统配置(KV)
 * 表: system_configs (仅 update_time)
 * group: smtp/payment/site/credit/security
 */
class SystemConfig extends Model
{
    protected $name = 'system_configs';

    protected $autoWriteTimestamp = 'datetime';

    // 该表无 create_time 字段
    protected $createTime = false;
    protected $updateTime = 'update_time';
}
