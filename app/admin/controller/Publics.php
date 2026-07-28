<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;
use app\common\model\AdminUser;
use app\common\service\AdminLogService;
use app\common\service\RateLimiter;
use think\facade\Session;

/**
 * 后台登录
 * 不受 AdminAuth 中间件保护(放行路由)
 */
class Publics extends BaseAdminController
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
     * 用于时序攻击防护的 dummy bcrypt hash(任意有效 hash,仅用于消耗时间)
     */
    protected const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

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
        $ip       = $this->request->ip();

        if ($username === '' || $password === '') {
            return $this->error('用户名或密码不能为空');
        }

        // 登录失败次数检查(IP 维度,5 次锁定 15 分钟)
        $limiter = app(RateLimiter::class);
        $lockKey = 'admin_login_fail:' . $ip;
        $lock    = $limiter->checkLoginLock($lockKey, self::LOGIN_FAIL_THRESHOLD);
        if ($lock['locked']) {
            $this->recordLoginLog(null, $username, 'login_locked', $ip);
            return $this->error('登录失败次数过多,请 15 分钟后再试');
        }

        // 根据 username 查询正常状态管理员
        $admin = AdminUser::where('username', $username)->normal()->find();

        // 统一时序与提示:账号不存在也执行一次 dummy password_verify,
        // 避免通过响应时间差异枚举账号;统一返回"用户名或密码错误"
        if (!$admin) {
            password_verify($password, self::DUMMY_HASH);
            $this->recordLoginFail($lockKey, $username, $ip);
            return $this->error('用户名或密码错误');
        }

        // password_hash verify
        if (!password_verify($password, (string) $admin->password)) {
            $this->recordLoginFail($lockKey, $username, $ip, (int) $admin->id);
            return $this->error('用户名或密码错误');
        }

        // 登录成功:清除失败计数
        $limiter->clearLoginFail($lockKey);

        // 更新登录信息
        $admin->last_login_ip = $ip;
        $admin->last_login_at = date('Y-m-d H:i:s');
        $admin->save();

        // 防会话固定攻击:登录成功后立即重新生成 Session ID(销毁旧会话)
        Session::regenerate(true);

        // 写入 Session
        Session::set('admin_id', (int) $admin->id);
        Session::set('admin_username', (string) $admin->username);
        Session::set('admin_nickname', (string) ($admin->nickname ?: $admin->username));
        // 记录 is_super 供中间件扩展点使用(字段不存在时默认 0)
        Session::set('admin_is_super', (int) ($admin->is_super ?? 0));

        // 登录成功审计日志
        $this->recordLoginLog((int) $admin->id, $username, 'login_success', $ip);

        return $this->success(['url' => '/admin'], '登录成功');
    }

    /**
     * 退出登录(仅允许 POST,防 CSRF 登出)
     * GET 请求直接跳转登录页,不执行登出动作
     */
    public function logout()
    {
        if (!$this->request->isPost()) {
            return $this->redirect('/admin/login');
        }

        $adminId   = $this->adminId();
        $username  = (string) Session::get('admin_username', '');
        $this->recordLoginLog($adminId, $username, 'logout', $this->request->ip());

        Session::delete('admin_id');
        Session::delete('admin_username');
        Session::delete('admin_nickname');
        Session::delete('admin_is_super');

        return $this->redirect('/admin/login');
    }

    /**
     * 记录一次登录失败:递增失败计数 + 写审计日志
     */
    protected function recordLoginFail(string $lockKey, string $username, string $ip, ?int $adminId = null): void
    {
        try {
            app(RateLimiter::class)->recordLoginFail($lockKey, self::LOGIN_FAIL_LOCK_TTL);
        } catch (\Throwable $e) {
            trace('admin_login_fail_count_error: ' . $e->getMessage(), 'error');
        }
        $this->recordLoginLog($adminId, $username, 'login_fail', $ip);
    }

    /**
     * 记录登录相关审计日志(失败降级,不阻断主流程)
     */
    protected function recordLoginLog(?int $adminId, string $username, string $action, string $ip): void
    {
        try {
            app(AdminLogService::class)->record(
                $adminId ?? 0,
                'admin',
                $action,
                null,
                ['username' => $username],
                $ip,
                $this->request->header('user-agent', '')
            );
        } catch (\Throwable $e) {
            trace('admin_login_log_error: ' . $e->getMessage(), 'error');
        }
    }
}
