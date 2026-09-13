<?php

declare(strict_types=1);

namespace Pholio\Highlight;

/**
 * Comment notations from @shikijs/transformers 4.4.3, applied in this order
 * (transformerNotationHighlight, …WordHighlight, …Diff, …Focus, each with matchAlgorithm "v3").
 *
 * JavaScript quirks that can become visible are reproduced: `trim()` with the JavaScript notion of whitespace,
 * word positions in UTF-16 units, `splice(-1, 1)` for a token that is not found.
 */
final class Notation
{
    /** Whitespace like `\s` and `String.prototype.trim` in JavaScript. */
    public const JS_WS = '\x{9}-\x{D}\x{20}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';
    private const DOT = '[^\n\r\x{2028}\x{2029}]';

    public static function apply(HastElement $code, HastElement $pre): void
    {
        self::map($code, $pre, '/#?[' . self::JS_WS . ']*\[!code (highlight|hl)(:\d+)?\]/iu', ['highlight' => 'highlighted', 'hl' => 'highlighted'], 'has-highlighted', -1);
        self::word($code);
        self::map($code, $pre, '/#?[' . self::JS_WS . ']*\[!code (\+\+|--)(:\d+)?\]/iu', ['++' => 'diff add', '--' => 'diff remove'], 'has-diff', -1);
        self::map($code, $pre, '/#?[' . self::JS_WS . ']*\[!code (focus)(:\d+)?\]/iu', ['focus' => 'focused'], 'has-focused', -1);
    }

    /** @param array<string,string> $classMap */
    private static function map(HastElement $code, HastElement $pre, string $regex, array $classMap, string $classActivePre, int $limit): void
    {
        self::run($code, $regex, $limit, static function (array $m, array $lines, int $index) use ($classMap, $pre, $classActivePre): bool {
            $range = $m[2] ?? ':1';
            $lineNum = (int) substr($range, 1);
            for ($i = $index; $i < min($index + $lineNum, count($lines)); $i++) {
                Hast::addClass($lines[$i] ?? null, $classMap[$m[1]] ?? null);
            }
            Hast::addClass($pre, $classActivePre);
            return true;
        });
    }

    private static function word(HastElement $code): void
    {
        self::run($code, '/[' . self::JS_WS . ']*\[!code word:((?:\\\\.|[^:\]])+)(:\d+)?\]/u', 1, static function (array $m, array $lines, int $index, HastElement $token): bool {
            $lineNum = isset($m[2]) ? (int) substr($m[2], 1) : count($lines);
            $word = (string) preg_replace('/\\\\(.)/su', '$1', $m[1]);
            for ($i = $index; $i < min($index + $lineNum, count($lines)); $i++) {
                if (isset($lines[$i])) {
                    self::highlightWordInLine($lines[$i], $token, $word, 'highlighted-word');
                }
            }
            return true;
        });
    }

    /**
     * createCommentNotationTransformer(...).code
     *
     * @param callable(array<int,?string>, list<HastElement>, int, HastElement):bool $onMatch
     */
    private static function run(HastElement $code, string $regex, int $limit, callable $onMatch): void
    {
        $lines = array_values(array_filter($code->children, static fn ($c) => $c instanceof HastElement));
        $code->data['notation'] ??= self::parseComments($lines);
        $linesToRemove = [];
        foreach ($code->data['notation'] as $comment) {
            if ($comment->info[1] === '') {
                continue;
            }
            $lineIdx = array_search($comment->line, $lines, true);
            $lineIdx = $lineIdx === false ? -1 : $lineIdx;
            if ($comment->isLineCommentOnly) {
                $lineIdx++;
            }
            $replaced = false;
            $comment->info[1] = (string) preg_replace_callback($regex, static function (array $m) use (&$replaced, $onMatch, $lines, $lineIdx, $comment): string {
                if ($onMatch($m, $lines, $lineIdx, $comment->token)) {
                    $replaced = true;
                    return '';
                }
                return (string) $m[0];
            }, $comment->info[1], $limit, $count, PREG_UNMATCHED_AS_NULL);
            if (!$replaced) {
                continue;
            }
            // v3ClearEndCommentPrefix
            if (preg_match('/(?:\/\/|#|;{1,2}|%{1,2}|--)([' . self::JS_WS . ']*)\z/u', $comment->info[1], $pm, PREG_OFFSET_CAPTURE)
                && self::jsTrim($pm[1][0]) === '') {
                $comment->info[1] = self::jsTrimEnd(substr($comment->info[1], 0, $pm[0][1]));
            }
            $isEmpty = self::jsTrim($comment->info[1]) === '';
            if ($isEmpty) {
                $comment->info[1] = '';
            }
            if ($isEmpty && $comment->isLineCommentOnly) {
                $linesToRemove[] = $comment->line;
            } elseif ($isEmpty) {
                if ($comment->additionalTokens !== null) {
                    for ($j = count($comment->additionalTokens) - 1; $j >= 0; $j--) {
                        $k = array_search($comment->additionalTokens[$j], $comment->line->children, true);
                        if ($k !== false) {
                            array_splice($comment->line->children, $k, 1);
                        }
                    }
                }
                $k = array_search($comment->token, $comment->line->children, true);
                array_splice($comment->line->children, $k === false ? -1 : $k, 1);
            } else {
                $head = $comment->token->children[0] ?? null;
                if ($head instanceof HastText) {
                    $head->value = $comment->info[0] . $comment->info[1] . ($comment->info[2] ?? '');
                    foreach ($comment->additionalTokens ?? [] as $extra) {
                        $h = $extra->children[0] ?? null;
                        if ($h instanceof HastText) {
                            $h->value = '';
                        }
                    }
                }
            }
        }
        foreach ($linesToRemove as $line) {
            $index = array_search($line, $code->children, true);
            $index = $index === false ? -1 : $index;
            $next = $index === -1 ? null : ($code->children[$index + 1] ?? null);
            $length = ($next instanceof HastText && $next->value === "\n") ? 2 : 1;
            array_splice($code->children, $index, $length);
        }
    }

    /**
     * parseComments(lines, jsx = false, "v3")
     *
     * @param list<HastElement> $lines
     * @return list<object{info: array{0:string,1:string,2:?string}, line: HastElement, token: HastElement, isLineCommentOnly: bool, additionalTokens: ?list<HastElement>}>
     */
    private static function parseComments(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $n = count($line->children);
            $split = [];
            foreach ($line->children as $idx => $el) {
                $tok = $el instanceof HastElement ? ($el->children[0] ?? null) : null;
                if (!$tok instanceof HastText || self::matchToken($tok->value, $idx === $n - 1) === null) {
                    $split[] = $el;
                    continue;
                }
                $raw = preg_split('/([' . self::JS_WS . ']+\/\/)/u', $tok->value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
                if (count($raw) <= 1) {
                    $split[] = $el;
                    continue;
                }
                $parts = [$raw[0]];
                for ($i = 1; $i < count($raw); $i += 2) {
                    $parts[] = $raw[$i] . ($raw[$i + 1] ?? '');
                }
                $parts = array_values(array_filter($parts, static fn ($s) => $s !== ''));
                if (count($parts) <= 1) {
                    $split[] = $el;
                    continue;
                }
                foreach ($parts as $p) {
                    $split[] = new HastElement($el->tagName, $el->properties, [new HastText($p)]);
                }
            }
            if (count($split) !== $n) {
                $line->children = $split;
            }
            $elements = $line->children;
            $count = count($elements);
            for ($i = max($count - 1, 0); $i < $count; $i++) {
                $token = $elements[$i];
                if (!$token instanceof HastElement) {
                    continue;
                }
                $head = $token->children[0] ?? null;
                if (!$head instanceof HastText) {
                    continue;
                }
                $isLast = $i === $count - 1;
                $match = self::matchToken($head->value, $isLast);
                if ($match === null && $i > 0 && str_starts_with(self::jsTrim($head->value), '[!code')) {
                    $prev = $elements[$i - 1];
                    $prevHead = $prev instanceof HastElement ? ($prev->children[0] ?? null) : null;
                    if ($prevHead instanceof HastText && str_contains($prevHead->value, '//')) {
                        $combined = self::matchToken($prevHead->value . $head->value, $isLast);
                        if ($combined !== null) {
                            $out[] = (object) [
                                'info' => $combined,
                                'line' => $line,
                                'token' => $prev,
                                'isLineCommentOnly' => $count === 2 && count($prev->children) === 1 && count($token->children) === 1,
                                'additionalTokens' => [$token],
                            ];
                            continue;
                        }
                    }
                }
                if ($match === null) {
                    continue;
                }
                $out[] = (object) [
                    'info' => $match,
                    'line' => $line,
                    'token' => $token,
                    'isLineCommentOnly' => $count === 1 && count($token->children) === 1,
                    'additionalTokens' => null,
                ];
            }
        }
        return $out;
    }

    /** @return array{0:string,1:string,2:?string}|null */
    private static function matchToken(string $text, bool $isLast): ?array
    {
        $trimmed = self::jsTrimStart($text);
        $spaceFront = mb_strlen($text, 'UTF-8') - mb_strlen($trimmed, 'UTF-8');
        $trimmed = self::jsTrimEnd($trimmed);
        $spaceEnd = mb_strlen($text, 'UTF-8') - mb_strlen($trimmed, 'UTF-8') - $spaceFront;
        $d = self::DOT;
        $matchers = [
            ['/^(<!--)(' . $d . '+)(-->)\z/u', false],
            ['/^(\/\*)(' . $d . '+)(\*\/)\z/u', false],
            ['/^(\/\/|["\'#]|;{1,2}|%{1,2}|--)(' . $d . '*)\z/u', true],
            ['/^(\*)(' . $d . '+)\z/u', true],
        ];
        foreach ($matchers as [$re, $endOfLine]) {
            if ($endOfLine && !$isLast) {
                continue;
            }
            if (!preg_match($re, $trimmed, $m)) {
                continue;
            }
            return [
                str_repeat(' ', $spaceFront) . $m[1],
                $m[2],
                isset($m[3]) && $m[3] !== '' ? $m[3] . str_repeat(' ', $spaceEnd) : null,
            ];
        }
        return null;
    }

    public static function jsTrim(string $s): string
    {
        return self::jsTrimEnd(self::jsTrimStart($s));
    }

    public static function jsTrimStart(string $s): string
    {
        return (string) preg_replace('/^[' . self::JS_WS . ']+/u', '', $s);
    }

    public static function jsTrimEnd(string $s): string
    {
        return (string) preg_replace('/[' . self::JS_WS . ']+\z/u', '', $s);
    }

    // -------------------------------------------------------------- Word highlighting (UTF-16 positions)

    private static function highlightWordInLine(HastElement $line, HastElement $ignored, string $word, string $className): void
    {
        $content = self::u16(self::textContent($line));
        $needle = self::u16($word);
        $len = intdiv(strlen($needle), 2);
        $index = self::u16IndexOf($content, $needle, 0);
        while ($index !== -1) {
            self::highlightRange($line, $ignored, $index, $len, $className);
            $index = self::u16IndexOf($content, $needle, $index + 1);
        }
    }

    private static function textContent(HastElement|HastText $node): string
    {
        if ($node instanceof HastText) {
            return $node->value;
        }
        $s = '';
        foreach ($node->children as $c) {
            $s .= self::textContent($c);
        }
        return $s;
    }

    private static function highlightRange(HastElement $line, HastElement $ignored, int $index, int $len, string $className): void
    {
        $currentIdx = 0;
        for ($i = 0; $i < count($line->children); $i++) {
            $element = $line->children[$i];
            if (!$element instanceof HastElement || $element->tagName !== 'span' || $element === $ignored) {
                continue;
            }
            $textNode = $element->children[0] ?? null;
            if (!$textNode instanceof HastText) {
                continue;
            }
            $text = self::u16($textNode->value);
            $textLen = intdiv(strlen($text), 2);
            if ($currentIdx <= $index + $len && $currentIdx + $textLen - 1 >= $index) {
                $start = max(0, $index - $currentIdx);
                $length = $len - max(0, $currentIdx - $index);
                if ($length === 0) {
                    continue;
                }
                $parts = [];
                if ($start > 0) {
                    $parts[] = new HastElement($element->tagName, $element->properties, [new HastText(self::u8(substr($text, 0, $start * 2)))]);
                }
                $mid = new HastElement($element->tagName, $element->properties, [new HastText(self::u8(substr($text, $start * 2, $length * 2)))]);
                Hast::addClass($mid, $className);
                $parts[] = $mid;
                if ($start + $length < $textLen) {
                    $parts[] = new HastElement($element->tagName, $element->properties, [new HastText(self::u8(substr($text, ($start + $length) * 2)))]);
                }
                array_splice($line->children, $i, 1, $parts);
                $i += count($parts) - 1;
            }
            $currentIdx += $textLen;
        }
    }

    private static function u16(string $s): string
    {
        return mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
    }

    private static function u8(string $s): string
    {
        return mb_convert_encoding($s, 'UTF-8', 'UTF-16LE');
    }

    private static function u16IndexOf(string $haystack, string $needle, int $fromUnit): int
    {
        $offset = $fromUnit * 2;
        while (($p = strpos($haystack, $needle, $offset)) !== false) {
            if ($p % 2 === 0) {
                return intdiv($p, 2);
            }
            $offset = $p + 1;
        }
        return -1;
    }
}
