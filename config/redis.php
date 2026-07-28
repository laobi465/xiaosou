<?php
// Redis 配置 - 供 think-queue / Session / 自定义使用

return [
    // 默认连接
    'default' => [
        'host'     => env('REDIS.HOST', '127.0.0.1'),
        'port'     => (int) env('REDIS.PORT', 6379),
        'password' => env('REDIS.PASSWORD', ''),
        'select'   => (int) env('REDIS.SELECT', 0),
        'timeout'  => (int) env('REDIS.TIMEOUT', 5),
        'prefix'   => env('REDIS.PREFIX', 'pansou:'),
    ],
];
