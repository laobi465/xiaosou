<?php
// API 路由
use think\facade\Route;

// 健康检查
Route::get('/api/health', '\app\api\controller\Index/health');
