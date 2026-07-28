<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;
use app\common\service\ConfigService;
use app\common\validate\ConfigValidate;

/**
 * 系统配置
 */
class Config extends BaseAdminController
{
    /** 支持的配置分组 */
    protected const GROUPS = ['smtp', 'payment', 'site', 'credit', 'security'];

    /**
     * 配置项分组展示(ConfigService::getGroup)
     */
    public function index()
    {
        $current = (string) $this->request->get('group', '');
        $groups  = self::GROUPS;
        $active  = in_array($current, $groups, true) ? $current : '';

        $configService = app(ConfigService::class);

        if ($active !== '') {
            // 仅加载指定分组
            $data = [$active => $configService->getGroup($active)];
        } else {
            // 加载全部分组
            $data = [];
            foreach ($groups as $name) {
                $data[$name] = $configService->getGroup($name);
            }
        }

        return view('config/index', [
            'groups'  => $groups,
            'data'    => $data,
            'active'  => $active,
        ]);
    }

    /**
     * 保存配置(ConfigValidate::save, 遍历 configs 数组 ConfigService::set)
     */
    public function save()
    {
        $data = $this->request->only(['group', 'configs']);

        $validate = new ConfigValidate();
        if (!$validate->scene('save')->check($data)) {
            return $this->error($validate->getError());
        }

        if (!is_array($data['configs']) || empty($data['configs'])) {
            return $this->error('配置数据不能为空');
        }

        $configService = app(ConfigService::class);
        $saved = 0;

        foreach ($data['configs'] as $key => $value) {
            if ($configService->set((string) $key, $value)) {
                $saved++;
            }
        }

        $this->logAction('config', 'save', null, [
            'group' => $data['group'],
            'count' => $saved,
        ]);
        return $this->success(['count' => $saved], '保存成功');
    }
}
