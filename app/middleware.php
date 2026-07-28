<?php
// 全局中间件注册

return [
    \think\middleware\SessionInit::class,
    // CSRF 校验需在 Session 之后,确保 csrf_token 可读写
    \app\middleware\CheckCsrf::class,
    \app\middleware\RequestId::class,
    \app\middleware\LoadConfig::class,
    \app\middleware\GlobalLog::class,
    \think\middleware\AllowCrossDomain::class,
];
