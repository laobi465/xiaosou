<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;
use app\common\model\AdminUser;
use think\facade\Session;

/**
 * 后台登录
 * 不受 AdminAuth 中间件保护(放行路由)
 */
class Publics extends BaseAdminController
{
    /**
     * 登录
     * GET  渲染登录页
     * POST 处理登录表单
     */
    public function login()
    {
        if ($this->request->isPost()) {
            return $this->doLogin();
        }
        // 登录页不使用后台布局
        config(['layout_on' => false], 'view');
        return view('publics/login');
    }

    /**
     * 处理登录
     */
    protected function doLogin()
    {
        $username = (string) $this->request->post('username', '');
        $password = (string) $this->request->post('password', '');

        if ($username === '' || $password === '') {
            return $this->error('用户名或密码不能为空');
        }

        // 根据 username 查询正常状态管理员
        $admin = AdminUser::where('username', $username)->normal()->find();
        if (!$admin) {
            return $this->error('账号不存在或已被禁用');
        }

        // password_hash verify
        if (!password_verify($password, (string) $admin->password)) {
            return $this->error('用户名或密码错误');
        }

        // 更新登录信息
        $admin->last_login_ip = $this->request->ip();
        $admin->last_login_at = date('Y-m-d H:i:s');
        $admin->save();

        // 写入 Session
        Session::set('admin_id', (int) $admin->id);
        Session::set('admin_username', (string) $admin->username);
        Session::set('admin_nickname', (string) ($admin->nickname ?: $admin->username));

        return $this->success(['url' => '/admin'], '登录成功');
    }

    /**
     * 退出登录
     */
    public function logout()
    {
        Session::delete('admin_id');
        Session::delete('admin_username');
        Session::delete('admin_nickname');
        return $this->redirect('/admin/login');
    }
}
