<?php
// 应用配置 - 多环境通过 .env 注入，零硬编码

return [
    // 应用地址
    'app_host'         => env('APP.APP_HOST', ''),
    // 应用调试模式
    'app_debug'        => env('APP.DEBUG', false),
    // 应用 Trace
    'app_trace'        => false,
    // 应用模式状态
    'app_status'       => 'office',
    // 是否支持多模块
    'auto_multi_app'   => true,
    // 默认应用
    'default_app'      => 'index',
    // 默认时区
    'default_timezone' => 'Asia/Shanghai',
    // 应用映射
    'app_map'          => [],
    // 域名绑定
    'domain_bind'      => [],
    // 禁止 URL 访问的应用
    'deny_app_list'    => ['common'],
    // 异常页 handle 类
    'exception_handle' => \app\ExceptionHandle::class,
    // 错误显示信息
    'show_error_msg'   => env('APP.DEBUG', false),
    // 默认跳转页
    'dispatch_success_tmpl' => app()->getThinkPath() . 'tpl/dispatch_jump.tpl',
    'dispatch_error_tmpl'   => app()->getThinkPath() . 'tpl/dispatch_jump.tpl',
    // 错误模板
    'http_exception_template' => [
        404 => \think\facade\App::getThinkPath() . 'tpl/404.html',
    ],
    // 默认全局过滤
    'default_filter'   => 'trim,htmlspecialchars',
];
