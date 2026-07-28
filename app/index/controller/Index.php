<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;

/**
 * 首页
 */
class Index extends BaseController
{
    /**
     * 首页
     */
    public function index()
    {
        return view('index/index');
    }
}
