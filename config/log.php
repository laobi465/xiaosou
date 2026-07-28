<?php
// 日志配置

return [
    'default'  => 'file',
    'channels' => [
        'file' => [
            'type'           => 'file',
            'path'           => runtime_path() . 'log/',
            'apart_level'    => ['error', 'sql', 'slow', 'pay', 'mail'],
            'max_files'      => 30,
            'format'         => '[%s][%s] %s',
            'realtime_write' => false,
        ],
        // 支付日志独立通道
        'pay'  => [
            'type'      => 'file',
            'path'      => runtime_path() . 'log/pay/',
            'max_files' => 365,
        ],
        // 邮件日志独立通道
        'mail' => [
            'type'      => 'file',
            'path'      => runtime_path() . 'log/mail/',
            'max_files' => 90,
        ],
    ],
];
