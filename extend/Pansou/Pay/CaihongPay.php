<?php
declare(strict_types=1);

namespace Pansou\Pay;

use Pansou\Helper\SignatureHelper;

/**
 * 彩虹易支付 SDK
 *
 * 参见架构设计文档 3.3 节(行 374-392)。
 *
 * 协议要点:
 *   - 跳转收银台: GET {api}/submit.php?{params}&sign=xxx&sign_type=MD5
 *   - 签名规则: 过滤空值与 sign/sign_type,按 key 升序拼接 key=value&,末尾拼商户密钥,md5 小写
 *   - 异步通知: 验签后回写 "success"
 *
 * 配置来源(优先级):
 *   1. 构造方法传入的 config 数组
 *   2. config/pay.php 的 caihong 节
 *   3. env: PAY.CAIHONG_PID / PAY.CAIHONG_KEY / PAY.CAIHONG_API / PAY.NOTIFY_URL / PAY.RETURN_URL
 */
class CaihongPay
{
    /** 商户ID */
    protected string $pid;
    /** 商户密钥 */
    protected string $key;
    /** 接口地址(如 https://pay.cccdl.com) */
    protected string $api;
    /** 异步通知地址 */
    protected string $notifyUrl;
    /** 同步跳转地址 */
    protected string $returnUrl;

    public function __construct(array $config = [])
    {
        $this->pid       = (string) ($config['pid'] ?? $this->readConfig('pid', 'PAY.CAIHONG_PID', 'pay.caihong.pid'));
        $this->key       = (string) ($config['key'] ?? $this->readConfig('key', 'PAY.CAIHONG_KEY', 'pay.caihong.key'));
        $this->api       = rtrim((string) ($config['api'] ?? $this->readConfig('api', 'PAY.CAIHONG_API', 'pay.caihong.api')), '/');
        $this->notifyUrl = (string) ($config['notify_url'] ?? $this->readConfig('notify_url', 'PAY.NOTIFY_URL', 'pay.caihong.notify_url'));
        $this->returnUrl = (string) ($config['return_url'] ?? $this->readConfig('return_url', 'PAY.RETURN_URL', 'pay.caihong.return_url'));
    }

    /**
     * 构建支付跳转 URL
     *
     * @param array $params 业务参数:
     *   - out_trade_no string 商户订单号
     *   - name         string 商品名称
     *   - money        string 金额(元)
     *   - notify_url   string 可选,覆盖默认异步通知地址
     *   - return_url   string 可选,覆盖默认同步跳转地址
     * @return string 收银台跳转完整 URL(含 sign 与 sign_type)
     * @throws \RuntimeException 缺少必填参数
     */
    public function buildPayUrl(array $params): string
    {
        if (empty($params['out_trade_no']) || empty($params['name']) || !isset($params['money'])) {
            throw new \RuntimeException('缺少必填参数: out_trade_no / name / money');
        }

        // 1. 组装业务参数
        $bizParams = [
            'pid'          => $this->pid,
            'type'         => $params['type'] ?? 'alipay',
            'out_trade_no' => $params['out_trade_no'],
            'notify_url'   => $params['notify_url'] ?? $this->notifyUrl,
            'return_url'   => $params['return_url'] ?? $this->returnUrl,
            'name'         => $params['name'],
            'money'        => $params['money'],
        ];
        // 附加额外参数(如 clientip 等)
        foreach (['clientip', 'param', 'sign'] as $extra) {
            if (isset($params[$extra]) && $params[$extra] !== '') {
                $bizParams[$extra] = $params[$extra];
            }
        }

        // 2. 生成签名
        $sign = SignatureHelper::md5Sign($bizParams, $this->key);

        // 3. 拼接收银台 URL
        $bizParams['sign']      = $sign;
        $bizParams['sign_type'] = 'MD5';

        return $this->api . '/submit.php?' . http_build_query($bizParams);
    }

    /**
     * 验证异步通知
     *
     * 校验维度:
     *   1. pid 必填且与回调一致(不再为空时跳过)
     *   2. 签名校验
     *   3. trade_status 必须为 TRADE_SUCCESS
     *   4. out_trade_no / money 必填且 money > 0
     *   5. 本地订单一致性校验(可选, 由 $order 传入 out_trade_no / money)
     *   6. 防重放: 基于 out_trade_no + Redis 防重, TTL 24 小时
     *
     * @param array      $params 回调参数(含 sign)
     * @param array|null $order  本地订单信息, 可选:
     *   - out_trade_no string 商户订单号, 用于一致性校验
     *   - money        string 订单金额(元), 用于金额一致性校验
     * @return bool true=校验通过
     * @throws \RuntimeException pid 未配置
     */
    public function verifyNotify(array $params, ?array $order = null): bool
    {
        // 1. pid 必填
        if ($this->pid === '') {
            throw new \RuntimeException('CaihongPay pid 未配置');
        }

        // 2. 商户 ID 一致性校验(不再因 pid 为空跳过)
        if ((string) ($params['pid'] ?? '') !== $this->pid) {
            return false;
        }

        // 3. 签名校验
        $sign = (string) ($params['sign'] ?? '');
        if ($sign === '') {
            return false;
        }
        if (!SignatureHelper::verifySign($params, $this->key, $sign)) {
            return false;
        }

        // 4. trade_status 校验(必须为 TRADE_SUCCESS)
        $tradeStatus = (string) ($params['trade_status'] ?? '');
        if ($tradeStatus !== 'TRADE_SUCCESS') {
            return false;
        }

        // 5. out_trade_no 必填
        $outTradeNo = (string) ($params['out_trade_no'] ?? '');
        if ($outTradeNo === '') {
            return false;
        }

        // 6. money 校验: 必填且 > 0(禁止 money=0 / 空字符串)
        $money     = (string) ($params['money'] ?? '');
        $moneyVal  = $money === '' ? 0.0 : (float) $money;
        if ($moneyVal <= 0) {
            return false;
        }

        // 7. 本地订单一致性校验(参数传入时)
        if ($order !== null) {
            $localOutTradeNo = (string) ($order['out_trade_no'] ?? '');
            if ($localOutTradeNo !== '' && $localOutTradeNo !== $outTradeNo) {
                return false;
            }
            $localMoney = (string) ($order['money'] ?? '');
            if ($localMoney !== '' && abs((float) $localMoney - $moneyVal) > 0.000001) {
                return false;
            }
        }

        // 8. 防重放校验: 基于 out_trade_no + Redis 防重, TTL 24 小时
        if (!$this->markNotifyProcessed($outTradeNo)) {
            // 同一订单回调已处理过, 判定为重放
            return false;
        }

        return true;
    }

    /**
     * 验证同步跳转签名
     *
     * 彩虹易支付同步跳转与异步通知使用同一签名规则,
     * 此方法对同步跳转参数进行验签, 防止伪造跳转欺骗用户。
     *
     * @param array $params 同步跳转参数(含 sign)
     * @return bool true=验签通过
     */
    public function verifyReturn(array $params): bool
    {
        return $this->verifySign($params);
    }

    /**
     * 通用签名校验(异步通知 / 同步跳转共用)
     *
     * @param array $params 回调参数(含 sign)
     * @return bool true=验签通过
     */
    protected function verifySign(array $params): bool
    {
        $sign = (string) ($params['sign'] ?? '');
        if ($sign === '') {
            return false;
        }
        // 校验商户 ID 一致性
        if (!empty($this->pid) && (string) ($params['pid'] ?? '') !== $this->pid) {
            return false;
        }
        return SignatureHelper::verifySign($params, $this->key, $sign);
    }

    /**
     * 标记异步通知已处理(防重放)
     *
     * 基于 out_trade_no + Redis SET NX 实现防重, TTL 24 小时。
     * Redis 不可用时降级放行(已由签名 + 订单一致性校验提供保护)。
     *
     * @param string $outTradeNo 商户订单号
     * @return bool true=首次标记成功(可继续处理) false=已处理过(重放)
     */
    protected function markNotifyProcessed(string $outTradeNo): bool
    {
        $redis = $this->redis();
        if ($redis === null) {
            // Redis 不可用: 降级放行(不阻塞业务)
            return true;
        }
        try {
            $key = 'pay:notify:dedup:' . $outTradeNo;
            // SET NX EX: 首次设置返回 true, 已存在返回 false
            $ok = $redis->set($key, '1', ['nx', 'ex' => 86400]);
            return $ok !== false;
        } catch (\Throwable $e) {
            trace('caihongpay_notify_dedup_error: ' . $e->getMessage(), 'error');
            return true;
        }
    }

    /**
     * 获取底层 Redis 实例(不可用时返回 null)
     */
    protected function redis(): ?\Redis
    {
        try {
            if (!function_exists('config')) {
                return null;
            }
            $store = \think\facade\Cache::store('redis');
            if ($store instanceof \think\cache\driver\Redis) {
                $handler = $store->handler();
                if ($handler instanceof \Redis) {
                    return $handler;
                }
            }
            if (method_exists($store, 'handler')) {
                $handler = $store->handler();
                return $handler instanceof \Redis ? $handler : null;
            }
        } catch (\Throwable $e) {
            trace('caihongpay_redis_init_error: ' . $e->getMessage(), 'error');
        }
        return null;
    }

    /**
     * 读取配置(config 优先,env 兜底)
     */
    protected function readConfig(string $name, string $envKey, string $configKey): string
    {
        // config/pay.php 优先
        if (function_exists('config')) {
            $val = config($configKey);
            if ($val !== null && $val !== '') {
                return (string) $val;
            }
        }
        // env 兜底
        if (function_exists('env')) {
            $val = env($envKey);
            if ($val !== null && $val !== '') {
                return (string) $val;
            }
        }
        return '';
    }
}
