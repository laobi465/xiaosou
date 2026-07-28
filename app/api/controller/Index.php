<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;

/**
 * API 入口
 */
class Index extends BaseController
{
    /**
     * 健康检查
     */
    public function health()
    {
        return $this->success(['status' => 'ok']);
    }
}
