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
use think\facade\Cache;

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
            ->paginate(config('pan.page_size.admin', 15), false, ['query' => $this->request->param()]);

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
     *
     * 安全: 显式校验 type ∈ {1,2},非法值兜底报错,避免走入非预期分支。
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

        // 显式校验 type,仅允许 1(增加)/2(扣减),其余兜底报错
        if ($type !== 1 && $type !== 2) {
            return $this->error('调整类型非法,仅支持 1=增加 2=扣减');
        }

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
            return $this->errorWithLog('积分调整失败,请稍后重试', $e, 'user_adjust_credit_error');
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
     *
     * 安全:
     *   - 原子更新 where('status', 当前值)->update,避免并发重复切换
     *   - 封禁时设置 Redis ban 版本标记(TTL 与会话一致),
     *     UserAuth 中间件读取该标记后强制下线,实现"封禁即失效当前登录态"
     */
    public function toggle(int $id)
    {
        $user = UserModel::find($id);
        if (!$user) {
            return $this->error('用户不存在');
        }

        $currentStatus = (int) $user->status;
        $newStatus     = $currentStatus === 1 ? 0 : 1;

        try {
            // 原子更新: 仅当 status 仍为预期当前值时才切换
            $affected = UserModel::where('id', $id)
                ->where('status', $currentStatus)
                ->update(['status' => $newStatus]);

            if ($affected !== 1) {
                // 并发场景下状态已被其他请求变更
                return $this->error('操作失败,用户状态已变更,请刷新后重试');
            }
        } catch (\Throwable $e) {
            return $this->errorWithLog('操作失败,请稍后重试', $e, 'user_toggle_error');
        }

        // 封禁时设置 ban 版本标记; 解封时清除标记
        // TTL 7200 秒(与会话过期一致),UserAuth 中间件据此强制下线
        try {
            if ($newStatus === 0) {
                Cache::set('user_ban:' . $id, time(), 7200);
            } else {
                Cache::delete('user_ban:' . $id);
            }
        } catch (\Throwable $e) {
            // 缓存操作失败不阻塞主流程,记录日志
            trace('user_ban_cache_error: user_id=' . $id . ' ' . $e->getMessage(), 'error');
        }

        $this->logAction('user', 'toggle', $id, ['status' => $newStatus]);
        return $this->success(['status' => $newStatus], '操作成功');
    }
}
