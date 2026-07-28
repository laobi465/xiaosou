<?php
declare(strict_types=1);

namespace app\common\service;

/**
 * 敏感词过滤服务
 *
 * 底层使用 Pansou\Sensitive\DfaFilter(DFA 算法)实现。
 * 敏感词来源: sensitive_words 表 status=1 的词条。
 */
class SensitiveFilter
{
    /**
     * 检查文本是否命中敏感词
     *
     * @param string $text 待检查文本
     * @return array{hit:bool,words:array<int,string>} ['hit'=>是否命中,'words'=>命中的敏感词列表]
     */
    public function check(string $text): array
    {
        // TODO: 加载 sensitive_words 表词条到 DFA
        // TODO: 调用 Pansou\Sensitive\DfaFilter::check($text)
        return ['hit' => false, 'words' => []];
    }
}
