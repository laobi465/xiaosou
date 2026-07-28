<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use app\common\enum\CreditType;
use app\common\model\User;
use app\common\model\UserLoginLog;
use app\common\service\CreditService;
use app\common\service\RateLimiter;
use app\common\service\VerifyCodeService;
use app\common\validate\AuthValidate;
use think\facade\Db;
use think\facade\Session;

/**
 * 注册登录
 */
class Auth extends BaseController
{
    /**
     * 登录失败锁定阈值(次)
     */
    protected const LOGIN_FAIL_THRESHOLD = 5;

    /**
     * 登录失败锁定时长(秒) 15 分钟
     */
    protected const LOGIN_FAIL_LOCK_TTL = 900;

    /**
     * 登录页
     */
    public function login()
    {
        return view('auth/login');
    }

    /**
     * 注册页
     */
    public function register()
    {
        return view('auth/register');
    }

    /**
     * 发送验证码(Ajax)
     * POST: email, type(1注册 2登录 3重置)
     */
    public function sendCode()
    {
        $data = [
            'email' => (string) $this->request->post('email', ''),
            'type'  => (string) $this->request->post('type', ''),
        ];

        $validate = new AuthValidate();
        if (!$validate->scene('sendCode')->check($data)) {
            return $this->error($validate->getError());
        }

        $result = app(VerifyCodeService::class)->send(
            $data['email'],
            (int) $data['type']
        );

        if (!empty($result['sent']) && $result['sent'] === true) {
            return $this->success([], '验证码已发送');
        }

        $reason = (string) ($result['reason'] ?? '发送失败');
        return $this->error($reason);
    }

    /**
     * 登录(Ajax)
     * POST: email, code → 验证码校验(type=2) → 查用户 → 写 Session → JSON
     *
     * 注意: 不再自动注册, 用户不存在则提示先注册
     */
    public function doLogin()
    {
        $data = [
            'email' => (string) $this->request->post('email', ''),
            'code'  => (string) $this->request->post('code', ''),
        ];

        $validate = new AuthValidate();
        if (!$validate->scene('doLogin')->check($data)) {
            return $this->error($validate->getError());
        }

        // 登录失败次数检查(IP 维度, 5 次锁定 15 分钟)
        $limiter = app(RateLimiter::class);
        $lockKey = 'user_login_fail:' . $this->request->ip();
        $lock    = $limiter->checkLoginLock($lockKey, self::LOGIN_FAIL_THRESHOLD);
        if ($lock['locked']) {
            return $this->error('登录失败次数过多,请 15 分钟后再试', 2002);
        }

        // 验证码校验(type=2 登录)
        $verifyService = app(VerifyCodeService::class);
        if (!$verifyService->verify($data['email'], $data['code'], 2)) {
            $this->recordLoginFail($lockKey);
            return $this->error('验证码错误或已过期', 2001);
        }

        // 查用户(不存在则提示先注册, 不再自动创建)
        $user = User::where('email', $data['email'])->find();
        if (!$user) {
            $this->recordLoginFail($lockKey);
            return $this->error('账号不存在,请先注册');
        }
        if ((int) $user->status !== 1) {
            $this->recordLoginFail($lockKey);
            return $this->error('账号已被封禁');
        }

        // 登录成功:清除失败计数
        $limiter->clearLoginFail($lockKey);

        // 写 Session(重新生成 sessionId 防固定会话攻击)
        Session::regenerate(true);
        Session::set('user_id', (int) $user->id);
        Session::set('email', (string) $user->email);
        Session::set('nickname', (string) $user->nickname);

        // 写登录日志(失败降级)
        try {
            UserLoginLog::create([
                'user_id'   => (int) $user->id,
                'login_type'=> 1,
                'result'    => 1,
                'ip'        => $this->request->ip(),
            ]);
        } catch (\Throwable $e) {
            trace('user_login_log_error: ' . $e->getMessage(), 'error');
        }

        // redirect 参数校验(防开放重定向): 必须以 / 开头且不以 // 开头, 否则忽略
        $redirect = self::sanitizeRedirect((string) $this->request->post('redirect', '/'));
        return $this->success(['url' => $redirect], '登录成功');
    }

    /**
     * 注册(Ajax)
     * POST: email, code → 验证码校验(type=1) → 创建用户 + 赠送注册积分 → 写 Session → JSON
     *
     * 创建用户 + 注册赠送积分在同一事务内, 任一失败回滚
     */
    public function doRegister()
    {
        $data = [
            'email' => (string) $this->request->post('email', ''),
            'code'  => (string) $this->request->post('code', ''),
        ];

        $validate = new AuthValidate();
        if (!$validate->scene('doRegister')->check($data)) {
            return $this->error($validate->getError());
        }

        // 邮箱是否已注册
        $exists = User::where('email', $data['email'])->find();
        if ($exists) {
            return $this->error('该邮箱已注册');
        }

        // 验证码校验(type=1 注册)
        $verifyService = app(VerifyCodeService::class);
        if (!$verifyService->verify($data['email'], $data['code'], 1)) {
            return $this->error('验证码错误或已过期', 2001);
        }

        // 赠送注册积分(配置驱动)
        $giftAmount = (int) config('pan.credit.register_gift');

        // 事务包裹: 创建用户 + 赠送积分(任一失败回滚)
        try {
            $user = Db::transaction(function () use ($data, $giftAmount) {
                $user = User::create([
                    'email'    => $data['email'],
                    'nickname' => explode('@', $data['email'])[0],
                    'status'   => 1,
                ]);

                if ($giftAmount > 0) {
                    app(CreditService::class)->recharge(
                        (int) $user->id,
                        $giftAmount,
                        CreditType::REGISTER_GIFT,
                        null,
                        '注册赠送'
                    );
                }

                return $user;
            });
        } catch (\Throwable $e) {
            return $this->errorWithLog('注册失败,请稍后重试', $e, 'register_error');
        }

        // 写 Session(重新生成 sessionId 防固定会话攻击)
        Session::regenerate(true);
        Session::set('user_id', (int) $user->id);
        Session::set('email', (string) $user->email);
        Session::set('nickname', (string) $user->nickname);

        return $this->success(['url' => '/'], '注册成功');
    }

    /**
     * 退出登录(POST)
     * 路由层已限定 POST 方法, 防止 GET 链接触发登出
     */
    public function logout()
    {
        Session::delete('user_id');
        Session::delete('email');
        Session::delete('nickname');
        return $this->redirect('/');
    }

    /**
     * 记录一次登录失败:递增 IP 维度失败计数(Redis 不可用时降级忽略)
     */
    protected function recordLoginFail(string $lockKey): void
    {
        try {
            app(RateLimiter::class)->recordLoginFail($lockKey, self::LOGIN_FAIL_LOCK_TTL);
        } catch (\Throwable $e) {
            trace('user_login_fail_count_error: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * 校验并清洗 redirect 参数, 防止开放重定向
     *
     * 规则: 必须以 / 开头且不以 // 开头, 否则返回默认 /
     *
     * @param string $url 待校验的跳转地址
     * @return string 安全的站内跳转地址
     */
    public static function sanitizeRedirect(string $url): string
    {
        if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')) {
            return '/';
        }
        return $url;
    }
}
