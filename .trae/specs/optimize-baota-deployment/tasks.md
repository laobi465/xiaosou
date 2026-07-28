# Tasks

- [x] Task 1: 优化 Install.php 安装向导核心逻辑
  - [x] SubTask 1.1: 增加输入校验（端口 1-65535 数字、邮箱格式、密码长度 ≥6）
  - [x] SubTask 1.2: 增加 MySQL 连接预检（写 .env 前测试连接，失败允许重输）
  - [x] SubTask 1.3: 增加 Redis 连接预检（失败询问是否继续）
  - [x] SubTask 1.4: SMTP/支付允许留空跳过（输入空值不报错，完成后提示）
  - [x] SubTask 1.5: `--force` 增加交互式二次确认（TTY 检测，非 TTY 跳过）
  - [x] SubTask 1.6: 增加安装后自检（表数量==27、Redis ping、管理员存在）
  - [x] SubTask 1.7: 完成后输出友好下一步指引（区分宝塔/通用场景）

- [x] Task 2: 优化 docs/宝塔面板部署教程.md
  - [x] SubTask 2.1: 顶部新增"5 分钟快速入口"章节（5 步极简流程 + 锚点链接）
  - [x] SubTask 2.2: 在 4.6 前新增"4.6 安装前预检"章节（PHP/扩展/MySQL ngram/Redis 检查命令）
  - [x] SubTask 2.3: 改写 4.7（原 4.6）"执行一键安装"章节（补充预检/自检行为、--force、跳过 SMTP/支付、失败重试）
  - [x] SubTask 2.4: FAQ 补充 3 个 install 相关问题（连接失败重试、--force 注意事项、安装后 419 完整解法）
  - [x] SubTask 2.5: 附录 Checklist 同步（增加预检项）

- [x] Task 3: 优化 README.md 宝塔区块
  - [x] SubTask 3.1: "方式二：宝塔面板部署"区块加适用场景说明（已有宝塔环境/不用 Docker）
  - [x] SubTask 3.2: 加 5 步快速入口（含链接到教程快速入口章节）

- [x] Task 4: 验证与提交
  - [x] SubTask 4.1: `php -l app/command/Install.php` 语法校验
  - [x] SubTask 4.2: 模拟运行 install 命令验证交互流程（如环境允许）— 跳过（无 MySQL/Redis 环境无法模拟运行，以子代理逻辑审查 + 语法校验为准）
  - [x] SubTask 4.3: git commit + push origin main

# Task Dependencies
- Task 2 依赖 Task 1（教程需描述 Install.php 的新行为）
- Task 3 可与 Task 2 并行
- Task 4 依赖 Task 1/2/3 全部完成
