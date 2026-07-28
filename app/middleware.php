<?php
// 全局中间件注册

return [
    \think\middleware\SessionInit::class,
    \app\middleware\RequestId::class,
    \app\middleware\LoadConfig::class,
    \app\middleware\GlobalLog::class,
    \think\middleware\AllowCrossDomain::class,
];
