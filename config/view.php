<?php
// 视图配置 - ThinkPHP 原生模板引擎

return [
    // 模板引擎类型
    'type'          => 'Think',
    // 默认模板渲染规则 1 解析为小写+下划线 2 全小写 3 保持原样
    'auto_rule'     => 1,
    // 模板目录名
    'view_dir_name' => 'view',
    // 模板路径
    'view_path'     => '',
    // 模板后缀
    'view_suffix'   => 'html',
    // 模板输出替换
    'tpl_replace_string' => [
        '__STATIC__' => '/static',
        '__CSS__'    => '/static/css',
        '__JS__'     => '/static/js',
        '__IMG__'    => '/static/img',
    ],
    // 模板渲染缓存
    'tpl_cache'     => !env('APP.DEBUG', false),
    // 标签库
    'tpl_begin'     => '{',
    'tpl_end'       => '}',
    // 布局模板开关
    'layout_on'     => true,
    'layout_name'   => 'layout/main',
    'layout_item'   => '{__CONTENT__}',
];
