<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;
use app\common\service\ConfigService;
use app\common\validate\ConfigValidate;
use think\facade\Db;

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
     *
     * 安全加固:
     *   - 逐 key 白名单校验(ConfigService::isWhitelisted),拒绝写入任意 key
     *   - 每个 value 按类型校验(数字/布尔/字符串/枚举)
     *   - 用 Db::transaction 包裹整个 foreach 循环,保证原子性(全成功或全回滚)
     *   - CSRF 校验由全局 CheckCsrf 中间件统一处理(X-CSRF-Token 头)
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

        $group        = (string) $data['group'];
        $configService = app(ConfigService::class);

        // 逐 key 白名单 + 类型校验,任一失败立即返回,不进入事务
        foreach ($data['configs'] as $key => $value) {
            $keyStr = (string) $key;
            [$ok, $err] = $configService->validateValue($group, $keyStr, $value);
            if (!$ok) {
                return $this->error($err);
            }
        }

        // 事务包裹整个保存循环,保证原子性
        try {
            $saved = Db::transaction(function () use ($data, $group, $configService) {
                $count = 0;
                foreach ($data['configs'] as $key => $value) {
                    $keyStr = (string) $key;
                    // 敏感字段(密码/密钥)留空表示不修改, 跳过更新避免用空串覆盖真实值
                    // 覆盖 smtp_pass / caihong_key 等不含 password/secret 字样的敏感字段
                    if ($value === '' && $this->isSensitiveKey($keyStr)) {
                        continue;
                    }
                    if (!$configService->set($keyStr, $value)) {
                        // set 内部失败,抛异常触发事务回滚,避免部分成功部分失败
                        throw new \RuntimeException('config_set_failed: ' . $keyStr);
                    }
                    $count++;
                }
                return $count;
            });
        } catch (\Throwable $e) {
            return $this->errorWithLog('配置保存失败,请稍后重试', $e, 'config_save_error');
        }

        $this->logAction('config', 'save', null, [
            'group' => $group,
            'count' => $saved,
        ]);
        return $this->success(['count' => $saved], '保存成功');
    }

    /**
     * 判断配置 key 是否为敏感字段(密码/密钥类)
     *
     * 覆盖: password / secret / pass(含 smtp_pass) / _key 后缀(含 caihong_key)
     * 与视图 config/index.html 的密码框判断条件保持一致
     */
    protected function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        return stripos($lower, 'password') !== false
            || stripos($lower, 'secret') !== false
            || stripos($lower, 'pass') !== false
            || str_ends_with($lower, '_key');
    }
}
