<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;

/**
 * 公共异步接口
 */
class Ajax extends BaseController
{
    /**
     * 热搜词
     * TODO: 读取 HotKeyword 缓存/模型
     */
    public function hotKeywords()
    {
        return $this->success([], 'success');
    }

    /**
     * 资源失效举报
     * TODO: 写入 resource_reports 表, 限流
     */
    public function reportResource(int $id)
    {
        return $this->success(['id' => $id], 'success');
    }
}
