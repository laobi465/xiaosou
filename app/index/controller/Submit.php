<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use app\common\enum\SubmissionStatus;
use app\common\model\PanSource;
use app\common\model\Submission;
use app\common\service\SensitiveFilter;
use app\common\validate\SubmitValidate;

/**
 * 资源提交
 * 登录校验由路由中间件 UserAuth 处理
 * 限流由路由中间件 RateLimit 处理
 */
class Submit extends BaseController
{
    /**
     * 提交页(含网盘源列表)
     */
    public function index()
    {
        $panSources = [];
        try {
            $panSources = PanSource::where('enabled', 1)
                ->order('sort', 'asc')
                ->select();
        } catch (\Throwable $e) {
            trace('submit_pan_sources_error: ' . $e->getMessage(), 'error');
        }

        return view('submit/index', [
            'panSources' => $panSources,
        ]);
    }

    /**
     * 创建提交(Ajax)
     * POST 校验 → 重复提交校验(user_id + title) → SensitiveFilter(try-catch) → 入库 Submission status=0 → JSON
     *
     * 限流由路由层 RateLimit 中间件保障(同 IP/分钟频控)。
     */
    public function create()
    {
        $data = [
            'title'         => (string) $this->request->post('title', ''),
            'share_url'     => (string) $this->request->post('share_url', ''),
            'pan_source_id' => (int) $this->request->post('pan_source_id', 0),
            'resource_type' => (int) $this->request->post('resource_type', 0),
            'extract_code'  => (string) $this->request->post('extract_code', ''),
            'intro'         => (string) $this->request->post('intro', ''),
        ];

        $validate = new SubmitValidate();
        if (!$validate->scene('create')->check($data)) {
            return $this->error($validate->getError());
        }

        $userId = (int) $this->userId();

        // 重复提交防护: 同一用户 + 同一标题 24 小时内不可重复提交
        try {
            $since = date('Y-m-d H:i:s', time() - 86400);
            $exists = Submission::where('user_id', $userId)
                ->where('title', $data['title'])
                ->where('create_time', '>=', $since)
                ->find();
            if ($exists) {
                return $this->error('您已提交过该资源,请勿重复提交');
            }
        } catch (\Throwable $e) {
            trace('submit_dedup_check_error: ' . $e->getMessage(), 'error');
            // 查询异常不阻塞流程,继续走主流程
        }

        // 敏感词过滤检查 title / intro(异常降级,不阻塞主流程)
        try {
            $sensitiveFilter = app(SensitiveFilter::class);
            $titleCheck = $sensitiveFilter->check($data['title']);
            if (!empty($titleCheck['hit'])) {
                return $this->error('标题包含敏感词: ' . implode(',', $titleCheck['words']));
            }
            $introCheck = $sensitiveFilter->check($data['intro']);
            if (!empty($introCheck['hit'])) {
                return $this->error('简介包含敏感词: ' . implode(',', $introCheck['words']));
            }
        } catch (\Throwable $e) {
            trace('submit_sensitive_check_error: ' . $e->getMessage(), 'error');
            // 敏感词服务异常时降级放行, 由审核环节兜底
        }

        // 入库
        try {
            Submission::create([
                'user_id'       => $userId,
                'title'         => $data['title'],
                'share_url'     => $data['share_url'],
                'pan_source_id' => $data['pan_source_id'],
                'resource_type' => $data['resource_type'],
                'extract_code'  => $data['extract_code'],
                'intro'         => $data['intro'],
                'status'        => SubmissionStatus::PENDING,
            ]);
        } catch (\Throwable $e) {
            return $this->errorWithLog('提交失败,请稍后重试', $e, 'submit_create_error');
        }

        return $this->success([], '提交成功,等待审核');
    }

    /**
     * 我的提交列表页
     */
    public function myList()
    {
        $userId = $this->userId();

        $submissions = Submission::where('user_id', $userId)
            ->order('create_time', 'desc')
            ->paginate(config('pan.page_size.index', 15));

        return view('submit/my_list', [
            'submissions' => $submissions,
        ]);
    }
}
