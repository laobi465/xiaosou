<?php
namespace app;

use think\App;
use think\facade\Session;
use think\Response;

/**
 * 后台控制器基类
 * 注入管理员会话、操作日志钩子
 */
abstract class BaseAdminController extends BaseController
{
    protected ?array $admin = null;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->initAdmin();
    }

    /**
     * 加载当前管理员信息
     */
    protected function initAdmin(): void
    {
        $adminId = Session::get('admin_id');
        if ($adminId) {
            $this->admin = [
                'id'       => (int) $adminId,
                'username' => Session::get('admin_username', ''),
                'nickname' => Session::get('admin_nickname', ''),
            ];
            $this->request->adminId = (int) $adminId;
        }
    }

    protected function adminId(): ?int
    {
        return $this->admin['id'] ?? null;
    }

    protected function isLogin(): bool
    {
        return $this->adminId() !== null;
    }

    /**
     * 记录管理员操作日志(子类可调用)
     */
    protected function logAction(string $module, string $action, ?int $targetId = null, array $detail = []): void
    {
        // 委托 AdminLogService 记录
        try {
            app(\app\common\service\AdminLogService::class)->record(
                $this->adminId(),
                $module,
                $action,
                $targetId,
                $detail,
                $this->request->ip(),
                $this->request->header('user-agent', '')
            );
        } catch (\Throwable $e) {
            // 日志失败不影响主流程
            trace('admin_log_error: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * 异常脱敏: trace 记录原始异常信息,对外返回通用提示
     *
     * @param string    $userMessage 对外展示的通用提示
     * @param \Throwable $e          捕获到的异常
     * @param string    $logPrefix   日志前缀,便于检索
     * @return Response
     */
    protected function errorWithLog(string $userMessage, \Throwable $e, string $logPrefix = 'admin_error'): Response
    {
        trace($logPrefix . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString(), 'error');
        return $this->error($userMessage);
    }
}
