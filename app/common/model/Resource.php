<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;
use think\model\concern\SoftDelete;

/**
 * 资源主表(去重)
 * 表: resources
 * 类型: 1影视 2音乐 3软件 4文档 5图片 6压缩包 7其他
 * 来源: 1爬虫 2用户提交
 * 状态: 1正常 0失效 2待审 3驳回
 *
 * 注: FULLTEXT 索引 ft_title_intro(title, intro) 由 DB 维护, 模型不声明。
 */
class Resource extends Model
{
    use SoftDelete;

    protected $name = 'resources';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $deleteTime = 'delete_time';

    protected $type = [
        'resource_type'   => 'int',
        'file_size'        => 'int',
        'source_type'      => 'int',
        'status'           => 'int',
        'view_count'       => 'int',
        'link_view_count'  => 'int',
        'submitter_id'     => 'int',
    ];

    /**
     * 一对多: 资源链接(多源)
     */
    public function links(): \think\model\relation\HasMany
    {
        return $this->hasMany(ResourceLink::class, 'resource_id');
    }

    /**
     * 一对多: 失效举报
     */
    public function reports(): \think\model\relation\HasMany
    {
        return $this->hasMany(ResourceReport::class, 'resource_id');
    }

    /**
     * 反向关联: 提交者(用户提交时)
     */
    public function submitter(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(User::class, 'submitter_id');
    }

    /**
     * 查询范围: 正常资源
     */
    public function scopeNormal($query)
    {
        return $query->where('status', 1);
    }
}
