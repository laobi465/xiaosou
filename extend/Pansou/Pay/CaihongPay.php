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
     * 验证异步通知签名
     *
     * @param array $params 回调参数(含 sign)
     * @return bool true=验签通过
     */
    public function verifyNotify(array $params): bool
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
