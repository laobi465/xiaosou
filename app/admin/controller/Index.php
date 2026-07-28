<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;

/**
 * 仪表盘
 */
class Index extends BaseAdminController
{
    /**
     * 仪表盘
     */
    public function index()
    {
        return view('index/index');
    }
}
