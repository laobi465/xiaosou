<?php
// 缓存配置 - Redis 驱动

return [
    // 默认驱动
    'default' => env('CACHE_DRIVER', 'redis'),
    // 缓存连接方式
    'stores'  => [
        // 文件缓存
        'file'   => [
            'type'       => 'File',
            'path'       => '',
            'prefix'     => '',
            'expire'     => 0,
            'tag_prefix' => 'tag:',
            'serialize'  => [],
        ],
        // Redis 缓存
        'redis'  => [
            'type'    => 'Redis',
            'host'    => env('REDIS.HOST', '127.0.0.1'),
            'port'    => (int) env('REDIS.PORT', 6379),
            'password'=> env('REDIS.PASSWORD', ''),
            'select'  => (int) env('REDIS.SELECT', 0),
            'timeout' => (int) env('REDIS.TIMEOUT', 5),
            'prefix'  => env('REDIS.PREFIX', 'pansou:cache:'),
        ],
    ],
];
