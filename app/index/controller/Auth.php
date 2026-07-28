<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;

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
     * TODO: 调用 VerifyCodeService 生成并通过 MailService 发送
     */
    public function sendCode()
    {
        return $this->success([], 'success');
    }

    /**
     * 登录(Ajax)
     * TODO: 校验验证码, 查询用户, 写入 Session user_id
     */
    public function doLogin()
    {
        return $this->success([], 'success');
    }

    /**
     * 注册(Ajax)
     * TODO: 校验参数, 创建用户, 赠送注册积分, 写入 Session
     */
    public function doRegister()
    {
        return $this->success([], 'success');
    }

    /**
     * 退出登录
     */
    public function logout()
    {
        \think\facade\Session::delete('user_id');
        return $this->redirect('/auth/login');
    }
}
