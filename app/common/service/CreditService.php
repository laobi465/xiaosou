<?php
declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;
use app\common\model\UserCredit;
use app\common\model\CreditLog;
use app\common\enum\CreditType;
use app\common\exception\CreditNotEnoughException;

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
     * @return bool
     *
     * @throws CreditNotEnoughException 积分不足
     */
    public function consume(int $userId, int $amount, int $type, ?int $relatedId = null, string $remark = ''): bool
    {
        // TODO: Db::transaction 内:
        //   1. UserCredit::where('user_id',$userId)->lock(true)->find() 行锁
        //   2. 余额不足抛 CreditNotEnoughException
        //   3. 乐观锁: where user_id + version 双条件 update
        //   4. 写 CreditLog 流水(balance_after)
        //   5. 失败抛 \Exception('并发冲突,请重试')
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
     * @return void
     */
    public function recharge(int $userId, int $amount, int $type, ?int $relatedId = null, string $remark = ''): void
    {
        // TODO: 事务内更新 UserCredit.balance + total_consume/recharge,写 CreditLog 流水
    }
}
