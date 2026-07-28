<?php
// 路由配置

return [
    // pathinfo分隔符
    'pathinfo_depr'         => '/',
    // URL伪静态后缀
    'url_html_suffix'       => 'html',
    // URL普通方式参数 用于自动生成
    'url_common_param'      => true,
    // 是否开启路由延迟解析
    'url_lazy_route'        => false,
    // 是否强制使用路由
    'url_route_must'        => true,
    // 合并路由规则
    'route_route_map'       => [],
    // 路由是否完全匹配
    'route_complete_match'  => true,
    // 是否开启路由缓存
    'route_check_cache'     => true,
    // 路由缓存连接
    'route_cache_option'    => [],
    // 路由缓存键
    'route_check_cache_key' => '',
    // 路由默认请求类型
    'default_method'        => 'GET',
    // 跨域允许
    'allow_cross_domain'    => [
        'allow_origin'      => array_filter(explode(',', env('CORS.ALLOW_ORIGIN', ''))),
        'allow_methods'     => 'GET,POST,PUT,DELETE,PATCH,OPTIONS',
        'allow_headers'     => 'X-Requested-With,Content-Type,Token,X-Request-Id,X-CSRF-Token',
        'expose_headers'    => 'X-Request-Id',
        'allow_credentials' => false,
        'max_age'           => 600,
    ],
];
