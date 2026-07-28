<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;
use app\common\model\SensitiveWord;
use app\common\service\SensitiveFilter;

/**
 * 敏感词管理
 */
class Sensitive extends BaseAdminController
{
    /**
     * 敏感词列表
     */
    public function index()
    {
        $word   = (string) $this->request->get('word', '');
        $status = $this->request->get('status', '');

        $list = SensitiveWord::when($word !== '', function ($query) use ($word) {
            $query->whereLike('word', '%' . $word . '%');
        })->when($status !== '' && $status !== null, function ($query) use ($status) {
            $query->where('status', (int) $status);
        })->order('id', 'desc')
            ->paginate(15, false, ['query' => $this->request->param()]);

        return view('sensitive/index', [
            'list'   => $list,
            'word'   => $word,
            'status' => $status,
        ]);
    }

    /**
     * 新增敏感词
     */
    public function add()
    {
        if (!$this->request->isPost()) {
            return view('sensitive/add');
        }

        $data = $this->request->only(['word', 'status']);

        if ((string) ($data['word'] ?? '') === '') {
            return $this->error('敏感词不能为空');
        }

        // 去重检查
        if (SensitiveWord::where('word', $data['word'])->find()) {
            return $this->error('该敏感词已存在');
        }

        if (!isset($data['status'])) {
            $data['status'] = 1;
        }

        try {
            $model = SensitiveWord::create($data);
        } catch (\Throwable $e) {
            return $this->error('新增失败: ' . $e->getMessage());
        }

        SensitiveFilter::clearCache();
        $this->logAction('sensitive', 'create', (int) $model->id, ['word' => $data['word']]);
        return $this->success(['id' => $model->id, 'url' => '/admin/sensitive'], '新增成功');
    }

    /**
     * 编辑敏感词
     */
    public function edit(int $id)
    {
        $model = SensitiveWord::find($id);
        if (!$model) {
            return $this->error('敏感词不存在');
        }

        if (!$this->request->isPost()) {
            return view('sensitive/edit', ['vo' => $model]);
        }

        $data = $this->request->only(['word', 'status']);

        if ((string) ($data['word'] ?? '') === '') {
            return $this->error('敏感词不能为空');
        }

        try {
            $model->save($data);
        } catch (\Throwable $e) {
            return $this->error('保存失败: ' . $e->getMessage());
        }

        SensitiveFilter::clearCache();
        $this->logAction('sensitive', 'update', $id, $data);
        return $this->success(['url' => '/admin/sensitive'], '保存成功');
    }

    /**
     * 删除敏感词
     */
    public function delete(int $id)
    {
        $model = SensitiveWord::find($id);
        if (!$model) {
            return $this->error('敏感词不存在');
        }

        try {
            $model->delete();
        } catch (\Throwable $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }

        SensitiveFilter::clearCache();
        $this->logAction('sensitive', 'delete', $id, ['word' => $model->word]);
        return $this->success([], '删除成功');
    }

    /**
     * 批量导入(words 文本域, 每行一个)
     */
    public function import()
    {
        $words = (string) $this->request->post('words', '');
        if ($words === '') {
            return $this->error('导入内容不能为空');
        }

        $lines = explode("\n", $words);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines, fn ($line) => $line !== '');
        $lines = array_unique($lines);

        if (empty($lines)) {
            return $this->error('未检测到有效词条');
        }

        $inserted = 0;
        foreach ($lines as $word) {
            // 跳过已存在的词
            if (SensitiveWord::where('word', $word)->find()) {
                continue;
            }
            try {
                SensitiveWord::create(['word' => $word, 'status' => 1]);
                $inserted++;
            } catch (\Throwable $e) {
                // 单条失败不影响其他
                trace('sensitive_import_item_error: ' . $e->getMessage(), 'error');
            }
        }

        SensitiveFilter::clearCache();
        $this->logAction('sensitive', 'import', null, [
            'total'    => count($lines),
            'inserted' => $inserted,
        ]);
        return $this->success(['inserted' => $inserted], '导入完成,新增' . $inserted . '条');
    }
}
