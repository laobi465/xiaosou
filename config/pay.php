<?php
// 彩虹易支付配置

return [
    'pid'        => env('PAY.CAIHONG_PID', ''),
    'key'        => env('PAY.CAIHONG_KEY', ''),
    'api'        => env('PAY.CAIHONG_API', 'https://pay.cccdl.com'),
    'notify_url' => env('PAY.NOTIFY_URL', ''),
    'return_url' => env('PAY.RETURN_URL', ''),
];
