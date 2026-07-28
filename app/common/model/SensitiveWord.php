<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 敏感词
 * 表: sensitive_words (仅 create_time)
 * 状态: 1启用 0禁用
 */
class SensitiveWord extends Model
{
    protected $name = 'sensitive_words';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $type = [
        'status' => 'int',
    ];

    /**
     * 查询范围: 启用词
     */
    public function scopeNormal($query)
    {
        return $query->where('status', 1);
    }
}
