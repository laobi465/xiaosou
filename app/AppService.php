<?php
namespace app;

use Pansou\Search\SearchDriverInterface;
use Pansou\Search\MysqlFulltextDriver;
use think\Service;

/**
 * 应用服务提供者
 */
class AppService extends Service
{
    public function boot(): void
    {
        // 全局绑定/事件监听可在此注册
    }

    /**
     * 服务注册
     *
     * 绑定搜索驱动接口到默认实现, 便于通过 app(SearchService::class) 解析。
     * 切换为 Elasticsearch 时仅需调整此绑定。
     */
    public function register(): void
    {
        $this->app->bind(SearchDriverInterface::class, MysqlFulltextDriver::class);
    }
}
