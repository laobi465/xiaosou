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
              ->paginate(config('pan.page_size.admin', 15), false, ['query' => $this->request->param()]);

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
              ->paginate(config('pan.page_size.admin', 15), false, ['query' => $this->request->param()]);

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
          ->paginate(config('pan.page_size.admin', 15), false, ['query' => $this->request->param()]);

        return view('log/payment', [
            'list'     => $list,
            'order_no' => $orderNo,
            'event'    => $event,
        ]);
    }

    /**
     * 异常访问日志(读 runtime/log 目录最近文件)
     *
     * 安全加固:
     *   - 读取前 filesize 检查上限,超过 2MB 只读尾部 2MB,防 GB 级日志 OOM
     *   - 使用 SplFileObject 读取尾部 N 行,避免 file() 一次性载入全文件
     *   - filemtime 失败时做 false 判断,避免相减产生 0 触发警告
     *   - 对日志内容做敏感字段脱敏,降低敏感信息泄露风险
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

        // 预取 mtime 并缓存,失败置 0,避免 usort 内 filemtime 失败相减产生 0 警告
        $mtimes = [];
        foreach ($files as $f) {
            $m = @filemtime($f);
            $mtimes[$f] = ($m === false) ? 0 : $m;
        }

        // 按修改时间倒序(使用预缓存 mtime,避免 false 参与运算)
        usort($files, function ($a, $b) use ($mtimes) {
            return $mtimes[$b] <=> $mtimes[$a];
        });

        // 取最近 10 个文件
        $files = array_slice($files, 0, 10);

        $logs = [];
        foreach ($files as $file) {
            $size   = @filesize($file);
            $size   = ($size === false) ? 0 : $size;
            $mtime  = $mtimes[$file] ?? 0;
            // 读尾部最多 200 行,且单文件最多读取 2MB,防 OOM
            $tail   = $this->readTailLines($file, 200, 2 * 1024 * 1024);
            $content = $this->redactSensitive(implode("\n", $tail));

            $logs[] = [
                'file'    => str_replace($logDir . '/', '', $file),
                'mtime'   => $mtime > 0 ? date('Y-m-d H:i:s', $mtime) : '-',
                'size'    => $size,
                'content' => $content,
            ];
        }

        return view('log/exception', ['logs' => $logs]);
    }

    /**
     * 使用 SplFileObject 读取文件尾部 N 行,且单次最多读取 maxBytes 字节,防 OOM
     *
     * @param string $file      文件路径
     * @param int    $maxLines  最多返回行数
     * @param int    $maxBytes  最多读取字节数(从尾部算起)
     * @return array<string>
     */
    protected function readTailLines(string $file, int $maxLines, int $maxBytes): array
    {
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }

        $size = @filesize($file);
        if ($size === false) {
            return [];
        }

        try {
            $spl = new \SplFileObject($file, 'r');
        } catch (\Throwable $e) {
            return [];
        }

        // 文件超过上限: 从 (size - maxBytes) 处开始读,跳过首行残片
        if ($size > $maxBytes) {
            $spl->fseek($size - $maxBytes);
            // 跳过可能截断的首行
            $spl->current();
            $spl->next();
        }

        $lines = [];
        foreach ($spl as $line) {
            // SplFileObject 迭代含换行,去掉换行与空行
            $line = rtrim((string) $line, "\r\n");
            if ($line === '') {
                continue;
            }
            $lines[] = $line;
        }

        // 释放文件句柄
        $spl = null;

        // 仅保留尾部 N 行
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }

        return $lines;
    }

    /**
     * 敏感字段脱敏: 对日志行中的 password / token / secret / key / authorization 等
     * 键值对做掩码处理,降低敏感信息泄露风险。
     *
     * @param string $content
     * @return string
     */
    protected function redactSensitive(string $content): string
    {
        // 匹配 key=value 或 key":"value 形式的敏感字段
        $pattern = '/\b(password|passwd|pwd|secret|token|api_key|apikey|access_key|private_key|authorization)(\s*[=:]\s*|"\s*:\s*")[^\'"\s,}\]]+/i';
        return preg_replace_callback($pattern, function ($m) {
            return $m[1] . $m[2] . '******';
        }, $content) ?? $content;
    }
}
