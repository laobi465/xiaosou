-- ============================================================
-- 轻量网盘资源搜索引擎 - 数据库安装脚本
-- 版本: v1.0  日期: 2026-07-28  数据库: MySQL 8.0
-- 字符集: utf8mb4  引擎: InnoDB
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS pan_search DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pan_search;

-- ============================================================
-- 1. 用户主表
-- ============================================================
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  `email`         VARCHAR(100)    NOT NULL COMMENT '登录邮箱',
  `password`      VARCHAR(255)    DEFAULT NULL COMMENT '密码哈希(BCrypt, 可选)',
  `nickname`      VARCHAR(50)     DEFAULT NULL COMMENT '昵称',
  `avatar`        VARCHAR(255)    DEFAULT NULL COMMENT '头像URL',
  `status`        TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态: 1正常 0封禁',
  `register_ip`   VARCHAR(45)     DEFAULT NULL COMMENT '注册IP',
  `last_login_ip` VARCHAR(45)     DEFAULT NULL COMMENT '最后登录IP',
  `last_login_at` DATETIME        DEFAULT NULL COMMENT '最后登录时间',
  `create_time`   DATETIME        DEFAULT NULL COMMENT '创建时间',
  `update_time`   DATETIME        DEFAULT NULL COMMENT '更新时间',
  `delete_time`   DATETIME        DEFAULT NULL COMMENT '软删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_status_create` (`status`, `create_time`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户主表';

-- ============================================================
-- 2. 用户积分余额表
-- ============================================================
DROP TABLE IF EXISTS `user_credits`;
CREATE TABLE `user_credits` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `balance`        INT NOT NULL DEFAULT 0 COMMENT '当前积分余额',
  `total_recharge` INT NOT NULL DEFAULT 0 COMMENT '累计充值积分',
  `total_consume`  INT NOT NULL DEFAULT 0 COMMENT '累计消耗积分',
  `total_reward`   INT NOT NULL DEFAULT 0 COMMENT '累计奖励积分(签到/提交/赠送)',
  `version`        INT NOT NULL DEFAULT 0 COMMENT '乐观锁版本号',
  `update_time`    DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户积分余额';

-- ============================================================
-- 3. 积分流水表
-- ============================================================
DROP TABLE IF EXISTS `credit_logs`;
CREATE TABLE `credit_logs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `type`          TINYINT UNSIGNED NOT NULL COMMENT '类型: 1充值 2消耗 3签到 4注册赠送 5提交奖励 6管理员调整 7退款',
  `amount`        INT NOT NULL COMMENT '变更数量(正增负减)',
  `balance_after` INT NOT NULL COMMENT '变更后余额',
  `related_id`    BIGINT UNSIGNED DEFAULT NULL COMMENT '关联业务ID(订单/资源等)',
  `remark`        VARCHAR(255)    DEFAULT NULL COMMENT '备注',
  `admin_id`      BIGINT UNSIGNED DEFAULT NULL COMMENT '管理员调整时记录',
  `create_time`   DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_create` (`user_id`, `create_time`),
  KEY `idx_type_create` (`type`, `create_time`),
  KEY `idx_related` (`related_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='积分流水';

-- ============================================================
-- 4. 签到记录表
-- ============================================================
DROP TABLE IF EXISTS `sign_in_records`;
CREATE TABLE `sign_in_records` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         BIGINT UNSIGNED NOT NULL,
  `sign_date`       DATE NOT NULL COMMENT '签到日期',
  `continuous_days` INT NOT NULL DEFAULT 1 COMMENT '当次连续签到天数',
  `credit_amount`   INT NOT NULL DEFAULT 0 COMMENT '本次奖励积分',
  `create_time`     DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_date` (`user_id`, `sign_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='签到记录';

-- ============================================================
-- 5. 用户登录日志
-- ============================================================
DROP TABLE IF EXISTS `user_login_logs`;
CREATE TABLE `user_login_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED DEFAULT NULL COMMENT '用户ID(失败可能为空)',
  `email`       VARCHAR(100)    DEFAULT NULL,
  `ip`          VARCHAR(45)     DEFAULT NULL,
  `user_agent`  VARCHAR(255)    DEFAULT NULL,
  `login_type`  TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '登录方式: 1验证码 2密码',
  `result`      TINYINT UNSIGNED NOT NULL COMMENT '结果: 1成功 0失败',
  `reason`      VARCHAR(100)    DEFAULT NULL COMMENT '失败原因',
  `create_time` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_create` (`user_id`, `create_time`),
  KEY `idx_ip_create` (`ip`, `create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户登录日志';

-- ============================================================
-- 6. 邮箱验证码表(审计, 主流程用 Redis)
-- ============================================================
DROP TABLE IF EXISTS `email_verifies`;
CREATE TABLE `email_verifies` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`       VARCHAR(100)    NOT NULL,
  `code`        VARCHAR(10)     NOT NULL COMMENT '验证码(入库时哈希存储)',
  `type`        TINYINT UNSIGNED NOT NULL COMMENT '类型: 1注册 2登录 3重置密码',
  `ip`          VARCHAR(45)     DEFAULT NULL,
  `expire_at`   DATETIME        NOT NULL,
  `used`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `create_time` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email_type_used` (`email`, `type`, `used`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邮箱验证码';

-- ============================================================
-- 7. 网盘源配置表
-- ============================================================
DROP TABLE IF EXISTS `pan_sources`;
CREATE TABLE `pan_sources` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(50)     NOT NULL COMMENT '显示名: 百度网盘',
  `code`          VARCHAR(20)     NOT NULL COMMENT '代码: baidu',
  `logo`          VARCHAR(255)    DEFAULT NULL,
  `url_pattern`   VARCHAR(500)    DEFAULT NULL COMMENT '链接匹配正则',
  `crawler_class` VARCHAR(100)    DEFAULT NULL COMMENT '采集器类名(含命名空间)',
  `is_mainstream` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1主流 0小众',
  `enabled`       TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
  `sort`          INT NOT NULL DEFAULT 0,
  `create_time`   DATETIME DEFAULT NULL,
  `update_time`   DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_enabled_sort` (`enabled`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='网盘源配置';

-- ============================================================
-- 8. 资源主表
-- ============================================================
DROP TABLE IF EXISTS `resources`;
CREATE TABLE `resources` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`           VARCHAR(255)    NOT NULL COMMENT '资源标题',
  `cover`           VARCHAR(255)    DEFAULT NULL COMMENT '封面图',
  `intro`           TEXT            DEFAULT NULL COMMENT '资源简介',
  `resource_type`   TINYINT UNSIGNED NOT NULL COMMENT '类型: 1影视 2音乐 3软件 4文档 5图片 6压缩包 7其他',
  `file_size`       BIGINT UNSIGNED DEFAULT NULL COMMENT '文件大小(字节)',
  `file_format`     VARCHAR(20)     DEFAULT NULL COMMENT '文件格式: mp4/mp3/exe',
  `source_type`     TINYINT UNSIGNED NOT NULL COMMENT '来源: 1爬虫 2用户提交',
  `submitter_id`    BIGINT UNSIGNED DEFAULT NULL COMMENT '提交者用户ID',
  `status`          TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态: 1正常 0失效 2待审 3驳回',
  `view_count`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '详情浏览数',
  `link_view_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '完整链接查看数',
  `create_time`     DATETIME DEFAULT NULL,
  `update_time`     DATETIME DEFAULT NULL,
  `delete_time`     DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  FULLTEXT KEY `ft_title_intro` (`title`, `intro`) WITH PARSER ngram,
  KEY `idx_type_status_create` (`resource_type`, `status`, `create_time`),
  KEY `idx_status_create` (`status`, `create_time`),
  KEY `idx_submitter` (`submitter_id`),
  KEY `idx_delete_time` (`delete_time`),
  KEY `idx_source_type` (`source_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='资源主表';

-- ============================================================
-- 9. 资源链接表(一个资源可多源)
-- ============================================================
DROP TABLE IF EXISTS `resource_links`;
CREATE TABLE `resource_links` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `resource_id`  BIGINT UNSIGNED NOT NULL,
  `pan_source_id` BIGINT UNSIGNED NOT NULL,
  `share_url`    VARCHAR(500)    NOT NULL COMMENT '分享链接',
  `extract_code` VARCHAR(20)     DEFAULT NULL COMMENT '提取码',
  `url_hash`     CHAR(32)        NOT NULL COMMENT 'MD5(share_url) 去重',
  `status`       TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1有效 0失效',
  `create_time`  DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_url_hash` (`url_hash`),
  KEY `idx_resource` (`resource_id`),
  KEY `idx_pan_source` (`pan_source_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='资源链接';

-- ============================================================
-- 10. 用户提交记录表
-- ============================================================
DROP TABLE IF EXISTS `submissions`;
CREATE TABLE `submissions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `title`         VARCHAR(255)    NOT NULL,
  `share_url`     VARCHAR(500)    NOT NULL,
  `extract_code`  VARCHAR(20)     DEFAULT NULL,
  `pan_source_id` BIGINT UNSIGNED NOT NULL,
  `resource_type` TINYINT UNSIGNED NOT NULL,
  `intro`         TEXT            DEFAULT NULL,
  `cover`         VARCHAR(255)    DEFAULT NULL,
  `status`        TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0待审 1通过 2驳回',
  `reject_reason` VARCHAR(255)    DEFAULT NULL,
  `reviewer_id`   BIGINT UNSIGNED DEFAULT NULL,
  `review_at`     DATETIME        DEFAULT NULL,
  `resource_id`   BIGINT UNSIGNED DEFAULT NULL COMMENT '通过后生成的 resources.id',
  `create_time`   DATETIME DEFAULT NULL,
  `update_time`   DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_status_create` (`status`, `create_time`),
  KEY `idx_pan_source` (`pan_source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户提交记录';

-- ============================================================
-- 11. 资源失效举报
-- ============================================================
DROP TABLE IF EXISTS `resource_reports`;
CREATE TABLE `resource_reports` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `resource_id` BIGINT UNSIGNED NOT NULL,
  `link_id`     BIGINT UNSIGNED DEFAULT NULL COMMENT '具体失效链接ID',
  `user_id`     BIGINT UNSIGNED DEFAULT NULL COMMENT '举报人(游客为空)',
  `ip`          VARCHAR(45)     DEFAULT NULL,
  `reason`      VARCHAR(255)    DEFAULT NULL,
  `status`      TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0待处理 1已确认失效 2已忽略',
  `handler_id`  BIGINT UNSIGNED DEFAULT NULL COMMENT '处理管理员',
  `handle_at`   DATETIME        DEFAULT NULL,
  `create_time` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_resource_status` (`resource_id`, `status`),
  KEY `idx_status_create` (`status`, `create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='资源失效举报';

-- ============================================================
-- 12. 采集任务表
-- ============================================================
DROP TABLE IF EXISTS `crawl_tasks`;
CREATE TABLE `crawl_tasks` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100)    NOT NULL COMMENT '任务名',
  `pan_source_id` BIGINT UNSIGNED NOT NULL,
  `keywords`    VARCHAR(500)    DEFAULT NULL COMMENT '采集关键词(逗号分隔)',
  `frequency`   INT NOT NULL DEFAULT 60 COMMENT '执行间隔(分钟)',
  `enabled`     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `last_run_at` DATETIME        DEFAULT NULL,
  `next_run_at` DATETIME        DEFAULT NULL,
  `create_time` DATETIME DEFAULT NULL,
  `update_time` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_enabled_next` (`enabled`, `next_run_at`),
  KEY `idx_pan_source` (`pan_source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='采集任务';

-- ============================================================
-- 13. 采集日志表(按月分表预留)
-- ============================================================
DROP TABLE IF EXISTS `crawl_logs`;
CREATE TABLE `crawl_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`     BIGINT UNSIGNED NOT NULL,
  `pan_source_id` BIGINT UNSIGNED NOT NULL,
  `status`      TINYINT UNSIGNED NOT NULL COMMENT '1成功 0失败',
  `found_count` INT NOT NULL DEFAULT 0 COMMENT '本轮发现',
  `new_count`   INT NOT NULL DEFAULT 0 COMMENT '新增入库',
  `error_msg`   TEXT            DEFAULT NULL,
  `duration_ms` INT UNSIGNED    DEFAULT NULL,
  `create_time` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_task_create` (`task_id`, `create_time`),
  KEY `idx_status_create` (`status`, `create_time`),
  KEY `idx_pan_source_create` (`pan_source_id`, `create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='采集日志';

-- ============================================================
-- 14. 搜索日志表
-- ============================================================
DROP TABLE IF EXISTS `search_logs`;
CREATE TABLE `search_logs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `keyword`      VARCHAR(255)    NOT NULL,
  `user_id`      BIGINT UNSIGNED DEFAULT NULL COMMENT '游客为空',
  `ip`           VARCHAR(45)     DEFAULT NULL,
  `result_count` INT NOT NULL DEFAULT 0,
  `duration_ms`  INT UNSIGNED    DEFAULT NULL,
  `filters`      VARCHAR(500)    DEFAULT NULL COMMENT '筛选条件JSON',
  `create_time`  DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_keyword_create` (`keyword`, `create_time`),
  KEY `idx_user_create` (`user_id`, `create_time`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='搜索日志';

-- ============================================================
-- 15. 热搜词归档表
-- ============================================================
DROP TABLE IF EXISTS `hot_keywords`;
CREATE TABLE `hot_keywords` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `keyword`    VARCHAR(255)    NOT NULL,
  `stat_date`  DATE NOT NULL,
  `search_cnt` INT UNSIGNED NOT NULL DEFAULT 0,
  `create_time` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_keyword_date` (`keyword`, `stat_date`),
  KEY `idx_date_cnt` (`stat_date`, `search_cnt` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='热搜词归档';

-- ============================================================
-- 16. 积分套餐表
-- ============================================================
DROP TABLE IF EXISTS `credit_packages`;
CREATE TABLE `credit_packages` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(50)     NOT NULL COMMENT '套餐名',
  `price`          DECIMAL(10,2)   NOT NULL COMMENT '价格(元)',
  `credits`        INT UNSIGNED    NOT NULL COMMENT '基础积分',
  `bonus`          INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '赠送积分',
  `is_recommended` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `status`         TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1上架 0下架',
  `sort`           INT NOT NULL DEFAULT 0,
  `create_time`    DATETIME DEFAULT NULL,
  `update_time`    DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='积分套餐';

-- ============================================================
-- 17. 订单表
-- ============================================================
DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no`   VARCHAR(32)     NOT NULL COMMENT '本站订单号',
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `package_id` BIGINT UNSIGNED DEFAULT NULL,
  `amount`     DECIMAL(10,2)   NOT NULL COMMENT '实付金额',
  `credits`    INT UNSIGNED    NOT NULL COMMENT '应到积分(基础+赠送)',
  `status`     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0待支付 1已支付 2已退款 3已关闭',
  `pay_type`   VARCHAR(20)     DEFAULT NULL COMMENT 'alipay/wechat/qq',
  `trade_no`   VARCHAR(64)     DEFAULT NULL COMMENT '彩虹易支付流水号',
  `pay_at`     DATETIME        DEFAULT NULL,
  `expire_at`  DATETIME        NOT NULL COMMENT '订单过期时间(默认30分钟)',
  `refund_at`  DATETIME        DEFAULT NULL,
  `refund_reason` VARCHAR(255) DEFAULT NULL,
  `create_time` DATETIME DEFAULT NULL,
  `update_time` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_status_create` (`status`, `create_time`),
  KEY `idx_expire` (`expire_at`),
  KEY `idx_trade_no` (`trade_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单';

-- ============================================================
-- 18. 支付日志表
-- ============================================================
DROP TABLE IF EXISTS `payment_logs`;
CREATE TABLE `payment_logs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no`     VARCHAR(32)     NOT NULL,
  `event`        VARCHAR(20)     NOT NULL COMMENT 'create/notify/sync/refund',
  `request_data` MEDIUMTEXT      DEFAULT NULL COMMENT '原始请求/回调',
  `response_data` MEDIUMTEXT     DEFAULT NULL,
  `ip`           VARCHAR(45)     DEFAULT NULL,
  `create_time`  DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order_event` (`order_no`, `event`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='支付日志';

-- ============================================================
-- 19. 广告位表
-- ============================================================
DROP TABLE IF EXISTS `ad_slots`;
CREATE TABLE `ad_slots` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(30)     NOT NULL COMMENT 'home_banner/search_top/detail_popup/bottom_float',
  `name`        VARCHAR(50)     NOT NULL,
  `description` VARCHAR(255)    DEFAULT NULL,
  `max_count`   TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '同时展示上限',
  `enabled`     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `create_time` DATETIME DEFAULT NULL,
  `update_time` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='广告位';

-- ============================================================
-- 20. 广告投放表
-- ============================================================
DROP TABLE IF EXISTS `ad_placements`;
CREATE TABLE `ad_placements` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slot_id`     BIGINT UNSIGNED NOT NULL,
  `title`       VARCHAR(100)    NOT NULL,
  `image_url`   VARCHAR(255)    NOT NULL,
  `link_url`    VARCHAR(500)    NOT NULL,
  `start_at`    DATETIME        NOT NULL,
  `end_at`      DATETIME        NOT NULL,
  `weight`      INT NOT NULL DEFAULT 1,
  `status`      TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1上线 0下线',
  `impressions` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `clicks`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `create_time` DATETIME DEFAULT NULL,
  `update_time` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_slot_status` (`slot_id`, `status`),
  KEY `idx_status_time` (`status`, `start_at`, `end_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='广告投放';

-- ============================================================
-- 21. 广告统计表(按天聚合)
-- ============================================================
DROP TABLE IF EXISTS `ad_stats`;
CREATE TABLE `ad_stats` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `placement_id` BIGINT UNSIGNED NOT NULL,
  `stat_date`    DATE NOT NULL,
  `impressions`  INT UNSIGNED NOT NULL DEFAULT 0,
  `clicks`       INT UNSIGNED NOT NULL DEFAULT 0,
  `create_time`  DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_placement_date` (`placement_id`, `stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='广告统计';

-- ============================================================
-- 22. 系统配置表(KV)
-- ============================================================
DROP TABLE IF EXISTS `system_configs`;
CREATE TABLE `system_configs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group`       VARCHAR(30)     NOT NULL COMMENT 'smtp/payment/site/credit/security',
  `key`         VARCHAR(50)     NOT NULL,
  `value`       TEXT            DEFAULT NULL,
  `remark`      VARCHAR(255)    DEFAULT NULL,
  `update_time` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`key`),
  KEY `idx_group` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统配置';

-- ============================================================
-- 23. 管理员表
-- ============================================================
DROP TABLE IF EXISTS `admin_users`;
CREATE TABLE `admin_users` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)     NOT NULL,
  `password`      VARCHAR(255)    NOT NULL COMMENT 'BCrypt 哈希',
  `nickname`      VARCHAR(50)     DEFAULT NULL,
  `last_login_ip` VARCHAR(45)     DEFAULT NULL,
  `last_login_at` DATETIME        DEFAULT NULL,
  `status`        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `create_time`   DATETIME DEFAULT NULL,
  `update_time`   DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员';

-- ============================================================
-- 24. 管理员操作日志
-- ============================================================
DROP TABLE IF EXISTS `admin_logs`;
CREATE TABLE `admin_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`    BIGINT UNSIGNED NOT NULL,
  `module`      VARCHAR(30)     NOT NULL COMMENT '模块: resource/user/order/ad/config',
  `action`      VARCHAR(50)     NOT NULL COMMENT '动作: create/update/delete',
  `target_id`   BIGINT UNSIGNED DEFAULT NULL,
  `detail`      TEXT            DEFAULT NULL COMMENT '变更前后JSON',
  `ip`          VARCHAR(45)     DEFAULT NULL,
  `user_agent`  VARCHAR(255)    DEFAULT NULL,
  `create_time` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_admin_create` (`admin_id`, `create_time`),
  KEY `idx_module_action` (`module`, `action`),
  KEY `idx_target` (`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员操作日志';

-- ============================================================
-- 25. 敏感词表
-- ============================================================
DROP TABLE IF EXISTS `sensitive_words`;
CREATE TABLE `sensitive_words` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `word`        VARCHAR(100)    NOT NULL,
  `category`    VARCHAR(30)     DEFAULT NULL COMMENT '分类: 政治/色情/广告/暴力',
  `replace`     VARCHAR(50)     DEFAULT NULL COMMENT '替换词, 为空则拒绝',
  `status`      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `create_time` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_word` (`word`),
  KEY `idx_status_category` (`status`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='敏感词';

-- ============================================================
-- 26. 站内通知表
-- ============================================================
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `type`        TINYINT UNSIGNED NOT NULL COMMENT '1系统 2提交审核 3积分变动 4订单',
  `title`       VARCHAR(100)    NOT NULL,
  `content`     TEXT            NOT NULL,
  `link`        VARCHAR(255)    DEFAULT NULL,
  `is_read`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `create_time` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_read_create` (`user_id`, `is_read`, `create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='站内通知';

-- ============================================================
-- 27. 失败任务表(think-queue failed_jobs)
-- ============================================================
DROP TABLE IF EXISTS `failed_jobs`;
CREATE TABLE `failed_jobs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `connection` TEXT NOT NULL,
  `queue`      TEXT NOT NULL,
  `payload`    LONGTEXT NOT NULL,
  `exception`  LONGTEXT NOT NULL,
  `failed_at`  DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='失败任务';

SET FOREIGN_KEY_CHECKS = 1;
