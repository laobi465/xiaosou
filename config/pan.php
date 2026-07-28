<?php
// 业务自定义配置 - 限流阈值/积分默认值等，可被 system_configs 表覆盖

return [
    // 限流配置
    'rate_limit' => [
        'search_per_min'          => 60,   // 搜索接口 IP/分钟
        'verify_send_per_email'   => 1,    // 验证码发送 同邮箱/60秒
        'verify_send_per_ip_10m'  => 5,    // 验证码发送 同IP/10分钟
        'verify_check_per_email'  => 5,    // 验证码校验 同邮箱/5分钟
        'submit_per_hour'         => 5,    // 资源提交 同用户/小时
        'pay_notify_per_min'      => 100,  // 支付回调 同IP/分钟
        'admin_per_min'           => 300,  // 后台操作 管理员/分钟
        // Redis 不可用时的降级策略:false=放行(降级,fail-open) true=拒绝(保守,fail-closed)
        'fail_closed'             => false,
        // 默认时间窗口(秒),未被路由参数显式指定时使用
        'default_window'          => 60,
    ],
    // 积分默认值（运行时被 system_configs 覆盖）
    'credit' => [
        'register_gift'    => 10,  // 注册赠送
        'sign_in'          => 1,   // 签到奖励
        'sign_in_continuous' => 5, // 连续7天额外奖励
        'view_link'        => 1,   // 查看链接消耗
        'submit_reward'    => 5,   // 提交审核通过奖励
    ],
    // 订单配置
    'order' => [
        'expire_minutes' => 30,   // 订单过期时间(分钟)
    ],
    // 支付回调配置
    'pay' => [
        // 异步通知 IP 白名单(逗号分隔, 留空则放行依赖签名校验)
        'notify_allow_ips' => env('PAY.NOTIFY_ALLOW_IPS', ''),
    ],
    // 首页展示配置
    'index' => [
        'hot_keywords'  => 10, // 首页热搜词数量
        'latest_limit'  => 12, // 首页最新资源数量
    ],
    // 验证码配置
    'verify_code' => [
        'length'   => 6,          // 验证码长度
        'ttl'      => 300,        // 有效期(秒)
        'max_try'  => 5,          // 最大尝试次数
    ],
    // 会话配置
    'session' => [
        'prefix'        => env('SECURITY.SESSION_PREFIX', 'pansou_'),
        'admin_prefix'  => env('SECURITY.ADMIN_SESSION_PREFIX', 'pansou_admin_'),
        'expire'        => 7200,  // 普通会话过期(秒)
        'remember_expire' => 604800, // 记住我过期(7天)
    ],
    // 安全配置
    'security' => [
        // 敏感词 DFA 加载失败时的策略: false=放行(fail-open) true=拒绝(fail-closed)
        'sensitive_fail_closed' => false,
    ],
    // 慢请求阈值(毫秒)
    'slow_request_ms' => 1000,
    // 分页配置
    'page_size' => [
        'admin' => 15, // 后台列表默认每页条数
        'index' => 15, // 前台列表默认每页条数
        'max'    => 100, // 单次请求允许的最大条数(防止过大分页)
    ],
    // 静态资源版本号(更新静态资源后递增以破缓存)
    'asset_version' => '1.0.1',
];
