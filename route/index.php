<?php
// 前台路由
use think\facade\Route;
use app\index\middleware\UserAuth;
use app\index\middleware\VisitorLog;
use app\index\middleware\RateLimit;
use app\index\middleware\PayIpWhitelist;

// 安装向导(安装完成后基于 install.lock 自动禁用,无需 CSRF/LoadConfig)
Route::get('/install', '\app\index\controller\Install/index')
    ->option(['csrf_skip' => true, 'skip_load_config' => true]);
Route::post('/install/ajax/:step', '\app\index\controller\Install/ajax')
    ->option(['csrf_skip' => true, 'skip_load_config' => true]);

// 首页
Route::get('/', '\app\index\controller\Index/index')->middleware(VisitorLog::class);

// 搜索
Route::get('/search', '\app\index\controller\Search/index')->middleware(VisitorLog::class)->middleware(RateLimit::class, '60');
Route::get('/ajax/search/hot', '\app\index\controller\Search/hot');

// 资源详情
Route::get('/resource/:id', '\app\index\controller\Resource/detail')->middleware(VisitorLog::class);
Route::post('/ajax/resource/viewLink/:id', '\app\index\controller\Resource/viewLink')->middleware(UserAuth::class)->middleware(RateLimit::class, '20');

// 注册登录
Route::get('/auth/login', '\app\index\controller\Auth/login');
Route::get('/auth/register', '\app\index\controller\Auth/register');
Route::post('/ajax/auth/sendCode', '\app\index\controller\Auth/sendCode')->middleware(RateLimit::class, '5', '60', 'email');
Route::post('/ajax/auth/login', '\app\index\controller\Auth/doLogin')->middleware(RateLimit::class, '10');
Route::post('/ajax/auth/register', '\app\index\controller\Auth/doRegister')->middleware(RateLimit::class, '5');
Route::post('/auth/logout', '\app\index\controller\Auth/logout');

// 用户中心(需登录)
Route::group(function () {
    Route::get('/user', '\app\index\controller\User/index');
    Route::get('/user/credits', '\app\index\controller\User/credits');
    Route::get('/user/orders', '\app\index\controller\User/orders');
    // 用户中心 Ajax 接口
    Route::post('/ajax/user/signIn', '\app\index\controller\User/signIn');
    Route::post('/ajax/user/profile', '\app\index\controller\User/profile');
})->middleware(UserAuth::class);

// 资源提交(需登录)
Route::group(function () {
    Route::get('/submit', '\app\index\controller\Submit/index');
    Route::post('/ajax/submit/create', '\app\index\controller\Submit/create');
    Route::get('/submit/myList', '\app\index\controller\Submit/myList');
})->middleware(UserAuth::class);

// 订单/套餐
Route::get('/order/packages', '\app\index\controller\Order/packages');
Route::group(function () {
    Route::get('/order/myList', '\app\index\controller\Order/myList');
    Route::get('/order/:id', '\app\index\controller\Order/detail');
})->middleware(UserAuth::class);

// 彩虹易支付
Route::post('/pay/notify', '\app\index\controller\Pay/notify')
    ->middleware(PayIpWhitelist::class)
    ->option(['csrf_skip' => true]);
Route::get('/pay/return', '\app\index\controller\Pay/return');
Route::group(function () {
    Route::get('/pay/create/:packageId', '\app\index\controller\Pay/create');
})->middleware(UserAuth::class);

// 广告点击上报
Route::get('/ad/click/:id', '\app\index\controller\Ad/click')->middleware(RateLimit::class, '30');

// 公共异步接口
Route::post('/ajax/report/:id', '\app\index\controller\Ajax/reportResource')->middleware(UserAuth::class)->middleware(RateLimit::class, '10');
Route::post('/ajax/adImpression/:id', '\app\index\controller\Ajax/adImpression')->middleware(RateLimit::class, '30');
