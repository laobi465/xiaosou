<?php
declare(strict_types=1);

namespace Pansou\Sensitive;

/**
 * DFA(确定有限自动机)敏感词过滤
 *
 * 适用于大量词条的快速匹配,时间复杂度近似 O(n)(n 为文本长度)。
 * 词条来源: sensitive_words 表 status=1 的词条。
 */
class DfaFilter
{
    /**
     * 敏感词树(嵌套哈希表)
     * 结构: ['字' => ['子字' => [..., 'end'=>true], ...], ...]
     *
     * @var array<string,array>
     */
    protected array $tree = [];

    /**
     * 是否已加载词条
     */
    protected bool $loaded = false;

    /**
     * 加载敏感词列表
     *
     * @param array<int,string> $words 敏感词列表
     * @return void
     */
    public function load(array $words): void
    {
        $this->tree = [];
        foreach ($words as $word) {
            $word = trim((string) $word);
            if ($word === '') {
                continue;
            }
            $this->insertWord($word);
        }
        $this->loaded = true;
    }

    /**
     * 检查文本是否命中敏感词
     *
     * @param string $text 待检查文本
     * @return array{hit:bool,words:array<int,string>} ['hit'=>是否命中,'words'=>命中的敏感词(去重)
     */
    public function check(string $text): array
    {
        $words = $this->findAll($text);
        return [
            'hit'   => !empty($words),
            'words' => $words,
        ];
    }

    /**
     * 替换敏感词为指定字符
     *
     * @param string $text        待处理文本
     * @param string $replacement 替换符(默认 ***)
     * @return string 替换后的文本
     */
    public function replace(string $text, string $replacement = '***'): string
    {
        if (empty($this->tree)) {
            return $text;
        }

        $length = mb_strlen($text, 'UTF-8');
        $result = '';
        $i = 0;
        while ($i < $length) {
            $char   = mb_substr($text, $i, 1, 'UTF-8');
            $node   = $this->tree[$char] ?? null;
            if ($node === null) {
                $result .= $char;
                $i++;
                continue;
            }
            // 尝试匹配最长敏感词
            $matchLen = 0;
            $matchEnd = false;
            $current  = $node;
            $j        = $i + 1;
            if (isset($current['end']) && $current['end'] === true) {
                $matchLen = 1;
                $matchEnd = true;
            }
            while ($j < $length) {
                $nextChar = mb_substr($text, $j, 1, 'UTF-8');
                if (!isset($current[$nextChar])) {
                    break;
                }
                $current = $current[$nextChar];
                $j++;
                if (isset($current['end']) && $current['end'] === true) {
                    $matchLen = $j - $i;
                    $matchEnd = true;
                }
            }
            if ($matchEnd && $matchLen > 0) {
                $result .= $replacement;
                $i += $matchLen;
            } else {
                $result .= $char;
                $i++;
            }
        }
        return $result;
    }

    /**
     * 查找文本中所有命中的敏感词(去重)
     *
     * @return array<int,string>
     */
    protected function findAll(string $text): array
    {
        if (empty($this->tree)) {
            return [];
        }

        $length   = mb_strlen($text, 'UTF-8');
        $found    = [];
        $foundSet = [];

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');
            $node = $this->tree[$char] ?? null;
            if ($node === null) {
                continue;
            }
            $current = $node;
            $j       = $i + 1;
            $matchWord = '';
            // 单字符敏感词
            if (isset($current['end']) && $current['end'] === true) {
                $matchWord = $char;
            }
            // 多字符敏感词(最长匹配)
            while ($j < $length) {
                $nextChar = mb_substr($text, $j, 1, 'UTF-8');
                if (!isset($current[$nextChar])) {
                    break;
                }
                $current = $current[$nextChar];
                $j++;
                if (isset($current['end']) && $current['end'] === true) {
                    $matchWord = mb_substr($text, $i, $j - $i, 'UTF-8');
                }
            }
            if ($matchWord !== '' && !isset($foundSet[$matchWord])) {
                $foundSet[$matchWord] = true;
                $found[] = $matchWord;
            }
        }
        return $found;
    }

    /**
     * 将单个敏感词插入 DFA 树
     */
    protected function insertWord(string $word): void
    {
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $node  = &$this->tree;
        foreach ($chars as $ch) {
            if (!isset($node[$ch])) {
                $node[$ch] = [];
            }
            $node = &$node[$ch];
        }
        $node['end'] = true;
    }

    /**
     * 是否已加载词条
     */
    public function isLoaded(): bool
    {
        return $this->loaded;
    }
}
