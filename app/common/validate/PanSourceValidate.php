<?php
declare(strict_types=1);

namespace app\common\validate;

use think\Validate;

/**
 * 网盘源验证器
 *
 * crawler_class 仅允许字母/数字/下划线/反斜杠(命名空间分隔符),
 * 杜绝任意类名入库后被反射实例化导致的 RCE 风险。
 * 控制器层额外校验类名前缀白名单与类是否存在。
 */
class PanSourceValidate extends Validate
{
    protected $rule = [
        'name'          => 'require|max:50',
        'code'          => 'require|alphaDash|max:30',
        'is_mainstream' => 'in:0,1',
        'crawler_class'=> 'require|max:200|regex:/^[A-Za-z0-9_\\\\]+$/',
        'api_config'    => 'max:2000',
        'enabled'       => 'in:0,1',
        'sort'          => 'integer|egt:0|max:9999',
    ];

    protected $scene = [
        'create' => ['name', 'code', 'is_mainstream', 'crawler_class', 'api_config', 'enabled', 'sort'],
        'edit'   => ['name', 'is_mainstream', 'crawler_class', 'api_config', 'enabled', 'sort'],
    ];
}
