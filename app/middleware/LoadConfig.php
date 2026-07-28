<?php
namespace app\middleware;

use Closure;
use think\App;
use think\Request;
use think\Response;

/**
 * 启动加载系统配置到内存(带缓存)
 * 缓存未命中时从 system_configs 表读取
 */
class LoadConfig
{
    protected App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function handle(Request $request, Closure $next): Response
    {
        // 安装向导等路由标记 skip_load_config 时跳过(避免 Redis/DB 未就绪时的无谓连接尝试)
        $rule = $request->rule();
        if ($rule !== null && method_exists($rule, 'getOption') && $rule->getOption('skip_load_config', false)) {
            return $next($request);
        }

        try {
            $this->loadConfigs();
        } catch (\Throwable $e) {
            // 启动阶段加载失败不阻塞请求，降级使用 config/pan.php 默认值
            trace('load_config_error: ' . $e->getMessage(), 'error');
        }
        return $next($request);
    }

    /**
     * 加载 system_configs 表的配置到全局容器
     */
    protected function loadConfigs(): void
    {
        $cache = $this->app->cache;
        $groups = ['smtp', 'payment', 'site', 'credit', 'security'];
        $configs = [];
        foreach ($groups as $group) {
            $data = $cache->get('config:' . $group);
            if ($data === null) {
                $data = $this->fetchFromDb($group);
                if ($data !== null) {
                    $cache->set('config:' . $group, $data, 3600);
                }
            }
            if ($data !== null) {
                $configs[$group] = $data;
            }
        }
        $this->app->instance('system_configs', $configs);
    }

    /**
     * 从数据库读取配置
     */
    protected function fetchFromDb(string $group): ?array
    {
        try {
            $items = \app\common\model\SystemConfig::where('group', $group)
                ->field(['key', 'value'])
                ->select()
                ->toArray();
            if (empty($items)) {
                return null;
            }
            $result = [];
            foreach ($items as $item) {
                $result[$item['key']] = $item['value'];
            }
            return $result;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
