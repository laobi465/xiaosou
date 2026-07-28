<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;
use app\common\service\StatService;

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
        $stat = app(StatService::class)->dashboard();
        return view('index/index', ['stat' => $stat]);
    }
}
