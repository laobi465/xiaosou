<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use app\common\enum\CreditType;
use app\common\model\User;
use app\common\model\UserLoginLog;
use app\common\service\CreditService;
use app\common\service\VerifyCodeService;
use app\common\validate\AuthValidate;
use think\facade\Session;

/**
 * 注册登录
 */
class Auth extends BaseController
{
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
     * POST: email, code → 验证码校验(type=2) → 查/建用户 → 写 Session → JSON
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

        // 验证码校验(type=2 登录)
        $verifyService = app(VerifyCodeService::class);
        if (!$verifyService->verify($data['email'], $data['code'], 2)) {
            return $this->error('验证码错误或已过期', 2001);
        }

        // 查/建用户
        $user = User::where('email', $data['email'])->find();
        if (!$user) {
            $user = User::create([
                'email'    => $data['email'],
                'nickname' => explode('@', $data['email'])[0],
                'status'   => 1,
            ]);
        } elseif ((int) $user->status !== 1) {
            return $this->error('账号已被封禁');
        }

        // 写 Session
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

        $redirect = (string) $this->request->post('redirect', '/');
        return $this->success(['url' => $redirect], '登录成功');
    }

    /**
     * 注册(Ajax)
     * POST: email, code → 验证码校验(type=1) → 创建用户 + 赠送注册积分 → 写 Session → JSON
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

        // 创建用户
        $user = User::create([
            'email'    => $data['email'],
            'nickname' => explode('@', $data['email'])[0],
            'status'   => 1,
        ]);

        // 赠送注册积分(失败降级)
        $giftAmount = (int) config('pan.credit.register_gift');
        if ($giftAmount > 0) {
            try {
                app(CreditService::class)->recharge(
                    (int) $user->id,
                    $giftAmount,
                    CreditType::REGISTER_GIFT,
                    null,
                    '注册赠送'
                );
            } catch (\Throwable $e) {
                trace('register_gift_error: ' . $e->getMessage(), 'error');
            }
        }

        // 写 Session
        Session::set('user_id', (int) $user->id);
        Session::set('email', (string) $user->email);
        Session::set('nickname', (string) $user->nickname);

        return $this->success(['url' => '/'], '注册成功');
    }

    /**
     * 退出登录
     */
    public function logout()
    {
        Session::delete('user_id');
        Session::delete('email');
        Session::delete('nickname');
        return $this->redirect('/');
    }
}
