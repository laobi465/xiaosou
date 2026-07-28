-- ============================================================
-- 轻量网盘资源搜索引擎 - 种子数据脚本
-- 版本: v1.0  日期: 2026-07-28
-- 依赖: 先执行 install.sql 创建表结构
-- ============================================================

USE pan_search;

-- ============================================================
-- 1. 网盘源(15 家)
-- ============================================================
INSERT INTO `pan_sources` (`name`, `code`, `logo`, `url_pattern`, `crawler_class`, `is_mainstream`, `enabled`, `sort`, `create_time`, `update_time`) VALUES
('百度网盘', 'baidu', '/static/img/pan/baidu.png', '^https?://pan\\.baidu\\.com/s/', 'app\\common\\crawler\\BaiduCrawler', 1, 1, 1, NOW(), NOW()),
('阿里云盘', 'aliyun', '/static/img/pan/aliyun.png', '^https?://www\\.alipan\\.com/s/', 'app\\common\\crawler\\AliyunCrawler', 1, 1, 2, NOW(), NOW()),
('夸克网盘', 'quark', '/static/img/pan/quark.png', '^https?://pan\\.quark\\.cn/s/', 'app\\common\\crawler\\QuarkCrawler', 1, 1, 3, NOW(), NOW()),
('迅雷云盘', 'xunlei', '/static/img/pan/xunlei.png', '^https?://pan\\.xunlei\\.com/s/', 'app\\common\\crawler\\XunleiCrawler', 1, 1, 4, NOW(), NOW()),
('123云盘', 'pan123', '/static/img/pan/123.png', '^https?://www\\.123pan\\.com/s/', 'app\\common\\crawler\\Pan123Crawler', 1, 1, 5, NOW(), NOW()),
('115网盘', 'pan115', '/static/img/pan/115.png', '^https?://115\\.com/s/', 'app\\common\\crawler\\Pan115Crawler', 1, 1, 6, NOW(), NOW()),
('蓝奏云', 'lanzou', '/static/img/pan/lanzou.png', '^https?://[a-z]+\\.lanzou[a-z]*\\.', 'app\\common\\crawler\\LanzouCrawler', 0, 1, 7, NOW(), NOW()),
('天翼云盘', 'ecloud', '/static/img/pan/ecloud.png', '^https?://cloud\\.189\\.cn/', 'app\\common\\crawler\\EcloudCrawler', 0, 1, 8, NOW(), NOW()),
('移动云盘', 'yidong', '/static/img/pan/yidong.png', '^https?://yun\\.139\\.com/', 'app\\common\\crawler\\YidongCrawler', 0, 1, 9, NOW(), NOW()),
('腾讯微云', 'weiyun', '/static/img/pan/weiyun.png', '^https?://share\\.weiyun\\.com/', 'app\\common\\crawler\\WeiyunCrawler', 0, 1, 10, NOW(), NOW()),
('UC网盘', 'uc', '/static/img/pan/uc.png', '^https?://drive\\.uc\\.cn/', 'app\\common\\crawler\\UcCrawler', 0, 1, 11, NOW(), NOW()),
('芒果云', 'mango', '/static/img/pan/mango.png', '^https?://pan\\.mangox\\.net/', 'app\\common\\crawler\\MangoCrawler', 0, 1, 12, NOW(), NOW()),
('奶牛快传', 'cowtransfer', '/static/img/pan/cowtransfer.png', '^https?://cowtransfer\\.com/', 'app\\common\\crawler\\CowtransferCrawler', 0, 1, 13, NOW(), NOW()),
('曲奇云盘', 'cookie', '/static/img/pan/cookie.png', '^https?://pan\\.quark\\.cn/share/', 'app\\common\\crawler\\CookieCrawler', 0, 1, 14, NOW(), NOW()),
('Firefox Send', 'firefoxsend', '/static/img/pan/firefoxsend.png', '^https?://send\\.firefox\\.com/', 'app\\common\\crawler\\FirefoxSendCrawler', 0, 0, 15, NOW(), NOW());

-- ============================================================
-- 2. 广告位(4 个)
-- ============================================================
INSERT INTO `ad_slots` (`code`, `name`, `description`, `max_count`, `enabled`, `create_time`, `update_time`) VALUES
('home_banner',    '首页 Banner',   '首页顶部轮播图', 3, 1, NOW(), NOW()),
('search_top',     '搜索置顶',      '搜索结果顶部置顶位', 2, 1, NOW(), NOW()),
('detail_popup',   '详情弹窗',      '资源详情页弹窗', 1, 1, NOW(), NOW()),
('bottom_float',   '底部浮动',      '页面右下角悬浮', 1, 1, NOW(), NOW());

-- ============================================================
-- 3. 积分套餐(4 档)
-- ============================================================
INSERT INTO `credit_packages` (`name`, `price`, `credits`, `bonus`, `is_recommended`, `status`, `sort`, `create_time`, `update_time`) VALUES
('体验包',  10.00,  100,   0, 0, 1, 1, NOW(), NOW()),
('标准包',  50.00,  600,  50, 1, 1, 2, NOW(), NOW()),
('超值包', 100.00, 1500, 200, 0, 1, 3, NOW(), NOW()),
('土豪包', 300.00, 5000, 800, 0, 1, 4, NOW(), NOW());

-- ============================================================
-- 4. 系统配置(默认值, 敏感字段占位待 .env 覆盖)
-- ============================================================
INSERT INTO `system_configs` (`group`, `key`, `value`, `remark`, `update_time`) VALUES
-- SMTP
('smtp', 'smtp_host',           'smtp.qq.com',     'SMTP 主机', NOW()),
('smtp', 'smtp_port',           '465',             'SMTP 端口', NOW()),
('smtp', 'smtp_user',           '',                '发件账号', NOW()),
('smtp', 'smtp_pass',           '',                '授权码(AES加密)', NOW()),
('smtp', 'smtp_from_name',      '网盘搜索',         '发件人名称', NOW()),
('smtp', 'smtp_encryption',     'ssl',             '加密方式: ssl/tls/none', NOW()),
-- 支付
('payment', 'caihong_pid',      '',                '彩虹易支付商户ID', NOW()),
('payment', 'caihong_key',      '',                '彩虹易支付密钥(实际存.env)', NOW()),
('payment', 'caihong_api',      'https://pay.cccdl.com', '接口地址', NOW()),
('payment', 'notify_url',       '',                '异步通知URL(部署后填)', NOW()),
('payment', 'return_url',       '',                '同步跳转URL(部署后填)', NOW()),
-- 站点
('site', 'site_name',           '网盘资源搜索',     '站点名', NOW()),
('site', 'site_logo',           '/static/img/logo.png', 'Logo', NOW()),
('site', 'site_icp',            '',                '备案号', NOW()),
('site', 'site_seo_title',      '网盘资源搜索 - 一键搜全网网盘', 'SEO 标题', NOW()),
('site', 'site_seo_keywords',   '网盘搜索,百度网盘,阿里云盘,夸克网盘', 'SEO 关键词', NOW()),
('site', 'site_seo_description','聚合15+网盘资源,一键搜索', 'SEO 描述', NOW()),
-- 积分
('credit', 'credit_register_gift',     '10', '注册赠送', NOW()),
('credit', 'credit_sign_in',           '1',  '签到奖励', NOW()),
('credit', 'credit_sign_in_continuous','5',  '连续7天额外', NOW()),
('credit', 'credit_view_link',         '1',  '查看链接消耗', NOW()),
('credit', 'credit_submit_reward',     '5',  '提交通过奖励', NOW()),
-- 安全
('security', 'rate_search_per_min',         '60', '搜索限流/分', NOW()),
('security', 'rate_verify_per_ip_10min',    '5',  '验证码/IP/10分', NOW()),
('security', 'ip_blacklist',                '',   'IP 黑名单', NOW()),
('security', 'sensitive_filter_enabled',    '1',  '敏感词开关', NOW());

-- ============================================================
-- 5. 默认管理员(密码: admin123, BCrypt 哈希, 部署后请立即修改)
-- ============================================================
INSERT INTO `admin_users` (`username`, `password`, `nickname`, `status`, `create_time`, `update_time`) VALUES
('admin', '$2y$12$N9qo8uLOickgx2ZMRZoMy.MrqKMTBQ8Kv0qM6VwJmDqU0qYq8BzWu', '超级管理员', 1, NOW(), NOW());

-- ============================================================
-- 6. 默认敏感词(占位, 实际使用需导入完整词库)
-- ============================================================
INSERT INTO `sensitive_words` (`word`, `category`, `replace`, `status`, `create_time`) VALUES
('示例敏感词1', '其他', '***', 1, NOW()),
('示例敏感词2', '其他', '***', 1, NOW());

-- ============================================================
-- 7. 示例采集任务(可选, 默认禁用, 部署后按需开启)
-- ============================================================
INSERT INTO `crawl_tasks` (`name`, `pan_source_id`, `keywords`, `frequency`, `enabled`, `next_run_at`, `create_time`, `update_time`)
SELECT '百度网盘-影视', id, '电影,电视剧,综艺', 60, 0, NULL, NOW(), NOW() FROM pan_sources WHERE code='baidu';
