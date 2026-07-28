<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;
use app\common\model\AdminLog;
use app\common\model\PaymentLog;
use app\common\model\UserLoginLog;

/**
 * 日志查看
 */
class Log extends BaseAdminController
{
    /**
     * 默认入口 → 跳转管理员操作日志
     */
    public function index()
    {
        return $this->admin();
    }

    /**
     * 管理员操作日志(AdminLog 分页)
     */
    public function admin()
    {
        $module = (string) $this->request->get('module', '');
        $adminId = $this->request->get('admin_id', '');

        $list = AdminLog::with('admin')
            ->when($module !== '', function ($query) use ($module) {
                $query->where('module', $module);
            })->when($adminId !== '' && $adminId !== null, function ($query) use ($adminId) {
                $query->where('admin_id', (int) $adminId);
            })->order('id', 'desc')
              ->paginate(15, false, ['query' => $this->request->param()]);

        return view('log/admin', [
            'list'     => $list,
            'module'   => $module,
            'admin_id' => $adminId,
        ]);
    }

    /**
     * 用户登录日志(UserLoginLog 分页)
     */
    public function userLogin()
    {
        $userId = $this->request->get('user_id', '');
        $result = $this->request->get('result', '');

        $list = UserLoginLog::with('user')
            ->when($userId !== '' && $userId !== null, function ($query) use ($userId) {
                $query->where('user_id', (int) $userId);
            })->when($result !== '' && $result !== null, function ($query) use ($result) {
                $query->where('result', (int) $result);
            })->order('id', 'desc')
              ->paginate(15, false, ['query' => $this->request->param()]);

        return view('log/user_login', [
            'list'    => $list,
            'user_id' => $userId,
            'result'  => $result,
        ]);
    }

    /**
     * 支付日志(PaymentLog 分页)
     */
    public function payment()
    {
        $orderNo = (string) $this->request->get('order_no', '');
        $event   = (string) $this->request->get('event', '');

        $list = PaymentLog::when($orderNo !== '', function ($query) use ($orderNo) {
            $query->whereLike('order_no', '%' . $orderNo . '%');
        })->when($event !== '', function ($query) use ($event) {
            $query->where('event', $event);
        })->order('id', 'desc')
          ->paginate(15, false, ['query' => $this->request->param()]);

        return view('log/payment', [
            'list'     => $list,
            'order_no' => $orderNo,
            'event'    => $event,
        ]);
    }

    /**
     * 异常访问日志(读 runtime/log 目录最近文件)
     */
    public function exception()
    {
        $logDir = runtime_path() . 'log';
        $files  = [];

        if (is_dir($logDir)) {
            // ThinkPHP 日志结构: runtime/log/YYYYMM/DD.log
            $monthDirs = glob($logDir . '/*', GLOB_ONLYDIR);
            if (is_array($monthDirs)) {
                foreach ($monthDirs as $monthDir) {
                    $dayFiles = glob($monthDir . '/*.log');
                    if (is_array($dayFiles)) {
                        $files = array_merge($files, $dayFiles);
                    }
                }
            }
            // 兼容扁平结构
            $flatFiles = glob($logDir . '/*.log');
            if (is_array($flatFiles)) {
                $files = array_merge($files, $flatFiles);
            }
        }

        // 按修改时间倒序
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        // 取最近 10 个文件
        $files = array_slice($files, 0, 10);

        $logs = [];
        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                $lines = [];
            }
            // 每个文件取最后 200 行
            $tail = array_slice($lines, -200);

            $logs[] = [
                'file'    => str_replace($logDir . '/', '', $file),
                'mtime'   => date('Y-m-d H:i:s', filemtime($file)),
                'size'    => filesize($file),
                'content' => implode("\n", $tail),
            ];
        }

        return view('log/exception', ['logs' => $logs]);
    }
}
