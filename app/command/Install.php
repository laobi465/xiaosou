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

        // 1. 交互式收集配置
        $this->collectConfig($input, $output);

        // 2. 写入 .env 文件
        $this->writeEnvFile($rootPath);
        $output->writeln('<info>[✓] .env 配置文件已写入</info>');

        // 3. 执行 SQL 建库建表 + 种子数据
        $this->executeSql($rootPath, $output);

        // 4. 创建/更新管理员账号
        $this->setupAdmin($output);

        // 5. 预热 Redis 缓存
        $this->warmupCache($output);

        $output->writeln('');
        $output->writeln('<info>========================================</info>');
        $output->writeln('<info>   安装完成!</info>');
        $output->writeln('<info>========================================</info>');
        $output->writeln('');
        $output->writeln('后续步骤:');
        $output->writeln('  1. 配置 Nginx 指向 public/');
        $output->writeln('  2. 配置 supervisor 守护队列:');
        $output->writeln('     php think crawl:consume');
        $output->writeln('     php think mail:consume');
        $output->writeln('  3. 配置 crontab 定时任务:');
        $output->writeln('     * * * * * php think crawl:dispatch');
        $output->writeln('     * * * * * php think order:close');
        $output->writeln('     0 * * * * php think ad:agg');
        $output->writeln('');
    }

    /**
     * 交互式收集配置
     */
    protected function collectConfig(Input $input, Output $output): void
    {
        $output->writeln('<comment>--- 数据库配置 ---</comment>');
        $this->config['db_host']     = $this->ask($input, $output, '数据库主机', '127.0.0.1');
        $this->config['db_port']     = $this->ask($input, $output, '数据库端口', '3306');
        $this->config['db_name']     = $this->ask($input, $output, '数据库名', 'pan_search');
        $this->config['db_user']     = $this->ask($input, $output, '数据库用户名', 'root');
        $this->config['db_password'] = $this->askHidden($input, $output, '数据库密码');

        $output->writeln('');
        $output->writeln('<comment>--- Redis 配置 ---</comment>');
        $this->config['redis_host']     = $this->ask($input, $output, 'Redis 主机', '127.0.0.1');
        $this->config['redis_port']     = $this->ask($input, $output, 'Redis 端口', '6379');
        $this->config['redis_password'] = $this->askHidden($input, $output, 'Redis 密码(空则回车)');

        $output->writeln('');
        $output->writeln('<comment>--- SMTP 邮件配置 ---</comment>');
        $this->config['smtp_host']       = $this->ask($input, $output, 'SMTP 主机', 'smtp.qq.com');
        $this->config['smtp_port']       = $this->ask($input, $output, 'SMTP 端口', '465');
        $this->config['smtp_user']       = $this->ask($input, $output, 'SMTP 用户名(邮箱)', '');
        $this->config['smtp_pass']       = $this->askHidden($input, $output, 'SMTP 密码/授权码');
        $this->config['smtp_from_name']  = $this->ask($input, $output, '发件人名称', '网盘搜索');

        $output->writeln('');
        $output->writeln('<comment>--- 彩虹易支付配置 ---</comment>');
        $this->config['pay_pid']    = $this->ask($input, $output, '商户 PID', '');
        $this->config['pay_key']    = $this->askHidden($input, $output, '商户密钥 KEY');
        $this->config['pay_api']    = $this->ask($input, $output, '支付接口地址', 'https://pay.cccdl.com');

        $output->writeln('');
        $output->writeln('<comment>--- 管理员账号 ---</comment>');
        $this->config['admin_user']     = $this->ask($input, $output, '管理员用户名', 'admin');
        $this->config['admin_password'] = $this->askHidden($input, $output, '管理员密码');

        $output->writeln('');
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
