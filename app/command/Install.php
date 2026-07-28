<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/**
 * 一键安装命令
 *
 * 交互式输入数据库/SMTP/支付/管理员配置,
 * 写入 .env, 执行建库 SQL, 预热 Redis 缓存。
 *
 * 参考: docs/架构设计文档.md 行 755-774
 */
class Install extends Command
{
    /** @var array 收集到的配置 */
    protected array $config = [];

    /** @var \PDO|null 数据库连接(安装过程中复用) */
    protected ?\PDO $pdo = null;

    /** @var bool Redis 是否就绪(预检通过) */
    protected bool $redisOk = false;

    protected function configure()
    {
        $this->setName('install')
            ->setDescription('一键安装: 配置.env + 建库建表 + 种子数据 + 预热缓存')
            ->addOption('force', 'f', Option::VALUE_NONE, '覆盖已存在的 .env 文件');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('');
        $output->writeln('<info>========================================</info>');
        $output->writeln('<info>   网盘资源搜索引擎 - 一键安装</info>');
        $output->writeln('<info>========================================</info>');
        $output->writeln('');

        $rootPath = $this->app->getRootPath();

        // 检查 .env 是否已存在
        if (file_exists($rootPath . '.env') && !$input->getOption('force')) {
            $output->writeln('<comment>.env 已存在, 如需重新安装请使用 --force</comment>');
            return;
        }

        // --force 交互式确认(仅 TTY 环境, 非交互直接覆盖)
        if (file_exists($rootPath . '.env') && $input->getOption('force') && $this->isInteractive()) {
            $answer = $this->ask($input, $output, '确认覆盖已存在的 .env? [y/N]', 'N');
            if (strtolower($answer) !== 'y') {
                $output->writeln('<comment>已取消安装</comment>');
                return;
            }
        }

        // 1. 交互式收集配置(含校验)
        $this->collectConfig($input, $output);

        // 2. 连接预检(MySQL + Redis)
        $this->preflightCheck($input, $output);

        // 3. 写入 .env 文件
        $this->writeEnvFile($rootPath);
        $output->writeln('<info>[✓] .env 配置文件已写入</info>');

        // 4. 执行 SQL 建库建表 + 种子数据
        $this->executeSql($rootPath, $output);

        // 5. 创建/更新管理员账号
        $this->setupAdmin($output);

        // 6. 预热 Redis 缓存(Redis 未就绪则跳过)
        if ($this->redisOk) {
            $this->warmupCache($output);
        } else {
            $output->writeln('<comment>--- 预热 Redis 缓存 ---</comment>');
            $output->writeln('<comment>[!] Redis 未就绪, 跳过缓存预热</comment>');
        }

        // 7. 安装后自检
        $this->postInstallCheck($output);

        // 8. 友好下一步指引
        $output->writeln('');
        $output->writeln('<info>========================================</info>');
        $output->writeln('<info>   安装完成!</info>');
        $output->writeln('<info>========================================</info>');
        $output->writeln('');
        $this->printNextSteps($output);
    }

    /**
     * 交互式收集配置
     */
    protected function collectConfig(Input $input, Output $output): void
    {
        $portValidator = function (string $v): string {
            if (!ctype_digit((string) $v) || (int) $v < 1 || (int) $v > 65535) {
                return '端口必须为 1-65535 的数字';
            }
            return '';
        };

        $output->writeln('<comment>--- 数据库配置 ---</comment>');
        $this->config['db_host']     = $this->ask($input, $output, '数据库主机', '127.0.0.1');
        $this->config['db_port']     = $this->askValidated($input, $output, '数据库端口', '3306', $portValidator);
        $this->config['db_name']     = $this->ask($input, $output, '数据库名', 'pan_search');
        $this->config['db_user']     = $this->ask($input, $output, '数据库用户名', 'root');
        $this->config['db_password'] = $this->askHidden($input, $output, '数据库密码');

        $output->writeln('');
        $output->writeln('<comment>--- Redis 配置 ---</comment>');
        $this->config['redis_host']     = $this->ask($input, $output, 'Redis 主机', '127.0.0.1');
        $this->config['redis_port']     = $this->askValidated($input, $output, 'Redis 端口', '6379', $portValidator);
        $this->config['redis_password'] = $this->askHidden($input, $output, 'Redis 密码(空则回车)');

        $output->writeln('');
        $output->writeln('<comment>--- SMTP 邮件配置 ---</comment>');
        $this->config['smtp_host'] = $this->ask($input, $output, 'SMTP 主机', 'smtp.qq.com');
        $this->config['smtp_user'] = $this->askValidated($input, $output, 'SMTP 用户名(邮箱)', '', function (string $v): string {
            if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                return '邮箱格式非法';
            }
            return '';
        });
        if ($this->config['smtp_user'] !== '') {
            $this->config['smtp_port']      = $this->askValidated($input, $output, 'SMTP 端口', '465', $portValidator);
            $this->config['smtp_pass']      = $this->askHidden($input, $output, 'SMTP 密码/授权码');
            $this->config['smtp_from_name'] = $this->ask($input, $output, '发件人名称', '网盘搜索');
        } else {
            $this->config['smtp_port']      = '';
            $this->config['smtp_pass']      = '';
            $this->config['smtp_from_name'] = '';
            $output->writeln('<comment>SMTP 用户名为空, 跳过 SMTP 配置</comment>');
        }

        $output->writeln('');
        $output->writeln('<comment>--- 彩虹易支付配置 ---</comment>');
        $this->config['pay_pid'] = $this->ask($input, $output, '商户 PID', '');
        if ($this->config['pay_pid'] !== '') {
            $this->config['pay_key'] = $this->askHidden($input, $output, '商户密钥 KEY');
        } else {
            $this->config['pay_key'] = '';
            $output->writeln('<comment>商户 PID 为空, 跳过支付配置</comment>');
        }
        $this->config['pay_api'] = $this->ask($input, $output, '支付接口地址', 'https://pay.cccdl.com');

        $output->writeln('');
        $output->writeln('<comment>--- 管理员账号 ---</comment>');
        $this->config['admin_user']     = $this->ask($input, $output, '管理员用户名', 'admin');
        $this->config['admin_password'] = $this->askValidated($input, $output, '管理员密码', '', function (string $v): string {
            if (mb_strlen($v) < 6) {
                return '管理员密码至少 6 位';
            }
            return '';
        }, true);

        $output->writeln('');
    }

    /**
     * 连接预检: MySQL + Redis
     */
    protected function preflightCheck(Input $input, Output $output): void
    {
        $output->writeln('');
        $output->writeln('<comment>--- 连接预检 ---</comment>');

        $this->preflightMysql($input, $output);
        $this->preflightRedis($input, $output);

        $output->writeln('');
    }

    /**
     * MySQL 连接预检(含建库), 失败重试 3 次后抛异常
     */
    protected function preflightMysql(Input $input, Output $output): void
    {
        $maxRetries = 3;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;charset=utf8mb4',
                    $this->config['db_host'],
                    $this->config['db_port']
                );
                $pdo = new \PDO($dsn, $this->config['db_user'], $this->config['db_password'], [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                ]);
                $dbName = $this->config['db_name'];
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $output->writeln('<info>[✓] 数据库连接正常</info>');
                return;
            } catch (\PDOException $e) {
                $output->writeln('<error>数据库连接失败: ' . $e->getMessage() . '</error>');
                if ($attempt >= $maxRetries) {
                    throw new \RuntimeException('数据库连接失败, 重试 ' . $maxRetries . ' 次仍无法连接, 终止安装');
                }
                $output->writeln('<comment>数据库连接失败, 请检查配置后重新输入(第 ' . $attempt . '/' . $maxRetries . ' 次)</comment>');
                $this->collectConfig($input, $output);
            }
        }
    }

    /**
     * Redis 连接预检, 失败可继续安装或重新输入配置
     */
    protected function preflightRedis(Input $input, Output $output): void
    {
        try {
            $redis = new \Redis();
            $connected = $redis->connect(
                $this->config['redis_host'],
                (int) $this->config['redis_port'],
                5
            );
            if (!$connected) {
                throw new \RuntimeException('Redis connect 返回 false');
            }
            if ($this->config['redis_password'] !== '') {
                $redis->auth($this->config['redis_password']);
            }
            $redis->ping();
            $redis->close();
            $this->redisOk = true;
            $output->writeln('<info>[✓] Redis 连接正常</info>');
        } catch (\Throwable $e) {
            $this->redisOk = false;
            $answer = $this->ask(
                $input,
                $output,
                'Redis 连接失败(' . $e->getMessage() . '), 是否继续安装? 后续可手动配置 [y/N]',
                'N'
            );
            if (strtolower($answer) === 'y') {
                $output->writeln('<comment>跳过 Redis, 继续安装</comment>');
                return;
            }
            $output->writeln('<comment>请重新输入 Redis 配置</comment>');
            $this->config['redis_host'] = $this->ask($input, $output, 'Redis 主机', '127.0.0.1');
            $this->config['redis_port'] = $this->askValidated($input, $output, 'Redis 端口', '6379', function (string $v): string {
                if (!ctype_digit((string) $v) || (int) $v < 1 || (int) $v > 65535) {
                    return '端口必须为 1-65535 的数字';
                }
                return '';
            });
            $this->config['redis_password'] = $this->askHidden($input, $output, 'Redis 密码(空则回车)');
            $this->preflightRedis($input, $output);
        }
    }

    /**
     * 写入 .env 文件
     */
    protected function writeEnvFile(string $rootPath): void
    {
        $envExample = $rootPath . '.env.example';
        if (!file_exists($envExample)) {
            throw new \RuntimeException('.env.example 模板文件不存在');
        }

        $content = file_get_contents($envExample);

        // 生成随机密钥
        $appKey = bin2hex(random_bytes(16));
        $aesKey = bin2hex(random_bytes(16));

        // 按段精确替换键值, 避免 PASSWORD 等重复键名冲突
        $content = $this->replaceEnvSection($content, 'DATABASE', [
            'HOSTNAME'  => $this->config['db_host'],
            'DATABASE'  => $this->config['db_name'],
            'USERNAME'  => $this->config['db_user'],
            'PASSWORD'  => $this->config['db_password'],
            'HOSTPORT'  => $this->config['db_port'],
        ]);
        $content = $this->replaceEnvSection($content, 'REDIS', [
            'HOST'     => $this->config['redis_host'],
            'PORT'     => $this->config['redis_port'],
            'PASSWORD' => $this->config['redis_password'],
        ]);
        $content = $this->replaceEnvSection($content, 'MAIL', [
            'SMTP_HOST'      => $this->config['smtp_host'],
            'SMTP_PORT'      => $this->config['smtp_port'],
            'SMTP_USER'      => $this->config['smtp_user'],
            'SMTP_PASS'      => $this->config['smtp_pass'],
            'SMTP_FROM'      => $this->config['smtp_user'],
            'SMTP_FROM_NAME' => $this->config['smtp_from_name'],
        ]);
        $content = $this->replaceEnvSection($content, 'PAY', [
            'CAIHONG_PID' => $this->config['pay_pid'],
            'CAIHONG_KEY' => $this->config['pay_key'],
            'CAIHONG_API' => $this->config['pay_api'],
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
     */
    protected function replaceEnvSection(string $content, string $section, array $values): string
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
     * 执行 SQL 建库建表 + 种子数据
     */
    protected function executeSql(string $rootPath, Output $output): void
    {
        $output->writeln('<comment>--- 执行数据库脚本 ---</comment>');

        $host = $this->config['db_host'];
        $port = $this->config['db_port'];
        $dbName = $this->config['db_name'];
        $user = $this->config['db_user'];
        $pass = $this->config['db_password'];

        try {
            // 先连接 MySQL(不指定库), 创建数据库
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");
            $output->writeln('<info>[✓] 数据库 ' . $dbName . ' 已就绪</info>');

            // 执行 install.sql
            $installSql = $rootPath . 'database/install.sql';
            if (file_exists($installSql)) {
                $this->executeSqlFile($pdo, $installSql);
                $output->writeln('<info>[✓] install.sql 建表脚本已执行</info>');
            } else {
                $output->writeln('<comment>[!] database/install.sql 不存在, 跳过建表</comment>');
            }

            // 执行 data.sql
            $dataSql = $rootPath . 'database/data.sql';
            if (file_exists($dataSql)) {
                $this->executeSqlFile($pdo, $dataSql);
                $output->writeln('<info>[✓] data.sql 种子数据已导入</info>');
            } else {
                $output->writeln('<comment>[!] database/data.sql 不存在, 跳过种子数据</comment>');
            }

            $this->pdo = $pdo;
        } catch (\PDOException $e) {
            $output->writeln('<error>[✗] 数据库连接失败: ' . $e->getMessage() . '</error>');
            throw $e;
        }
    }

    /**
     * 执行 SQL 文件(支持多语句)
     */
    protected function executeSqlFile(\PDO $pdo, string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            return;
        }

        // 移除注释行和空行
        $lines = explode("\n", $sql);
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
     * 创建/更新管理员账号
     */
    protected function setupAdmin(Output $output): void
    {
        if ($this->pdo === null) {
            return;
        }

        $output->writeln('<comment>--- 配置管理员账号 ---</comment>');

        $username = $this->config['admin_user'];
        $password = $this->config['admin_password'];
        $hash = password_hash($password, PASSWORD_BCRYPT);

        // 检查管理员是否已存在
        $stmt = $this->pdo->prepare('SELECT id FROM admin_users WHERE username = ?');
        $stmt->execute([$username]);
        $exists = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($exists) {
            $stmt = $this->pdo->prepare('UPDATE admin_users SET password = ?, status = 1, update_time = NOW() WHERE username = ?');
            $stmt->execute([$hash, $username]);
            $output->writeln('<info>[✓] 管理员 ' . $username . ' 密码已更新</info>');
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO admin_users (username, password, nickname, status, create_time, update_time) VALUES (?, ?, ?, 1, NOW(), NOW())');
            $stmt->execute([$username, $hash, '超级管理员']);
            $output->writeln('<info>[✓] 管理员 ' . $username . ' 已创建</info>');
        }
    }

    /**
     * 预热 Redis 缓存
     */
    protected function warmupCache(Output $output): void
    {
        $output->writeln('<comment>--- 预热 Redis 缓存 ---</comment>');

        try {
            $redis = new \Redis();
            $connected = $redis->connect(
                $this->config['redis_host'],
                (int) $this->config['redis_port'],
                5
            );
            if (!$connected) {
                $output->writeln('<comment>[!] Redis 连接失败, 跳过缓存预热</comment>');
                return;
            }
            if ($this->config['redis_password'] !== '') {
                $redis->auth($this->config['redis_password']);
            }

            // 预热网盘源列表
            if ($this->pdo !== null) {
                $stmt = $this->pdo->query('SELECT id, code, name FROM pan_sources WHERE enabled = 1 ORDER BY sort, id');
                $sources = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $redis->set('pansou:pan:sources', json_encode($sources, \JSON_UNESCAPED_UNICODE));
                $output->writeln('<info>[✓] 网盘源列表已缓存 (' . count($sources) . ' 条)</info>');
            }

            // 预热敏感词
            if ($this->pdo !== null) {
                $stmt = $this->pdo->query('SELECT word, replace FROM sensitive_words WHERE status = 1');
                $words = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $redis->set('pansou:sensitive:words', json_encode($words, \JSON_UNESCAPED_UNICODE));
                $output->writeln('<info>[✓] 敏感词库已缓存 (' . count($words) . ' 条)</info>');
            }

            $redis->close();
        } catch (\Throwable $e) {
            $output->writeln('<comment>[!] Redis 缓存预热失败: ' . $e->getMessage() . '</comment>');
        }
    }

    /**
     * 安装后自检: 表数量 / Redis ping / 管理员账号
     */
    protected function postInstallCheck(Output $output): void
    {
        $output->writeln('');
        $output->writeln('<comment>--- 安装后自检 ---</comment>');

        $expectedTables = 27;

        // a. 表数量
        $tableCount = 0;
        if ($this->pdo !== null) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
            $stmt->execute([$this->config['db_name']]);
            $tableCount = (int) $stmt->fetchColumn();
        }
        if ($tableCount === $expectedTables) {
            $output->writeln('<info>[✓] 数据库表数量：' . $tableCount . '/' . $expectedTables . '</info>');
        } else {
            $output->writeln('<comment>[!] 数据库表数量：' . $tableCount . '/' . $expectedTables . '（建议检查 database/install.sql 是否完整执行）</comment>');
        }

        // b. Redis ping
        if (!$this->redisOk) {
            $output->writeln('<comment>[!] Redis ping：跳过（Redis 未就绪，后续可手动检查）</comment>');
        } else {
            $pingOk = false;
            try {
                $redis = new \Redis();
                $connected = $redis->connect(
                    $this->config['redis_host'],
                    (int) $this->config['redis_port'],
                    5
                );
                if ($connected) {
                    if ($this->config['redis_password'] !== '') {
                        $redis->auth($this->config['redis_password']);
                    }
                    $redis->ping();
                    $pingOk = true;
                    $redis->close();
                }
            } catch (\Throwable $e) {
                $pingOk = false;
            }
            if ($pingOk) {
                $output->writeln('<info>[✓] Redis ping：成功</info>');
            } else {
                $output->writeln('<error>[✗] Redis ping：失败（后续可手动检查）</error>');
            }
        }

        // c. 管理员账号
        $adminOk = false;
        if ($this->pdo !== null) {
            $stmt = $this->pdo->prepare('SELECT id FROM admin_users WHERE username = ?');
            $stmt->execute([$this->config['admin_user']]);
            $adminOk = $stmt->fetch() !== false;
        }
        if ($adminOk) {
            $output->writeln('<info>[✓] 管理员账号 ' . $this->config['admin_user'] . '：已创建</info>');
        } else {
            $output->writeln('<error>[✗] 管理员账号 ' . $this->config['admin_user'] . '：未找到</error>');
        }
    }

    /**
     * 检测是否宝塔面板环境
     */
    protected function detectBaota(): bool
    {
        return is_dir('/www/server/panel');
    }

    /**
     * 输出友好下一步指引(区分宝塔/通用)
     */
    protected function printNextSteps(Output $output): void
    {
        $output->writeln('后续步骤：');
        $output->writeln('');

        if ($this->detectBaota()) {
            $output->writeln('[宝塔环境]');
            $output->writeln('1. 配置 Nginx 伪静态规则（网站设置 → 伪静态）');
            $output->writeln('2. 配置 Supervisor 守护队列：');
            $output->writeln('   php think crawl:consume');
            $output->writeln('   php think mail:consume');
            $output->writeln('3. 添加 Crontab 定时任务：');
            $output->writeln('   * * * * * php think crawl:dispatch');
            $output->writeln('   * * * * * php think order:close');
            $output->writeln('4. 申请 SSL 证书并强制 HTTPS');
            $output->writeln('5. 编辑 .env 设置 SESSION_SECURE=true');
            $output->writeln('');
            $output->writeln('详细教程：docs/宝塔面板部署教程.md');
        } else {
            $output->writeln('[通用环境]');
            $output->writeln('1. 配置 Web 服务器指向 public/ 目录');
            $output->writeln('2. 配置 supervisor 守护队列：');
            $output->writeln('   php think crawl:consume');
            $output->writeln('   php think mail:consume');
            $output->writeln('3. 配置 crontab 定时任务：');
            $output->writeln('   * * * * * php think crawl:dispatch');
            $output->writeln('   * * * * * php think order:close');
        }

        $output->writeln('');

        if ($this->config['smtp_user'] === '') {
            $output->writeln('<comment>注意：邮件功能未配置，安装后登录后台 /admin → 系统配置 → 邮件配置 在线补充</comment>');
        }
        if ($this->config['pay_pid'] === '') {
            $output->writeln('<comment>注意：支付功能未配置，安装后登录后台 /admin → 系统配置 → 支付配置 在线补充</comment>');
        }

        $output->writeln('');
    }

    /**
     * 检测当前是否交互式 TTY
     */
    private function isInteractive(): bool
    {
        return function_exists('posix_isatty') ? posix_isatty(STDIN) : stream_isatty(STDIN);
    }

    /**
     * 交互式提问(带校验, 循环直到通过)
     *
     * @param \Closure(string): string $validator 校验回调, 返回空串表示通过, 非空串为错误提示
     * @param bool                      $hidden   是否隐藏输入(用于密码)
     */
    protected function askValidated(
        Input $input,
        Output $output,
        string $question,
        string $default,
        \Closure $validator,
        bool $hidden = false
    ): string {
        while (true) {
            $value = $hidden
                ? $this->askHidden($input, $output, $question)
                : $this->ask($input, $output, $question, $default);
            $error = $validator($value);
            if ($error === '') {
                return $value;
            }
            $output->writeln('<comment>' . $error . '</comment>');
        }
    }

    /**
     * 交互式提问(带默认值)
     */
    protected function ask(Input $input, Output $output, string $question, string $default = ''): string
    {
        $prompt = $default !== '' ? $question . ' [' . $default . ']: ' : $question . ': ';
        $answer = $output->ask($input, $prompt, $default);
        return (string) ($answer ?? $default);
    }

    /**
     * 交互式提问(隐藏输入, 用于密码)
     */
    protected function askHidden(Input $input, Output $output, string $question): string
    {
        $answer = $output->askHidden($input, $question . ': ');
        return (string) ($answer ?? '');
    }
}
