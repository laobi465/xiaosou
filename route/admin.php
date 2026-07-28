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
        Route::post('add', '\app\admin\controller\Resource/add');
        Route::get('edit/:id', '\app\admin\controller\Resource/edit');
        Route::post('edit/:id', '\app\admin\controller\Resource/edit');
        Route::post('delete/:id', '\app\admin\controller\Resource/delete');
        Route::post('markInvalid/:id', '\app\admin\controller\Resource/markInvalid');
        Route::post('batch', '\app\admin\controller\Resource/batch');
    });

    // 网盘源
    Route::group('pan_source', function () {
        Route::get('/', '\app\admin\controller\PanSource/index');
        Route::get('add', '\app\admin\controller\PanSource/add');
        Route::post('add', '\app\admin\controller\PanSource/add');
        Route::get('edit/:id', '\app\admin\controller\PanSource/edit');
        Route::post('edit/:id', '\app\admin\controller\PanSource/edit');
        Route::post('delete/:id', '\app\admin\controller\PanSource/delete');
        Route::post('toggle/:id', '\app\admin\controller\PanSource/toggle');
    });

    // 采集任务
    Route::group('crawl', function () {
        Route::get('/', '\app\admin\controller\Crawl/index');
        Route::get('add', '\app\admin\controller\Crawl/add');
        Route::post('add', '\app\admin\controller\Crawl/add');
        Route::get('edit/:id', '\app\admin\controller\Crawl/edit');
        Route::post('edit/:id', '\app\admin\controller\Crawl/edit');
        Route::post('delete/:id', '\app\admin\controller\Crawl/delete');
        Route::get('logs/:id', '\app\admin\controller\Crawl/logs');
        Route::post('trigger/:id', '\app\admin\controller\Crawl/trigger');
    });

    // 用户提交审核
    Route::group('submission', function () {
        Route::get('/', '\app\admin\controller\Submission/index');
        Route::post('approve/:id', '\app\admin\controller\Submission/approve');
        Route::post('reject/:id', '\app\admin\controller\Submission/reject');
    });

    // 用户管理
    Route::group('user', function () {
        Route::get('/', '\app\admin\controller\User/index');
        Route::get('detail/:id', '\app\admin\controller\User/detail');
        Route::post('adjustCredit/:id', '\app\admin\controller\User/adjustCredit');
        Route::post('toggle/:id', '\app\admin\controller\User/toggle');
    });

    // 订单管理
    Route::group('order', function () {
        Route::get('/', '\app\admin\controller\Order/index');
        Route::get('detail/:id', '\app\admin\controller\Order/detail');
        Route::post('manualComplete/:id', '\app\admin\controller\Order/manualComplete');
        Route::post('refund/:id', '\app\admin\controller\Order/refund');
    });

    // 积分套餐
    Route::group('package', function () {
        Route::get('/', '\app\admin\controller\Package/index');
        Route::get('add', '\app\admin\controller\Package/add');
        Route::post('add', '\app\admin\controller\Package/add');
        Route::get('edit/:id', '\app\admin\controller\Package/edit');
        Route::post('edit/:id', '\app\admin\controller\Package/edit');
        Route::post('delete/:id', '\app\admin\controller\Package/delete');
        Route::post('toggle/:id', '\app\admin\controller\Package/toggle');
    });

    // 广告管理
    Route::group('ad', function () {
        Route::get('/', '\app\admin\controller\Ad/index');
        Route::get('placements/:slotId', '\app\admin\controller\Ad/placements');
        Route::get('create/:slotId', '\app\admin\controller\Ad/create');
        Route::post('create/:slotId', '\app\admin\controller\Ad/create');
        Route::get('edit/:id', '\app\admin\controller\Ad/edit');
        Route::post('edit/:id', '\app\admin\controller\Ad/edit');
        Route::get('stats/:id', '\app\admin\controller\Ad/stats');
        Route::post('toggle/:id', '\app\admin\controller\Ad/toggle');
    });

    // 系统配置
    Route::group('config', function () {
        Route::get('/', '\app\admin\controller\Config/index');
        Route::post('save', '\app\admin\controller\Config/save');
    });

    // 敏感词
    Route::group('sensitive', function () {
        Route::get('/', '\app\admin\controller\Sensitive/index');
        Route::get('add', '\app\admin\controller\Sensitive/add');
        Route::post('add', '\app\admin\controller\Sensitive/add');
        Route::get('edit/:id', '\app\admin\controller\Sensitive/edit');
        Route::post('edit/:id', '\app\admin\controller\Sensitive/edit');
        Route::post('delete/:id', '\app\admin\controller\Sensitive/delete');
        Route::post('import', '\app\admin\controller\Sensitive/import');
    });

    // 日志查看
    Route::group('log', function () {
        Route::get('/', '\app\admin\controller\Log/index');
        Route::get('admin', '\app\admin\controller\Log/admin');
        Route::get('userLogin', '\app\admin\controller\Log/userLogin');
        Route::get('payment', '\app\admin\controller\Log/payment');
        Route::get('exception', '\app\admin\controller\Log/exception');
    });
})->middleware(AdminAuth::class);
