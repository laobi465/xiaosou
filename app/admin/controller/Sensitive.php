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
            ->paginate(config('pan.page_size.admin', 15), false, ['query' => $this->request->param()]);

        return view('sensitive/index', [
            'list'   => $list,
            'word'   => $word,
            'status' => $status,
        ]);
    }

    /**
     * 新增敏感词
     *
     * 安全: 依赖 DB 唯一约束(uk_word)兜底,catch 唯一键异常,
     * 避免先查重再 create 在并发下产生重复写入。
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

        if (!isset($data['status'])) {
            $data['status'] = 1;
        }

        try {
            $model = SensitiveWord::create($data);
        } catch (\Throwable $e) {
            // 唯一键冲突 → 友好提示;其他异常 → 脱敏
            if ($this->isDuplicateKeyException($e)) {
                return $this->error('该敏感词已存在');
            }
            return $this->errorWithLog('新增失败,请稍后重试', $e, 'sensitive_create_error');
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
            return $this->errorWithLog('保存失败', $e, 'sensitive_update_error');
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
            return $this->errorWithLog('删除失败', $e, 'sensitive_delete_error');
        }

        SensitiveFilter::clearCache();
        $this->logAction('sensitive', 'delete', $id, ['word' => $model->word]);
        return $this->success([], '删除成功');
    }

    /**
     * 批量导入(words 文本域, 每行一个)
     *
     * 性能优化:
     *   - 限制导入条数上限 5000,防止超大请求耗尽资源
     *   - 先 whereIn 一次取出已存在集合,差集用 insertAll 批量入库,消除 N+1 查询
     *   - 并发场景下由 DB 唯一约束(uk_word)兜底,catch 唯一键异常
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
        $lines = array_values(array_unique($lines));

        if (empty($lines)) {
            return $this->error('未检测到有效词条');
        }

        // 限制导入条数上限
        $maxImport = 5000;
        if (count($lines) > $maxImport) {
            return $this->error('单次导入条数不能超过 ' . $maxImport . ' 条');
        }

        // 一次取出已存在的词条集合,消除 N+1 查询
        $existing = SensitiveWord::whereIn('word', $lines)->column('word');
        $existingMap = array_flip($existing);

        // 计算差集(待新增)
        $newWords = [];
        foreach ($lines as $word) {
            if (!isset($existingMap[$word])) {
                $newWords[] = $word;
            }
        }

        $inserted = 0;
        if (!empty($newWords)) {
            $now = date('Y-m-d H:i:s');
            $batch = [];
            foreach ($newWords as $word) {
                $batch[] = [
                    'word'        => $word,
                    'status'      => 1,
                    'create_time' => $now,
                ];
            }

            try {
                // 批量入库
                $inserted = SensitiveWord::insertAll($batch);
            } catch (\Throwable $e) {
                // 并发场景下可能触发唯一键冲突,降级为逐条插入跳过重复
                if ($this->isDuplicateKeyException($e)) {
                    foreach ($newWords as $word) {
                        try {
                            SensitiveWord::create([
                                'word'   => $word,
                                'status' => 1,
                            ]);
                            $inserted++;
                        } catch (\Throwable $itemE) {
                            // 单条失败(含重复)跳过
                            if (!$this->isDuplicateKeyException($itemE)) {
                                trace('sensitive_import_item_error: ' . $itemE->getMessage(), 'error');
                            }
                        }
                    }
                } else {
                    return $this->errorWithLog('导入失败,请稍后重试', $e, 'sensitive_import_error');
                }
            }
        }

        SensitiveFilter::clearCache();
        $this->logAction('sensitive', 'import', null, [
            'total'    => count($lines),
            'inserted' => $inserted,
        ]);
        return $this->success(['inserted' => $inserted], '导入完成,新增' . $inserted . '条');
    }

    /**
     * 判断异常是否为唯一键冲突(MySQL Duplicate entry / SQLSTATE 23000)
     *
     * @param \Throwable $e
     * @return bool
     */
    protected function isDuplicateKeyException(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        // MySQL: Duplicate entry 'xxx' for key 'uk_word' (errno 1062, SQLSTATE 23000)
        if (stripos($msg, 'Duplicate entry') !== false) {
            return true;
        }
        $code = (string) $e->getCode();
        if ($code === '23000' || $code === '1062') {
            return true;
        }
        // ThinkPHP PDOException 可能将原始异常放在 data 中
        if (method_exists($e, 'getData')) {
            $data = $e->getData();
            if (is_array($data)) {
                $origin = $data['PDOException'] ?? $data['origin'] ?? null;
                if ($origin instanceof \Throwable) {
                    return $this->isDuplicateKeyException($origin);
                }
            }
        }
        return false;
    }
}
