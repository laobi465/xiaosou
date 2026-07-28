<?php
// 控制台命令注册

return [
    'commands' => [
        'install'       => \app\command\Install::class,
        'crawl:dispatch'=> \app\command\CrawlDispatch::class,
        'crawl:consume' => \app\command\CrawlConsume::class,
        'mail:consume'  => \app\command\MailConsume::class,
        'ad:agg'        => \app\command\AdStatAgg::class,
        'order:close'   => \app\command\OrderClose::class,
    ],
];
