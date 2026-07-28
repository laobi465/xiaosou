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
    // 慢请求阈值(毫秒)
    'slow_request_ms' => 1000,
];
