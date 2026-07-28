<?php
// 后台路由
use think\facade\Route;
use app\admin\middleware\AdminAuth;

// 后台登录(无需鉴权)
Route::get('/admin/login', '\app\admin\controller\Publics/login');
Route::post('/admin/login', '\app\admin\controller\Publics/login');
Route::get('/admin/logout', '\app\admin\controller\Publics/logout');

// 后台鉴权路由组
Route::group('admin', function () {
    // 仪表盘
    Route::get('/', '\app\admin\controller\Index/index');

    // 资源管理
    Route::group('resource', function () {
        Route::get('/', '\app\admin\controller\Resource/index');
        Route::get('add', '\app\admin\controller\Resource/add');
        Route::get('edit/:id', '\app\admin\controller\Resource/edit');
        Route::post('delete/:id', '\app\admin\controller\Resource/delete');
    });

    // 网盘源
    Route::group('pan_source', function () {
        Route::get('/', '\app\admin\controller\PanSource/index');
        Route::get('add', '\app\admin\controller\PanSource/add');
        Route::get('edit/:id', '\app\admin\controller\PanSource/edit');
        Route::post('delete/:id', '\app\admin\controller\PanSource/delete');
    });

    // 采集任务
    Route::group('crawl', function () {
        Route::get('/', '\app\admin\controller\Crawl/index');
        Route::get('add', '\app\admin\controller\Crawl/add');
        Route::get('edit/:id', '\app\admin\controller\Crawl/edit');
        Route::post('delete/:id', '\app\admin\controller\Crawl/delete');
    });

    // 用户提交审核
    Route::group('submission', function () {
        Route::get('/', '\app\admin\controller\Submission/index');
        Route::get('add', '\app\admin\controller\Submission/add');
        Route::get('edit/:id', '\app\admin\controller\Submission/edit');
        Route::post('delete/:id', '\app\admin\controller\Submission/delete');
    });

    // 用户管理
    Route::group('user', function () {
        Route::get('/', '\app\admin\controller\User/index');
        Route::get('add', '\app\admin\controller\User/add');
        Route::get('edit/:id', '\app\admin\controller\User/edit');
        Route::post('delete/:id', '\app\admin\controller\User/delete');
    });

    // 订单管理
    Route::group('order', function () {
        Route::get('/', '\app\admin\controller\Order/index');
        Route::get('add', '\app\admin\controller\Order/add');
        Route::get('edit/:id', '\app\admin\controller\Order/edit');
        Route::post('delete/:id', '\app\admin\controller\Order/delete');
    });

    // 积分套餐
    Route::group('package', function () {
        Route::get('/', '\app\admin\controller\Package/index');
        Route::get('add', '\app\admin\controller\Package/add');
        Route::get('edit/:id', '\app\admin\controller\Package/edit');
        Route::post('delete/:id', '\app\admin\controller\Package/delete');
    });

    // 广告管理
    Route::group('ad', function () {
        Route::get('/', '\app\admin\controller\Ad/index');
        Route::get('add', '\app\admin\controller\Ad/add');
        Route::get('edit/:id', '\app\admin\controller\Ad/edit');
        Route::post('delete/:id', '\app\admin\controller\Ad/delete');
    });

    // 系统配置
    Route::group('config', function () {
        Route::get('/', '\app\admin\controller\Config/index');
        Route::get('add', '\app\admin\controller\Config/add');
        Route::get('edit/:id', '\app\admin\controller\Config/edit');
        Route::post('delete/:id', '\app\admin\controller\Config/delete');
    });

    // 敏感词
    Route::group('sensitive', function () {
        Route::get('/', '\app\admin\controller\Sensitive/index');
        Route::get('add', '\app\admin\controller\Sensitive/add');
        Route::get('edit/:id', '\app\admin\controller\Sensitive/edit');
        Route::post('delete/:id', '\app\admin\controller\Sensitive/delete');
    });

    // 日志查看
    Route::group('log', function () {
        Route::get('/', '\app\admin\controller\Log/index');
        Route::get('add', '\app\admin\controller\Log/add');
        Route::get('edit/:id', '\app\admin\controller\Log/edit');
        Route::post('delete/:id', '\app\admin\controller\Log/delete');
    });
})->middleware(AdminAuth::class);
