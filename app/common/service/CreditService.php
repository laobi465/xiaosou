<?php
declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;
use think\facade\Cache;
use app\common\model\UserCredit;
use app\common\model\CreditLog;
use app\common\model\SignInRecord;
use app\common\enum\CreditType;
use app\common\exception\CreditNotEnoughException;
use app\common\exception\BusinessException;

/**
 * 积分服务
 *
 * 参见架构设计文档 3.2 节。
 *
 * 关键设计:
 *   - 事务 + 行锁(SELECT ... FOR UPDATE)+ 乐观锁(version 字段)双重保险防超扣
 *   - 流水表记录 balance_after 便于追溯
 *   - 流水类型枚举见 app\common\enum\CreditType
 */
class CreditService
{
    /**
     * 扣减积分(防超扣)
     *
     * @param int         $userId    用户ID
     * @param int         $amount    扣减数量(正整数)
     * @param int         $type      流水类型 @see CreditType
     * @param int|null    $relatedId 关联业务ID(订单/资源等)
     * @param string      $remark    备注
     * @param int|null    $adminId   管理员ID
     * @return bool
     *
     * @throws CreditNotEnoughException 积分不足
     * @throws \Exception 并发冲突
     */
    public function consume(int $userId, int $amount, int $type, ?int $relatedId = null, string $remark = '', ?int $adminId = null): bool
    {
        Db::transaction(function () use ($userId, $amount, $type, $relatedId, $remark, $adminId) {
            // 行锁查询
            $credit = UserCredit::where('user_id', $userId)->lock(true)->find();
            if (!$credit) {
                // 首次使用,创建积分记录(balance=0)
                $credit = $this->ensureCreditRecord($userId);
            }

            $oldVersion = (int) $credit->version;
            $balance    = (int) $credit->balance;

            // 余额校验
            if ($balance < $amount) {
                throw new CreditNotEnoughException();
            }

            $newBalance      = $balance - $amount;
            $newTotalConsume = (int) $credit->total_consume + $amount;

            // 乐观锁更新(where user_id + version 双条件)
            $affected = UserCredit::where('user_id', $userId)
                ->where('version', $oldVersion)
                ->update([
                    'balance'       => $newBalance,
                    'total_consume' => $newTotalConsume,
                    'version'       => $oldVersion + 1,
                ]);

            if ($affected === 0) {
                throw new \Exception('并发冲突,请重试');
            }

            // 写流水(amount 负数)
            CreditLog::create([
                'user_id'       => $userId,
                'type'          => $type,
                'amount'        => -$amount,
                'balance_after' => $newBalance,
                'related_id'    => $relatedId ?? 0,
                'admin_id'      => $adminId ?? 0,
                'remark'        => $remark,
            ]);
        });

        // 清余额缓存
        $this->clearBalanceCache($userId);

        return true;
    }

    /**
     * 增加积分
     *
     * @param int         $userId    用户ID
     * @param int         $amount    增加数量(正整数)
     * @param int         $type      流水类型 @see CreditType
     * @param int|null    $relatedId 关联业务ID
     * @param string      $remark    备注
     * @param int|null    $adminId   管理员ID
     * @return void
     *
     * @throws \Exception 并发冲突
     */
    public function recharge(int $userId, int $amount, int $type, ?int $relatedId = null, string $remark = '', ?int $adminId = null): void
    {
        Db::transaction(function () use ($userId, $amount, $type, $relatedId, $remark, $adminId) {
            // 行锁查询
            $credit = UserCredit::where('user_id', $userId)->lock(true)->find();
            if (!$credit) {
                // 首次使用,创建积分记录
                $credit = $this->ensureCreditRecord($userId);
            }

            $oldVersion = (int) $credit->version;
            $newBalance = (int) $credit->balance + $amount;

            $update = [
                'balance' => $newBalance,
                'version' => $oldVersion + 1,
            ];

            // 充值/退款累加 total_recharge; 奖励类累加 total_reward
            if (in_array($type, [CreditType::RECHARGE, CreditType::REFUND], true)) {
                $update['total_recharge'] = (int) $credit->total_recharge + $amount;
            } elseif (in_array($type, [CreditType::SIGN_IN, CreditType::REGISTER_GIFT, CreditType::SUBMIT_REWARD, CreditType::ADMIN_ADJUST], true)) {
                $update['total_reward'] = (int) $credit->total_reward + $amount;
            }

            // 乐观锁更新
            $affected = UserCredit::where('user_id', $userId)
                ->where('version', $oldVersion)
                ->update($update);

            if ($affected === 0) {
                throw new \Exception('并发冲突,请重试');
            }

            // 写流水(amount 正数)
            CreditLog::create([
                'user_id'       => $userId,
                'type'          => $type,
                'amount'        => $amount,
                'balance_after' => $newBalance,
                'related_id'    => $relatedId ?? 0,
                'admin_id'      => $adminId ?? 0,
                'remark'        => $remark,
            ]);
        });

        // 清余额缓存
        $this->clearBalanceCache($userId);
    }

    /**
     * 查询余额(优先读缓存,TTL 300 秒)
     *
     * @param int $userId 用户ID
     * @return int
     */
    public function getBalance(int $userId): int
    {
        $cacheKey = 'credit:balance:' . $userId;

        // 读缓存(异常降级查库)
        try {
            $cached = Cache::get($cacheKey);
            if ($cached !== null && $cached !== false) {
                return (int) $cached;
            }
        } catch (\Throwable $e) {
            // 缓存读取异常,降级查库
        }

        $credit  = UserCredit::where('user_id', $userId)->find();
        $balance = $credit ? (int) $credit->balance : 0;

        // 回填缓存(异常忽略)
        try {
            Cache::set($cacheKey, $balance, 300);
        } catch (\Throwable $e) {
            // 缓存写入异常,忽略
        }

        return $balance;
    }

    /**
     * 每日签到
     *
     * @param int $userId 用户ID
     * @return array {credit: 本次签到积分, continuous_days: 连续天数}
     *
     * @throws BusinessException 今日已签到
     */
    public function signIn(int $userId): array
    {
        $today     = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        // 今日是否已签到
        $exists = SignInRecord::where('user_id', $userId)
            ->where('sign_date', $today)
            ->find();
        if ($exists) {
            throw new BusinessException('今日已签到');
        }

        // 计算连续天数: 昨日有记录则 +1, 否则 =1
        $yesterdayRecord = SignInRecord::where('user_id', $userId)
            ->where('sign_date', $yesterday)
            ->find();
        $continuousDays = $yesterdayRecord ? (int) $yesterdayRecord->continuous_days + 1 : 1;

        // 积分计算: 基础 + 连续7天额外
        $amount = (int) config('pan.credit.sign_in');
        if ($continuousDays % 7 === 0) {
            $amount += (int) config('pan.credit.sign_in_continuous');
        }

        Db::transaction(function () use ($userId, $amount, $today, $continuousDays) {
            // 签到积分到账
            $this->recharge($userId, $amount, CreditType::SIGN_IN, null, '每日签到');

            // 写签到记录(uk_user_date 唯一索引防重复)
            SignInRecord::create([
                'user_id'         => $userId,
                'sign_date'       => $today,
                'continuous_days' => $continuousDays,
                'credit_amount'   => $amount,
            ]);
        });

        // 清余额缓存(recharge 内部已清,此处确保)
        $this->clearBalanceCache($userId);

        return [
            'credit'          => $amount,
            'continuous_days' => $continuousDays,
        ];
    }

    /**
     * 确保用户积分记录存在(不存在则创建)
     *
     * @param int $userId 用户ID
     * @return UserCredit
     */
    private function ensureCreditRecord(int $userId): UserCredit
    {
        $credit = UserCredit::where('user_id', $userId)->find();
        if (!$credit) {
            $credit = UserCredit::create([
                'user_id'        => $userId,
                'balance'        => 0,
                'total_recharge' => 0,
                'total_consume'  => 0,
                'total_reward'   => 0,
                'version'        => 0,
            ]);
        }
        return $credit;
    }

    /**
     * 清除余额缓存(异常忽略)
     *
     * @param int $userId 用户ID
     * @return void
     */
    private function clearBalanceCache(int $userId): void
    {
        try {
            Cache::delete('credit:balance:' . $userId);
        } catch (\Throwable $e) {
            // 缓存操作异常,忽略
        }
    }
}
