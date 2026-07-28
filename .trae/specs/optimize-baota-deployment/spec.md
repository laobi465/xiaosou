# 优化宝塔面板部署 Spec

## Why
当前宝塔面板部署体验弱于 Docker 路径：教程纯手动 866 行无快速入口；`php think install` 向导无连接预检（写错 .env 后才发现）、无安装后自检（不验证表/Redis/管理员）、`--force` 无确认易误覆盖、SMTP/支付强制必填（无法快速体验）。需在不新增脚本的前提下，让宝塔部署更顺、更不易错。

## What Changes
- 优化 `app/command/Install.php` 安装向导：增加连接预检、安装后自检、`--force` 确认、SMTP/支付可跳过、输入校验、友好下一步指引
- 优化 `docs/宝塔面板部署教程.md`：顶部新增"5 分钟快速入口"、新增"安装前预检"章节、改写 4.6 安装向导说明、FAQ 补充 install 相关问题、Checklist 同步
- 优化 `README.md` "方式二：宝塔面板部署"区块：突出适用场景、加快速入口、链接教程快速入口章节

## Impact
- Affected specs: 无既有 spec（首次创建）
- Affected code:
  - `app/command/Install.php`（核心改动）
  - `docs/宝塔面板部署教程.md`（文档改动）
  - `README.md`（介绍区块改动）

## ADDED Requirements

### Requirement: 安装向导连接预检
The system SHALL 在写入 .env 前，先使用用户输入的 MySQL 与 Redis 配置测试连接，连接失败时提示原因并允许重新输入，避免写入错误的 .env。

#### Scenario: MySQL 连接失败
- **WHEN** 用户输入的数据库主机/端口/用户名/密码无法连接
- **THEN** 输出具体错误（如 Connection refused / Access denied），提示重新输入数据库配置，不写 .env

#### Scenario: Redis 连接失败
- **WHEN** 用户输入的 Redis 主机/端口/密码无法连接
- **THEN** 输出警告，询问是否继续（Redis 非安装必需，可后续配置），继续则写 .env 但标记 Redis 未就绪

### Requirement: 安装向导安装后自检
The system SHALL 在执行完 SQL 与预热缓存后，自动验证安装结果：表数量、Redis ping、管理员账号可查询，输出自检报告。

#### Scenario: 自检全部通过
- **WHEN** 数据库表数量 == 27、Redis ping 成功、管理员账号存在
- **THEN** 输出绿色自检报告，提示安装成功

#### Scenario: 表数量不符
- **WHEN** 数据库表数量 != 27
- **THEN** 输出警告，提示 install.sql 可能未完整执行，建议检查 database/install.sql

### Requirement: 安装向导 SMTP/支付可跳过
The system SHALL 允许用户在安装时跳过 SMTP 邮件与彩虹易支付配置（直接回车留空），安装正常完成，后续可手动编辑 .env 补充。

#### Scenario: 跳过 SMTP
- **WHEN** 用户在 SMTP 用户名处直接回车留空
- **THEN** SMTP 段写入空值，安装继续，完成后提示"邮件功能未配置，后续编辑 .env [MAIL] 段"

### Requirement: 安装向导 --force 确认
The system SHALL 在使用 `--force` 覆盖已存在 .env 前，增加二次确认提示（输入 y 继续），避免误覆盖；非交互环境（管道输入）自动跳过确认。

#### Scenario: 交互式 --force
- **WHEN** 用户执行 `php think install --force` 且 .env 已存在，且 stdin 为 TTY
- **THEN** 提示"确认覆盖 .env？[y/N]"，输入 y 才继续

#### Scenario: 非交互式 --force
- **WHEN** stdin 非 TTY（如管道/脚本调用）
- **THEN** 直接覆盖，不询问

### Requirement: 安装向导输入校验
The system SHALL 对端口、邮箱、密码长度进行基础校验，校验失败提示重新输入。

#### Scenario: 端口非法
- **WHEN** 用户输入数据库端口非数字或超出 1-65535
- **THEN** 提示"端口必须为 1-65535 的数字"，重新输入

### Requirement: 宝塔教程快速入口
The system SHALL 在宝塔部署教程顶部提供"5 分钟快速入口"，列出极简流程（环境就绪 → clone → composer install → php think install → 配伪静态 → 上线），每步链接到详细章节。

### Requirement: 宝塔教程安装前预检
The system SHALL 在教程"4.6 执行一键安装"前新增"安装前预检"章节，列出 PHP 版本/扩展/MySQL ngram/Redis 连通性的检查命令与预期输出。

### Requirement: README 宝塔区块快速入口
The system SHALL 在 README "方式二：宝塔面板部署"区块突出适用场景（已有宝塔环境、不用 Docker），并提供 5 步快速入口链接到教程快速入口章节。

## MODIFIED Requirements

### Requirement: 安装向导执行流程
[原：交互收集 → 写 .env → 执行 SQL → 建管理员 → 预热缓存 → 输出后续步骤]
修改为：交互收集（含校验）→ **连接预检** → 写 .env → 执行 SQL → 建管理员 → 预热缓存 → **安装后自检** → 输出友好下一步指引（区分宝塔/通用）。

### Requirement: 宝塔教程 4.6 章节
[原：仅列出 `php think install` 交互提示示例]
修改为：补充 install 命令的预检/自检行为说明、`--force` 用法与确认机制、跳过 SMTP/支付的说明、连接失败重试方式。

## REMOVED Requirements
无（本次为优化，不删除现有功能）
