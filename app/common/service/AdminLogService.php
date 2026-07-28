<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\model\AdminLog;

/**
 * 管理员操作日志服务
 *
 * 写入 admin_logs 表,记录后台管理操作的审计轨迹。
 * 字段: admin_id / module / action / target_id / detail(JSON) / ip / user_agent / create_time
 */
class AdminLogService
{
    /**
     * 记录一条管理员操作日志
     *
     * @param int         $adminId  管理员ID
     * @param string      $module   模块: resource/user/order/ad/config
     * @param string      $action   动作: create/update/delete
     * @param int|null    $targetId 操作目标ID
     * @param array       $detail   变更前后明细(序列化为 JSON)
     * @param string      $ip       请求IP
     * @param string      $ua       User-Agent
     */
    public function record(
        int $adminId,
        string $module,
        string $action,
        ?int $targetId,
        array $detail,
        string $ip,
        string $ua
    ): void {
        try {
            AdminLog::create([
                'admin_id'   => $adminId,
                'module'     => $module,
                'action'     => $action,
                'target_id'  => $targetId,
                'detail'     => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
                'ip'         => $ip,
                'user_agent' => $ua,
            ]);
        } catch (\Throwable $e) {
            // 审计日志写入失败不应阻断主业务,仅记录错误
            trace('admin_log_record_error: ' . $e->getMessage(), 'error');
        }
    }
}
