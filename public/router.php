<?php
// $resource = __DIR__ . '/../public/';
// 用于 PHP 内置服务器: php -S 127.0.0.1:8000 public/router.php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false; // 静态资源直接返回
}
require __DIR__ . '/index.php';
