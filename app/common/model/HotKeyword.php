<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 热搜词归档
 * 表: hot_keywords (仅 create_time)
 */
class HotKeyword extends Model
{
    protected $name = 'hot_keywords';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $type = [
        'search_cnt' => 'int',
    ];
}
