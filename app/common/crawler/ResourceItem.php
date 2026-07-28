<?php
declare(strict_types=1);

namespace app\common\crawler;

/**
 * 资源项 DTO
 *
 * 采集器统一返回的资源信息载体。
 * 参见 SearchQuery DTO 模式:构造方法接受数组初始化,
 * 仅赋值本类已声明属性,避免外部字段污染。
 */
class ResourceItem
{
    /** 资源标题 */
    public string $title = '';

    /** 分享链接 */
    public string $share_url = '';

    /** 提取码(可空) */
    public ?string $extract_code = null;

    /** 文件大小(字节,可空) */
    public ?int $file_size = null;

    /** 封面图(可空) */
    public ?string $cover = null;

    /** 资源简介(可空) */
    public ?string $intro = null;

    /** 资源类型: 1影视 2音乐 3软件 4文档 5图片 6压缩包 7其他(可空) */
    public ?int $resource_type = null;

    /**
     * @param array $data 初始化数据,仅赋值已声明属性
     */
    public function __construct(array $data = [])
    {
        foreach ($data as $k => $v) {
            if (property_exists($this, $k)) {
                $this->{$k} = $v;
            }
        }
    }
}
