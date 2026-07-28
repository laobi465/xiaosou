<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use app\common\service\AdService;

/**
 * 广告点击上报
 */
class Ad extends BaseController
{
    /**
     * 跳转广告链接
     * AdService::click → 302 跳转 link_url
     */
    public function click(int $id)
    {
        $result = app(AdService::class)->click($id);
        $linkUrl = (string) ($result['link_url'] ?? '');
        if ($linkUrl === '') {
            $this->fail('广告不存在或已下线');
        }
        $this->redirect($linkUrl, 302);
    }
}
