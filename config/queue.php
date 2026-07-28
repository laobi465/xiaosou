<?php
// 队列配置 - Redis 驱动，含业务通道

return [
    'default'      => 'redis',
    'connections'  => [
        'sync'     => ['type' => 'sync'],
        'database' => [
            'type'       => 'database',
            'queue'      => 'default',
            'table'      => 'jobs',
            'connection' => null,
            'retry_after' => 90,
        ],
        'redis'    => [
            'type'       => 'redis',
            'queue'      => 'default',
            'connection' => 'default',
            'retry_after'=> 90,
            'block_for'  => null,
        ],
    ],
    // 业务通道映射 - 各通道独立队列名
    'channels'     => [
        'crawl' => 'crawl_queue',  // 爬虫采集
        'mail'  => 'mail_queue',   // 邮件发送
        'stat'  => 'stat_queue',   // 统计聚合
    ],
    // 失败任务处理
    'failed'       => [
        'type'  => 'none',
        'table' => 'failed_jobs',
    ],
];
