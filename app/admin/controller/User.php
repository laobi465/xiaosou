<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;
use app\common\enum\CreditType;
use app\common\exception\CreditNotEnoughException;
use app\common\model\CreditLog;
use app\common\model\Order as OrderModel;
use app\common\model\Submission;
use app\common\model\User as UserModel;
use app\common\model\UserLoginLog;
use app\common\service\CreditService;
use app\common\validate\UserValidate;

/**
 * 用户管理
 */
class User extends BaseAdminController
{
    /**
     * 用户列表(搜索 email/nickname / 筛选 status)
     */
    public function index()
    {
        $keyword = (string) $this->request->get('keyword', '');
        $status  = $this->request->get('status', '');

        $list = UserModel::when($keyword !== '', function ($query) use ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('email', '%' . $keyword . '%')
                  ->whereOr('nickname', 'like', '%' . $keyword . '%');
            });
        })->when($status !== '' && $status !== null, function ($query) use ($status) {
            $query->where('status', (int) $status);
        })->order('id', 'desc')
            ->paginate(15, false, ['query' => $this->request->param()]);

        return view('user/index', [
            'list'    => $list,
            'keyword' => $keyword,
            'status'  => $status,
        ]);
    }

    /**
     * 用户详情(资料/积分/订单/提交/登录日志)
     */
    public function detail(int $id)
    {
        $user = UserModel::with('credit')->find($id);
        if (!$user) {
            return $this->error('用户不存在');
        }

        $orders      = OrderModel::where('user_id', $id)->order('id', 'desc')->limit(10)->select();
        $submissions = Submission::where('user_id', $id)->order('id', 'desc')->limit(10)->select();
        $loginLogs   = UserLoginLog::where('user_id', $id)->order('id', 'desc')->limit(10)->select();
        $creditLogs  = CreditLog::where('user_id', $id)->order('id', 'desc')->limit(10)->select();

        return view('user/detail', [
            'vo'          => $user,
            'orders'      => $orders,
            'submissions' => $submissions,
            'loginLogs'   => $loginLogs,
            'creditLogs'  => $creditLogs,
        ]);
    }

    /**
     * 调整积分(type=1增加/2扣减, CreditService::recharge/consume ADMIN_ADJUST)
     */
    public function adjustCredit(int $id)
    {
        $user = UserModel::find($id);
        if (!$user) {
            return $this->error('用户不存在');
        }

        $data = $this->request->only(['amount', 'type', 'remark']);
        $data['user_id'] = $id;

        $validate = new UserValidate();
        if (!$validate->scene('adjustCredit')->check($data)) {
            return $this->error($validate->getError());
        }

        $amount = (int) $data['amount'];
        $type   = (int) $data['type'];
        $remark = (string) ($data['remark'] ?? '');
        $adminId = $this->adminId();

        try {
            $creditService = app(CreditService::class);
            if ($type === 1) {
                $creditService->recharge(
                    $id, $amount, CreditType::ADMIN_ADJUST, null,
                    $remark !== '' ? $remark : '管理员增加积分', $adminId
                );
            } else {
                $creditService->consume(
                    $id, $amount, CreditType::ADMIN_ADJUST, null,
                    $remark !== '' ? $remark : '管理员扣减积分', $adminId
                );
            }
        } catch (CreditNotEnoughException $e) {
            return $this->error('用户积分不足');
        } catch (\Throwable $e) {
            return $this->error('积分调整失败: ' . $e->getMessage());
        }

        $this->logAction('user', 'adjust_credit', $id, [
            'type'   => $type,
            'amount' => $amount,
            'remark' => $remark,
        ]);
        return $this->success([], '积分调整成功');
    }

    /**
     * 封禁/解封(status 切换)
     */
    public function toggle(int $id)
    {
        $user = UserModel::find($id);
        if (!$user) {
            return $this->error('用户不存在');
        }

        $user->status = (int) $user->status === 1 ? 0 : 1;
        try {
            $user->save();
        } catch (\Throwable $e) {
            return $this->error('操作失败: ' . $e->getMessage());
        }

        $this->logAction('user', 'toggle', $id, ['status' => $user->status]);
        return $this->success(['status' => $user->status], '操作成功');
    }
}
