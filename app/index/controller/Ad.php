<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;

/**
 * 广告点击上报
 */
class Ad extends BaseController
{
    /**
     * 跳转广告链接
     * TODO: 记录点击日志(AdStat), 302 跳转到广告目标链接
     */
    public function click(int $id)
    {
        return $this->success(['id' => $id], 'success');
    }
}
