<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;

/**
 * 搜索
 */
class Search extends BaseController
{
    /**
     * 搜索页
     */
    public function index()
    {
        return view('search/index');
    }

    /**
     * 热搜词(Ajax)
     * TODO: 接入 HotKeyword 模型返回真实热搜词
     */
    public function hot()
    {
        return $this->success([], 'success');
    }
}
