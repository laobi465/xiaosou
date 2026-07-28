<?php
namespace think;

require __DIR__ . '/../vendor/autoload.php';

// 实例化应用并执行 HTTP
$http = (new App())->http;

$response = $http->run();

$response->send();

$http->end($response);
