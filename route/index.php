<?php
// 前台路由
use think\facade\Route;
use app\index\middleware\UserAuth;

// 首页
Route::get('/', '\app\index\controller\Index/index');

// 搜索
Route::get('/search', '\app\index\controller\Search/index');
Route::get('/ajax/search/hot', '\app\index\controller\Search/hot');

// 资源详情
Route::get('/resource/:id', '\app\index\controller\Resource/detail');
Route::post('/ajax/resource/viewLink/:id', '\app\index\controller\Resource/viewLink');

// 注册登录
Route::get('/auth/login', '\app\index\controller\Auth/login');
Route::get('/auth/register', '\app\index\controller\Auth/register');
Route::post('/ajax/auth/sendCode', '\app\index\controller\Auth/sendCode');
Route::post('/ajax/auth/login', '\app\index\controller\Auth/doLogin');
Route::post('/ajax/auth/register', '\app\index\controller\Auth/doRegister');
Route::get('/auth/logout', '\app\index\controller\Auth/logout');

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
Route::post('/pay/notify', '\app\index\controller\Pay/notify');
Route::get('/pay/return', '\app\index\controller\Pay/return');
Route::group(function () {
    Route::get('/pay/create/:packageId', '\app\index\controller\Pay/create');
})->middleware(UserAuth::class);

// 广告点击上报
Route::get('/ad/click/:id', '\app\index\controller\Ad/click');

// 公共异步接口
Route::post('/ajax/report/:id', '\app\index\controller\Ajax/reportResource');
Route::post('/ajax/adImpression/:id', '\app\index\controller\Ajax/adImpression');
