<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;

/**
 * 采集任务
 */
class Crawl extends BaseAdminController
{
    public function index()
    {
        return view('crawl/index');
    }

    public function add()
    {
        return view('crawl/add');
    }

    public function edit(int $id)
    {
        return view('crawl/edit', ['id' => $id]);
    }

    public function delete(int $id)
    {
        return $this->success(['id' => $id], 'success');
    }
}
