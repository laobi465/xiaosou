<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use think\facade\Session;
use think\Response;

/**
 * Web 安装向导控制器
 *
 * 提供分步安装界面与 Ajax 接口:
 *  - 环境检测
 *  - 数据库配置
 *  - Redis 配置
 *  - SMTP/支付/管理员配置
 *  - 执行安装(写 .env + 建库建表 + 种子数据 + 预热缓存 + 自检)
 *
 * 安装完成后在项目根目录生成 install.lock, 防止重复安装。
 * 此控制器不继承前台 layout, 向导页面使用独立布局。
 */
class Install extends BaseController
{
    /**
     * 安装锁文件路径(项目根目录)
     */
    protected function lockFile(): string
    {
        return $this->app->getRootPath() . 'install.lock';
    }

    /**
     * 是否已安装
     */
    protected function isInstalled(): bool
    {
        return file_exists($this->lockFile());
    }

    /**
     * 安装向导首页
     * 已安装且未带 force=1 参数则返回 404
     */
    public function index()
    {
        $force = (int) $this->request->get('force', 0);
        if ($this->isInstalled() && !$force) {
            return Response::create('Not Found', 'html', 404);
        }
        return view('install/index');
    }

    /**
     * Ajax 分步处理
     * 已安装则拒绝, 防止恶意调用
     */
    public function ajax(string $step)
    {
        if ($this->isInstalled()) {
            return $this->error('已安装, 如需重装请删除 install.lock 或访问 /install?force=1', 403);
        }

        return match ($step) {
            'env'      => $this->stepEnv(),
            'database' => $this->stepDatabase(),
            'redis'    => $this->stepRedis(),
            'config'   => $this->stepConfig(),
            'run'      => $this->stepRun(),
            default    => $this->error('未知的安装步骤: ' . $step, 404),
        };
    }

    /**
     * 步骤一: 环境检测
     * 检测 PHP 版本、扩展、MySQL 客户端、目录可写性
     */
    protected function stepEnv(): Response
    {
        $extensions = ['pdo_mysql', 'redis', 'mbstring', 'openssl', 'bcmath', 'fileinfo', 'gd'];
        $extStatus  = [];
        $allOk      = true;

        foreach ($extensions as $ext) {
            $loaded          = extension_loaded($ext);
            $extStatus[$ext] = $loaded;
            if (!$loaded) {
                $allOk = false;
            }
        }

        $phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
        if (!$phpOk) {
            $allOk = false;
        }

        $mysqlClient = function_exists('mysqli_connect') || class_exists('PDO');
        if (!$mysqlClient) {
            $allOk = false;
        }

        $rootPath = $this->app->getRootPath();
        $writable = [
            'runtime'        => is_writable($rootPath . 'runtime'),
            'public/uploads' => is_writable($rootPath . 'public/uploads'),
        ];
        foreach ($writable as $ok) {
            if (!$ok) {
                $allOk = false;
            }
        }

        return $this->success([
            'php_version' => PHP_VERSION,
            'php_ok'      => $phpOk,
            'extensions'  => $extStatus,
            'mysql_client'=> $mysqlClient,
            'writable'    => $writable,
            'all_ok'      => $allOk,
        ]);
    }

    /**
     * 步骤二: 数据库配置
     * 接收连接信息, 测试连接, 创建数据库, 暂存到 Session
     */
    protected function stepDatabase(): Response
    {
        $cfg = [
            'db_host'     => trim((string) $this->request->post('db_host', '')),
            'db_port'     => trim((string) $this->request->post('db_port', '')),
            'db_name'     => trim((string) $this->request->post('db_name', '')),
            'db_user'     => trim((string) $this->request->post('db_user', '')),
            'db_password' => (string) $this->request->post('db_password', ''),
        ];

        if ($cfg['db_host'] === '') {
            return $this->error('数据库主机不能为空');
        }
        if (!ctype_digit($cfg['db_port']) || (int) $cfg['db_port'] < 1 || (int) $cfg['db_port'] > 65535) {
            return $this->error('数据库端口必须为 1-65535 的数字');
        }
        if ($cfg['db_name'] === '') {
            return $this->error('数据库名不能为空');
        }
        if ($cfg['db_user'] === '') {
            return $this->error('数据库用户名不能为空');
        }

        // 连接测试
        [$ok, $err] = $this->testMysql($cfg);
        if (!$ok) {
            return $this->error('数据库连接失败: ' . $err);
        }

        // 创建数据库并 USE 测试
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']);
            $pdo = new \PDO($dsn, $cfg['db_user'], $cfg['db_password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $dbName = $cfg['db_name'];
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");
            $pdo = null;
        } catch (\PDOException $e) {
            return $this->error('数据库初始化失败: ' . $e->getMessage());
        }

        Session::set('install_config.db', $cfg);
        return $this->success(['message' => '数据库连接成功']);
    }

    /**
     * 步骤三: Redis 配置
     * 支持跳过(skip=true); 连接测试通过后暂存到 Session
     */
    protected function stepRedis(): Response
    {
        $skip = (bool) $this->request->post('skip', 0);

        $cfg = [
            'redis_host'     => trim((string) $this->request->post('redis_host', '')),
            'redis_port'     => trim((string) $this->request->post('redis_port', '')),
            'redis_password' => (string) $this->request->post('redis_password', ''),
        ];

        if ($skip) {
            Session::set('install_config.redis', []);
            return $this->success(['message' => '已跳过 Redis 配置']);
        }

        if ($cfg['redis_host'] === '') {
            return $this->error('Redis 主机不能为空');
        }
        if (!ctype_digit($cfg['redis_port']) || (int) $cfg['redis_port'] < 1 || (int) $cfg['redis_port'] > 65535) {
            return $this->error('Redis 端口必须为 1-65535 的数字');
        }

        [$ok, $err] = $this->testRedis($cfg);
        if (!$ok) {
            return $this->error('Redis 连接失败: ' . $err);
        }

        Session::set('install_config.redis', $cfg);
        return $this->success(['message' => 'Redis 连接成功']);
    }

    /**
     * 步骤四: SMTP/支付/管理员配置
     * SMTP 用户名或支付 PID 为空时, 对应字段整体置空
     */
    protected function stepConfig(): Response
    {
        $smtp = [
            'smtp_host'      => trim((string) $this->request->post('smtp_host', '')),
            'smtp_port'      => trim((string) $this->request->post('smtp_port', '')),
            'smtp_user'      => trim((string) $this->request->post('smtp_user', '')),
            'smtp_pass'      => (string) $this->request->post('smtp_pass', ''),
            'smtp_from_name' => trim((string) $this->request->post('smtp_from_name', '')),
        ];

        if ($smtp['smtp_user'] === '') {
            // SMTP 用户名为空, 其他 SMTP 字段置空
            $smtp['smtp_host']      = '';
            $smtp['smtp_port']      = '';
            $smtp['smtp_pass']      = '';
            $smtp['smtp_from_name'] = '';
        } else {
            if (!filter_var($smtp['smtp_user'], FILTER_VALIDATE_EMAIL)) {
                return $this->error('SMTP 用户名邮箱格式非法');
            }
            if (!ctype_digit($smtp['smtp_port']) || (int) $smtp['smtp_port'] < 1 || (int) $smtp['smtp_port'] > 65535) {
                return $this->error('SMTP 端口必须为 1-65535 的数字');
            }
        }

        $pay = [
            'pay_pid' => trim((string) $this->request->post('pay_pid', '')),
            'pay_key' => (string) $this->request->post('pay_key', ''),
            'pay_api' => trim((string) $this->request->post('pay_api', '')),
        ];
        if ($pay['pay_pid'] === '') {
            // 商户 PID 为空, 其他支付字段置空
            $pay['pay_key'] = '';
            $pay['pay_api'] = '';
        }

        $admin = [
            'admin_user'     => trim((string) $this->request->post('admin_user', '')),
            'admin_password' => (string) $this->request->post('admin_password', ''),
        ];
        if ($admin['admin_user'] === '') {
            return $this->error('管理员用户名不能为空');
        }
        if (mb_strlen($admin['admin_password']) < 6) {
            return $this->error('管理员密码至少 6 位');
        }

        Session::set('install_config.smtp', $smtp);
        Session::set('install_config.pay', $pay);
        Session::set('install_config.admin', $admin);

        return $this->success();
    }

    /**
     * 步骤五: 执行安装
     * 写 .env → 建库建表 + 种子数据 → 创建管理员 → 预热缓存 → 自检 → 生成锁
     */
    protected function stepRun(): Response
    {
        $db    = Session::get('install_config.db');
        $redis = Session::get('install_config.redis');
        $smtp  = Session::get('install_config.smtp');
        $pay   = Session::get('install_config.pay');
        $admin = Session::get('install_config.admin');

        // 校验配置完整性
        if (empty($db) || empty($admin)) {
            return $this->error('请先完成前序步骤');
        }

        // 合并为扁平配置(与 app/command/Install.php 的 $this->config 结构一致)
        $cfg = array_merge(
            is_array($db) ? $db : [],
            is_array($redis) ? $redis : [],
            is_array($smtp) ? $smtp : [],
            is_array($pay) ? $pay : [],
            is_array($admin) ? $admin : []
        );

        try {
            // 1. 写 .env
            $this->writeEnv($cfg);

            // 2. 连接数据库
            $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']);
            $pdo = new \PDO($dsn, $cfg['db_user'], $cfg['db_password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $dbName = $cfg['db_name'];
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");

            // 3. 执行建表 + 种子数据
            $rootPath   = $this->app->getRootPath();
            $installSql = $rootPath . 'database/install.sql';
            if (file_exists($installSql)) {
                $this->executeSqlFile($pdo, $installSql);
            }
            $dataSql = $rootPath . 'database/data.sql';
            if (file_exists($dataSql)) {
                $this->executeSqlFile($pdo, $dataSql);
            }

            // 4. 创建/更新管理员
            $this->setupAdmin($pdo, $cfg);

            // 5. 预热 Redis 缓存(Redis 配置为空则跳过)
            $redisEnabled = is_array($redis) && !empty($redis);
            $warmup = $redisEnabled ? $this->warmupCache($pdo, $cfg) : ['skipped' => true];

            // 6. 安装后自检
            $check = $this->postCheck($pdo, $cfg);

            // 7. 生成 install.lock
            $this->createLock();

            // 8. 清除 Session
            Session::clear();

            $check['warmup'] = $warmup;

            return $this->success([
                'check'      => $check,
                'next_steps' => $this->buildNextSteps($cfg),
            ]);
        } catch (\Throwable $e) {
            return $this->error('安装失败: ' . $e->getMessage());
        }
    }

    /**
     * 测试 MySQL 连接
     * @return array{0:bool, 1:string} [ok, error]
     */
    protected function testMysql(array $cfg): array
    {
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']);
            $pdo = new \PDO($dsn, $cfg['db_user'], $cfg['db_password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo = null;
            return [true, ''];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * 测试 Redis 连接
     * @return array{0:bool, 1:string} [ok, error]
     */
    protected function testRedis(array $cfg): array
    {
        try {
            $redis = new \Redis();
            $connected = $redis->connect($cfg['redis_host'], (int) $cfg['redis_port'], 5);
            if (!$connected) {
                return [false, 'Redis connect 返回 false'];
            }
            if (!empty($cfg['redis_password'])) {
                $redis->auth($cfg['redis_password']);
            }
            $redis->ping();
            $redis->close();
            return [true, ''];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * 写入 .env 文件
     * 基于 .env.example 模板, 生成随机密钥, 按段替换
     */
    protected function writeEnv(array $cfg): void
    {
        $rootPath   = $this->app->getRootPath();
        $envExample = $rootPath . '.env.example';
        if (!file_exists($envExample)) {
            throw new \RuntimeException('.env.example 模板文件不存在');
        }

        $content = (string) file_get_contents($envExample);

        // 生成随机密钥
        $appKey = bin2hex(random_bytes(16));
        $aesKey = bin2hex(random_bytes(16));

        // 按段精确替换键值, 避免 PASSWORD 等重复键名冲突
        $content = $this->replaceEnvSection($content, 'DATABASE', [
            'HOSTNAME' => $cfg['db_host'] ?? '',
            'DATABASE' => $cfg['db_name'] ?? '',
            'USERNAME' => $cfg['db_user'] ?? '',
            'PASSWORD' => $cfg['db_password'] ?? '',
            'HOSTPORT' => $cfg['db_port'] ?? '',
        ]);
        $content = $this->replaceEnvSection($content, 'REDIS', [
            'HOST'     => $cfg['redis_host'] ?? '',
            'PORT'     => $cfg['redis_port'] ?? '',
            'PASSWORD' => $cfg['redis_password'] ?? '',
        ]);
        $content = $this->replaceEnvSection($content, 'MAIL', [
            'SMTP_HOST'      => $cfg['smtp_host'] ?? '',
            'SMTP_PORT'      => $cfg['smtp_port'] ?? '',
            'SMTP_USER'      => $cfg['smtp_user'] ?? '',
            'SMTP_PASS'      => $cfg['smtp_pass'] ?? '',
            'SMTP_FROM'      => $cfg['smtp_user'] ?? '',
            'SMTP_FROM_NAME' => $cfg['smtp_from_name'] ?? '',
        ]);
        $content = $this->replaceEnvSection($content, 'PAY', [
            'CAIHONG_PID' => $cfg['pay_pid'] ?? '',
            'CAIHONG_KEY' => $cfg['pay_key'] ?? '',
            'CAIHONG_API' => $cfg['pay_api'] ?? '',
        ]);
        $content = $this->replaceEnvSection($content, 'APP', [
            'KEY' => $appKey,
        ]);
        $content = $this->replaceEnvSection($content, 'SECURITY', [
            'AES_KEY' => $aesKey,
        ]);

        file_put_contents($rootPath . '.env', $content);
    }

    /**
     * 精确替换 .env 某个段(section)下的键值
     * 逻辑复制自 app/command/Install.php
     */
    private function replaceEnvSection(string $content, string $section, array $values): string
    {
        // 匹配 [SECTION] 段内容
        $pattern = '/(\[' . $section . '\]\r?\n)([\s\S]*?)(\r?\n\[|\z)/';
        if (!preg_match($pattern, $content, $matches)) {
            return $content;
        }

        $sectionBody = $matches[2];
        foreach ($values as $key => $val) {
            // 匹配 KEY = value (含引号) 并替换
            $linePattern = '/^(' . preg_quote($key) . '\s*=\s*)(.*)$/m';
            $replacement = $val === '' ? '$1""' : '$1"' . $val . '"';
            // 纯数字不加引号
            if (ctype_digit((string) $val)) {
                $replacement = '$1' . $val;
            }
            $sectionBody = preg_replace($linePattern, $replacement, $sectionBody);
        }

        return preg_replace($pattern, $matches[1] . $sectionBody . $matches[3], $content, 1);
    }

    /**
     * 执行 SQL 文件(支持多语句)
     * 逻辑复制自 app/command/Install.php: 移除注释行, 按分号拆分执行
     */
    protected function executeSqlFile(\PDO $pdo, string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            return;
        }

        // 移除注释行和空行
        $lines   = explode("\n", $sql);
        $cleaned = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                continue;
            }
            $cleaned[] = $line;
        }
        $sql = implode("\n", $cleaned);

        // 按分号拆分语句
        $statements = array_filter(array_map('trim', explode(";\n", $sql)));
        foreach ($statements as $stmt) {
            $stmt = rtrim($stmt, ';');
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
    }

    /**
     * 创建/更新管理员账号(BCrypt 哈希)
     * 先查重, 存在则 UPDATE 密码, 不存在则 INSERT
     */
    protected function setupAdmin(\PDO $pdo, array $cfg): void
    {
        $username = $cfg['admin_user'];
        $password = $cfg['admin_password'];
        $hash     = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE username = ?');
        $stmt->execute([$username]);
        $exists = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($exists) {
            $stmt = $pdo->prepare('UPDATE admin_users SET password = ?, status = 1, update_time = NOW() WHERE username = ?');
            $stmt->execute([$hash, $username]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO admin_users (username, password, nickname, status, create_time, update_time) VALUES (?, ?, ?, 1, NOW(), NOW())');
            $stmt->execute([$username, $hash, '超级管理员']);
        }
    }

    /**
     * 预热 Redis 缓存(pan_sources + sensitive_words)
     * 返回自检数据
     */
    protected function warmupCache(\PDO $pdo, array $cfg): array
    {
        $result = [
            'pan_sources'     => 0,
            'sensitive_words' => 0,
            'redis_ping'      => false,
            'error'           => '',
        ];

        try {
            $redis = new \Redis();
            $connected = $redis->connect($cfg['redis_host'], (int) $cfg['redis_port'], 5);
            if (!$connected) {
                $result['error'] = 'Redis connect 返回 false';
                return $result;
            }
            if (!empty($cfg['redis_password'])) {
                $redis->auth($cfg['redis_password']);
            }
            $redis->ping();

            // 预热网盘源列表
            $stmt    = $pdo->query('SELECT id, code, name FROM pan_sources WHERE enabled = 1 ORDER BY sort, id');
            $sources = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            $redis->set('pansou:pan:sources', json_encode($sources, \JSON_UNESCAPED_UNICODE));
            $result['pan_sources'] = count($sources);

            // 预热敏感词
            $stmt  = $pdo->query('SELECT word, replace FROM sensitive_words WHERE status = 1');
            $words = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            $redis->set('pansou:sensitive:words', json_encode($words, \JSON_UNESCAPED_UNICODE));
            $result['sensitive_words'] = count($words);

            $result['redis_ping'] = true;
            $redis->close();
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * 安装后自检: 表数量(期望 27)、Redis ping、管理员存在
     */
    protected function postCheck(\PDO $pdo, array $cfg): array
    {
        $expectedTables = 27;

        // a. 表数量
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
        $stmt->execute([$cfg['db_name']]);
        $tableCount = (int) $stmt->fetchColumn();

        // b. Redis ping(Redis 未配置则跳过)
        $redisPing = false;
        if (!empty($cfg['redis_host'])) {
            try {
                $redis = new \Redis();
                $connected = $redis->connect($cfg['redis_host'], (int) $cfg['redis_port'], 5);
                if ($connected) {
                    if (!empty($cfg['redis_password'])) {
                        $redis->auth($cfg['redis_password']);
                    }
                    $redis->ping();
                    $redisPing = true;
                    $redis->close();
                }
            } catch (\Throwable $e) {
                $redisPing = false;
            }
        }

        // c. 管理员账号
        $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE username = ?');
        $stmt->execute([$cfg['admin_user']]);
        $adminExists = $stmt->fetch() !== false;

        return [
            'tables'       => [
                'expected' => $expectedTables,
                'actual'   => $tableCount,
                'ok'       => $tableCount === $expectedTables,
            ],
            'redis_ping'   => $redisPing,
            'admin_exists' => $adminExists,
            'admin_user'   => $cfg['admin_user'],
        ];
    }

    /**
     * 生成 install.lock 文件(内容: 时间戳)
     */
    protected function createLock(): void
    {
        file_put_contents($this->lockFile(), time() . PHP_EOL);
    }

    /**
     * 检测是否宝塔面板环境
     */
    private function detectBaota(): bool
    {
        return is_dir('/www/server/panel');
    }

    /**
     * 构建后续步骤指引文本(区分宝塔/通用)
     */
    private function buildNextSteps(array $cfg): string
    {
        $lines = [];

        if ($this->detectBaota()) {
            $lines[] = '[宝塔环境]';
            $lines[] = '1. 配置 Nginx 伪静态规则(网站设置 -> 伪静态)';
            $lines[] = '2. 配置 Supervisor 守护队列:';
            $lines[] = '   php think crawl:consume';
            $lines[] = '   php think mail:consume';
            $lines[] = '3. 添加 Crontab 定时任务:';
            $lines[] = '   * * * * * php think crawl:dispatch';
            $lines[] = '   * * * * * php think order:close';
            $lines[] = '4. 申请 SSL 证书并强制 HTTPS';
            $lines[] = '5. 编辑 .env 设置 SESSION_SECURE=true';
            $lines[] = '详细教程: docs/宝塔面板部署教程.md';
        } else {
            $lines[] = '[通用环境]';
            $lines[] = '1. 配置 Web 服务器指向 public/ 目录';
            $lines[] = '2. 配置 supervisor 守护队列:';
            $lines[] = '   php think crawl:consume';
            $lines[] = '   php think mail:consume';
            $lines[] = '3. 配置 crontab 定时任务:';
            $lines[] = '   * * * * * php think crawl:dispatch';
            $lines[] = '   * * * * * php think order:close';
        }

        if (($cfg['smtp_user'] ?? '') === '') {
            $lines[] = '注意: 邮件功能未配置, 后续编辑 .env [MAIL] 段';
        }
        if (($cfg['pay_pid'] ?? '') === '') {
            $lines[] = '注意: 支付功能未配置, 后续编辑 .env [PAY] 段';
        }

        return implode("\n", $lines);
    }
}
