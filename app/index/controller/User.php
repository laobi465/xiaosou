<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\model\CreditLog;
use app\common\model\Order;
use app\common\model\User as UserModel;
use app\common\service\CreditService;
use think\facade\Session;

/**
 * 用户中心
 * 登录校验由路由中间件 UserAuth 处理, 控制器内不再判断
 */
class User extends BaseController
{
    /**
     * 个人中心: 个人资料 + 积分余额
     */
    public function index()
    {
        $userId = $this->userId();
        $user   = UserModel::find($userId);
        if (!$user) {
            $this->fail('用户不存在');
        }

        $balance = 0;
        try {
            $balance = app(CreditService::class)->getBalance((int) $userId);
        } catch (\Throwable $e) {
            trace('user_balance_error: ' . $e->getMessage(), 'error');
        }

        return view('user/index', [
            'user'    => $user,
            'balance' => $balance,
        ]);
    }

    /**
     * 积分流水页
     */
    public function credits()
    {
        $userId = $this->userId();

        $logs = CreditLog::where('user_id', $userId)
            ->order('create_time', 'desc')
            ->paginate(config('pan.page_size', 15));

        $balance = 0;
        try {
            $balance = app(CreditService::class)->getBalance((int) $userId);
        } catch (\Throwable $e) {
            trace('user_credits_balance_error: ' . $e->getMessage(), 'error');
        }

        return view('user/credits', [
            'logs'    => $logs,
            'balance' => $balance,
        ]);
    }

    /**
     * 订单列表页
     */
    public function orders()
    {
        $userId = $this->userId();

        $orders = Order::where('user_id', $userId)
            ->order('create_time', 'desc')
            ->paginate(config('pan.page_size', 15));

        return view('user/orders', [
            'orders' => $orders,
        ]);
    }

    /**
     * 每日签到(Ajax)
     */
    public function signIn()
    {
        $userId = $this->userId();
        try {
            $result = app(CreditService::class)->signIn((int) $userId);
        } catch (BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            trace('user_sign_in_error: ' . $e->getMessage(), 'error');
            return $this->error('签到失败,请稍后重试');
        }

        return $this->success($result, '签到成功');
    }

    /**
     * 资料编辑(Ajax)
     * POST: nickname, avatar
     */
    public function profile()
    {
        $userId   = $this->userId();
        $nickname = (string) $this->request->post('nickname', '');
        $avatar   = (string) $this->request->post('avatar', '');

        $update = [];
        if ($nickname !== '') {
            $update['nickname'] = $nickname;
        }
        if ($avatar !== '') {
            $update['avatar'] = $avatar;
        }

        if (empty($update)) {
            return $this->error('无可更新内容');
        }

        try {
            UserModel::where('id', $userId)->update($update);
        } catch (\Throwable $e) {
            trace('user_profile_update_error: ' . $e->getMessage(), 'error');
            return $this->error('资料更新失败');
        }

        if (isset($update['nickname'])) {
            Session::set('nickname', $update['nickname']);
        }

        return $this->success([], '资料更新成功');
    }
}
