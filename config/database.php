<?php
// 数据库配置 - 全部从 .env 注入

return [
    'default'     => env('DATABASE.TYPE', 'mysql'),
    'connections' => [
        'mysql' => [
            // 数据库类型
            'type'            => env('DATABASE.TYPE', 'mysql'),
            // 服务器地址
            'hostname'        => env('DATABASE.HOSTNAME', '127.0.0.1'),
            // 数据库名
            'database'        => env('DATABASE.DATABASE', 'pan_search'),
            // 用户名
            'username'        => env('DATABASE.USERNAME', 'root'),
            // 密码
            'password'        => env('DATABASE.PASSWORD', ''),
            // 端口
            'hostport'        => env('DATABASE.HOSTPORT', '3306'),
            // 数据库连接参数
            'params'          => [],
            // 数据库编码默认采用 utf8mb4
            'charset'         => env('DATABASE.CHARSET', 'utf8mb4'),
            // 数据库表前缀
            'prefix'          => env('DATABASE.PREFIX', ''),
            // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
            'deploy'          => (int) env('DATABASE.DEPLOY', 0),
            // 数据库读写是否分离 主从式有效
            'rw_separate'     => (bool) env('DATABASE.RW_SEPARATE', false),
            // 读写分离后 主服务器数量
            'master_num'      => 1,
            // 指定从服务器序号
            'slave_no'        => '',
            // 是否严格检查字段是否存在
            'fields_strict'   => true,
            // 是否需要断线重连
            'break_reconnect' => true,
            // 监听 SQL
            'trigger_sql'     => env('APP.DEBUG', false),
            // 开启字段缓存
            'fields_cache'    => false,
        ],
    ],
];
