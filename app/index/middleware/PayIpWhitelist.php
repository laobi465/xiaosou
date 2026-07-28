<?php
declare(strict_types=1);

namespace app\index\middleware;

use think\Request;
use think\Response;

class PayIpWhitelist
{
    public function handle(Request $request, \Closure $next)
    {
        $allowIps = array_filter(explode(',', (string) config('pan.pay.notify_allow_ips', '')));
        if (empty($allowIps)) {
            // 未配置则放行(依赖签名校验)
            return $next($request);
        }
        $clientIp = $request->ip();
        if (!in_array($clientIp, $allowIps, true)) {
            return Response::create('Forbidden', 'html', 403);
        }
        return $next($request);
    }
}
