<?php
declare(strict_types=1);

namespace app\common\service;

use think\App;
use think\facade\Cache;
use app\common\model\SystemConfig;

/**
 * 系统配置服务
 *
 * 数据来源优先级:
 *   1. 应用容器中的 'system_configs'(由 LoadConfig 中间件加载,结构 [group => [key => value]])
 *   2. 数据库 system_configs 表
 *
 * 缓存策略:
 *   按 group 分组缓存,key 为 'config:{group}',TTL 3600 秒。
 */
class ConfigService
{
    protected App $app;

    /** 缓存前缀 + TTL */
    protected const CACHE_PREFIX = 'config:';
    protected const CACHE_TTL    = 3600;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * 读取单个配置项
     *
     * @param string $key     配置键(system_configs.key,全局唯一)
     * @param mixed  $default 未命中时的默认值
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // 1. 优先从内存容器查找
        $value = $this->getFromMemory($key);
        if ($value !== null) {
            return $value;
        }

        // 2. 内存未命中查 DB
        try {
            $row = SystemConfig::where('key', $key)->field(['value'])->find();
            if ($row && $row->value !== null) {
                return $row->value;
            }
        } catch (\Throwable $e) {
            trace('config_get_error: ' . $e->getMessage(), 'error');
        }

        return $default;
    }

    /**
     * 读取整组配置
     *
     * @param string $group 分组名(smtp/payment/site/credit/security)
     * @return array<string,mixed> [key => value]
     */
    public function getGroup(string $group): array
    {
        // 1. 内存容器
        $memory = $this->getMemoryGroup($group);
        if (!empty($memory)) {
            return $memory;
        }

        // 2. 缓存
        try {
            $cached = Cache::get(self::CACHE_PREFIX . $group);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // 3. DB
        return $this->loadGroupFromDb($group);
    }

    /**
     * 写入/更新单个配置项
     *
     * @param string $key   配置键
     * @param mixed  $value 配置值(数组/对象会被 json 编码)
     * @return bool
     */
    public function set(string $key, mixed $value): bool
    {
        $stored = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);

        try {
            $row = SystemConfig::where('key', $key)->find();
            if ($row) {
                $row->value = $stored;
                $row->save();
                $group = $row->getData('group');
            } else {
                // 新建配置,默认归入 'site' 组
                $group = 'site';
                SystemConfig::create([
                    'group' => $group,
                    'key'   => $key,
                    'value' => $stored,
                ]);
            }

            // 刷新该组缓存与内存
            if ($group) {
                $this->flushGroup($group);
            }
            return true;
        } catch (\Throwable $e) {
            trace('config_set_error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * 清除指定分组的缓存
     */
    public function flushGroup(string $group): void
    {
        try {
            Cache::delete(self::CACHE_PREFIX . $group);
        } catch (\Throwable $e) {
            // ignore
        }

        // 同步刷新内存容器中的对应分组
        try {
            $configs = $this->app->has('system_configs') ? $this->app->get('system_configs') : [];
            if (is_array($configs) && isset($configs[$group])) {
                unset($configs[$group]);
                $this->app->instance('system_configs', $configs);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * 从内存容器查找单个配置值
     */
    protected function getFromMemory(string $key): mixed
    {
        $configs = $this->getMemoryAll();
        foreach ($configs as $group => $items) {
            if (is_array($items) && array_key_exists($key, $items)) {
                return $items[$key];
            }
        }
        return null;
    }

    /**
     * 从内存容器读取整组配置
     */
    protected function getMemoryGroup(string $group): array
    {
        $configs = $this->getMemoryAll();
        return is_array($configs[$group] ?? null) ? $configs[$group] : [];
    }

    /**
     * 获取内存容器中的全部配置
     */
    protected function getMemoryAll(): array
    {
        try {
            $configs = $this->app->has('system_configs') ? $this->app->get('system_configs') : [];
            return is_array($configs) ? $configs : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 从 DB 加载整组配置并写入缓存
     */
    protected function loadGroupFromDb(string $group): array
    {
        try {
            $items = SystemConfig::where('group', $group)
                ->field(['key', 'value'])
                ->select()
                ->toArray();
            $result = [];
            foreach ($items as $item) {
                $result[$item['key']] = $item['value'];
            }
            if (!empty($result)) {
                Cache::set(self::CACHE_PREFIX . $group, $result, self::CACHE_TTL);
            }
            return $result;
        } catch (\Throwable $e) {
            trace('config_load_group_error: ' . $e->getMessage(), 'error');
            return [];
        }
    }
}
