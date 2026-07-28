<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;
use app\common\model\AdPlacement;
use app\common\model\AdSlot;
use app\common\model\AdStat;
use app\common\validate\AdValidate;

/**
 * 广告管理
 */
class Ad extends BaseAdminController
{
    /**
     * 广告位列表(AdSlot)
     */
    public function index()
    {
        $list = AdSlot::order('id', 'asc')
            ->paginate(15, false, ['query' => $this->request->param()]);

        return view('ad/index', ['list' => $list]);
    }

    /**
     * 投放列表(AdPlacement by slot_id)
     */
    public function placements(int $slotId)
    {
        $slot = AdSlot::find($slotId);
        if (!$slot) {
            return $this->error('广告位不存在');
        }

        $status = $this->request->get('status', '');

        $list = AdPlacement::where('slot_id', $slotId)
            ->when($status !== '' && $status !== null, function ($query) use ($status) {
                $query->where('status', (int) $status);
            })->order('id', 'desc')
              ->paginate(15, false, ['query' => $this->request->param()]);

        return view('ad/placements', [
            'slot'   => $slot,
            'list'   => $list,
            'status' => $status,
        ]);
    }

    /**
     * 新建投放
     * GET  渲染表单
     * POST 保存(AdValidate::create)
     */
    public function create(int $slotId)
    {
        $slot = AdSlot::find($slotId);
        if (!$slot) {
            return $this->error('广告位不存在');
        }

        if (!$this->request->isPost()) {
            return view('ad/create', ['slot' => $slot]);
        }

        $data = $this->request->only([
            'title', 'image_url', 'link_url',
            'start_at', 'end_at', 'weight',
        ]);
        $data['slot_id'] = $slotId;
        $data['status']  = (int) $this->request->post('status', 1);

        $validate = new AdValidate();
        if (!$validate->scene('create')->check($data)) {
            return $this->error($validate->getError());
        }

        try {
            $placement = AdPlacement::create($data);
        } catch (\Throwable $e) {
            return $this->error('新增失败: ' . $e->getMessage());
        }

        $this->logAction('ad', 'create', (int) $placement->id, $data);
        return $this->success(['id' => $placement->id, 'url' => '/admin/ad/placements/' . $slotId], '新增成功');
    }

    /**
     * 编辑投放
     * GET  渲染表单
     * POST 保存(AdValidate::edit)
     */
    public function edit(int $id)
    {
        $placement = AdPlacement::find($id);
        if (!$placement) {
            return $this->error('广告投放不存在');
        }

        if (!$this->request->isPost()) {
            return view('ad/edit', ['vo' => $placement]);
        }

        $data = $this->request->only([
            'slot_id', 'title', 'image_url', 'link_url',
            'start_at', 'end_at', 'weight', 'status',
        ]);

        // slot_id 未提交时沿用原值
        if (empty($data['slot_id'])) {
            $data['slot_id'] = (int) $placement->slot_id;
        }

        $validate = new AdValidate();
        if (!$validate->scene('edit')->check($data)) {
            return $this->error($validate->getError());
        }

        try {
            $placement->save($data);
        } catch (\Throwable $e) {
            return $this->error('保存失败: ' . $e->getMessage());
        }

        $this->logAction('ad', 'update', $id, $data);
        return $this->success(['url' => '/admin/ad/placements/' . $placement->slot_id], '保存成功');
    }

    /**
     * 广告统计(曝光/点击/CTR, AdStat by placement_id)
     */
    public function stats(int $id)
    {
        $placement = AdPlacement::find($id);
        if (!$placement) {
            return $this->error('广告投放不存在');
        }

        $list = AdStat::where('placement_id', $id)
            ->order('id', 'desc')
            ->paginate(15, false, ['query' => $this->request->param()]);

        $totalImpressions = (int) AdStat::where('placement_id', $id)->sum('impressions');
        $totalClicks      = (int) AdStat::where('placement_id', $id)->sum('clicks');
        $ctr              = $totalImpressions > 0
            ? round($totalClicks / $totalImpressions * 100, 2)
            : 0.0;

        return view('ad/stats', [
            'placement'        => $placement,
            'list'             => $list,
            'totalImpressions' => $totalImpressions,
            'totalClicks'      => $totalClicks,
            'ctr'              => $ctr,
        ]);
    }

    /**
     * 上下线切换(status 字段)
     */
    public function toggle(int $id)
    {
        $placement = AdPlacement::find($id);
        if (!$placement) {
            return $this->error('广告投放不存在');
        }

        $placement->status = (int) $placement->status === 1 ? 0 : 1;
        try {
            $placement->save();
        } catch (\Throwable $e) {
            return $this->error('操作失败: ' . $e->getMessage());
        }

        $this->logAction('ad', 'toggle', $id, ['status' => $placement->status]);
        return $this->success(['status' => $placement->status], '操作成功');
    }
}
