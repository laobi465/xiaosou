<?php
// Session 配置 - Redis 驱动

return [
    'type'           => 'redis',
    'prefix'         => env('SECURITY.SESSION_PREFIX', 'pansou_:'),
    'expire'         => 7200,
    'auto_start'     => true,
    'name'           => 'PANSOU_SID',
    'cookie'         => [
        'httponly' => true,
        'secure'   => false,
        'samesite' => 'Lax',
    ],
    // Redis 连接
    'connection'     => [
        'host'     => env('REDIS.HOST', '127.0.0.1'),
        'port'     => (int) env('REDIS.PORT', 6379),
        'password' => env('REDIS.PASSWORD', ''),
        'select'   => (int) env('REDIS.SELECT', 0),
    ],
];
