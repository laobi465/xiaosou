<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\model\CreditLog;
use app\common\model\Order;
use app\common\model\User as UserModel;
use app\common\service\CreditService;
use app\common\validate\UserValidate;
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
            ->paginate(config('pan.page_size.index', 15));

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
            ->paginate(config('pan.page_size.index', 15));

        return view('user/orders', [
            'orders' => $orders,
        ]);
    }

    /**
     * 每日签到(Ajax)
     *
     * 幂等由 CreditService::signIn 内部保障(uk_user_date 唯一索引 + 今日已签到检查),
     * 重复提交返回"今日已签到"提示。
     */
    public function signIn()
    {
        $userId = $this->userId();
        try {
            $result = app(CreditService::class)->signIn((int) $userId);
        } catch (BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errorWithLog('签到失败,请稍后重试', $e, 'user_sign_in_error');
        }

        return $this->success($result, '签到成功');
    }

    /**
     * 资料编辑(Ajax)
     * POST: nickname, avatar
     *
     * - nickname/avatar 先 trim 再判定是否为空
     * - 使用 UserValidate profile 场景校验: nickname max:20, avatar 协议白名单(http/https)
     * - CSRF 由全局 CheckCsrf 中间件保障
     */
    public function profile()
    {
        $userId   = $this->userId();
        $nickname = trim((string) $this->request->post('nickname', ''));
        $avatar   = trim((string) $this->request->post('avatar', ''));

        // 仅收集非空字段(空字符串表示不更新)
        $data = [];
        if ($nickname !== '') {
            $data['nickname'] = $nickname;
        }
        if ($avatar !== '') {
            $data['avatar'] = $avatar;
        }

        if (empty($data)) {
            return $this->error('无可更新内容');
        }

        // 校验(profile 场景: nickname max:20, avatar 协议白名单)
        $validate = new UserValidate();
        if (!$validate->scene('profile')->check($data)) {
            return $this->error($validate->getError());
        }

        try {
            UserModel::where('id', $userId)->update($data);
        } catch (\Throwable $e) {
            return $this->errorWithLog('资料更新失败', $e, 'user_profile_update_error');
        }

        if (isset($data['nickname'])) {
            Session::set('nickname', $data['nickname']);
        }

        return $this->success([], '资料更新成功');
    }
}
