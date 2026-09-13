<?php

declare(strict_types=1);

namespace Pholio\Highlight;

use RuntimeException;

/**
 * Counterpart of JavaScriptScanner (@shikijs/engine-javascript): finds the earliest match of a list of patterns from a
 * start position; on a tie the pattern with the lower index wins.
 *
 * Positions are byte offsets in the UTF-8 string (Shiki counts UTF-16 units; only the boundaries matter for the
 * output, and in both cases they fall on the same characters).
 *
 * Like the OnigScanner of vscode-oniguruma, the scanner remembers the last result per pattern for the same string:
 * a match at position p, found by a search from s0, stays valid unchanged for every later search from s with
 * s0 <= s <= p, as long as the pattern does not depend on the start position (sticky `\G` or clip).
 */
final class Scanner
{
    /** @var list<array{pattern:string, strategy:?string, groups:int, map:list<?int>, transfers:array<int,int>}> */
    private array $compiled;
    /** @var list<bool> */
    private array $positionDependent = [];
    private ?string $lastString = null;
    /** @var array<int, array{0:int, 1:?array}> pattern => [search start, result] */
    private array $cache = [];

    /** @param list<string> $patterns */
    public function __construct(array $patterns)
    {
        $this->compiled = [];
        foreach ($patterns as $p) {
            $t = OnigRegex::translate($p);
            $this->compiled[] = $t;
            $this->positionDependent[] = $t['strategy'] !== null || str_starts_with($t['pattern'], '/\G');
        }
    }

    /**
     * @return array{index:int, captures:list<?array{0:int,1:int}>}|null captures: [start, end] or null
     */
    public function findNextMatch(string $string, int $start): ?array
    {
        if ($this->lastString !== $string) {
            $this->lastString = $string;
            $this->cache = [];
        }
        $best = null;
        $bestIndex = -1;
        foreach ($this->compiled as $i => $t) {
            $result = null;
            $cached = $this->cache[$i] ?? null;
            if ($cached !== null && !$this->positionDependent[$i] && $cached[0] <= $start
                && ($cached[1] === null || $cached[1][0][0] >= $start)) {
                $result = $cached[1];
            } else {
                $result = self::exec($t, $string, $start);
                $this->cache[$i] = [$start, $result];
            }
            if ($result === null) {
                continue;
            }
            $pos = $result[0][0];
            if ($pos === $start) {
                return ['index' => $i, 'captures' => $result];
            }
            if ($best === null || $pos < $best[0][0]) {
                $best = $result;
                $bestIndex = $i;
            }
        }
        return $best === null ? null : ['index' => $bestIndex, 'captures' => $best];
    }

    /**
     * @param array{pattern:string, strategy:?string, groups:int, map:list<?int>, transfers:array<int,int>} $t
     * @return list<?array{0:int,1:int}>|null
     */
    private static function exec(array $t, string $string, int $start): ?array
    {
        $subject = $string;
        $offset = $start;
        $shift = 0;
        if ($t['strategy'] === 'clip' && $start > 0) {
            $subject = substr($string, $start);
            $offset = 0;
            $shift = $start;
        }
        $r = preg_match($t['pattern'], $subject, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset);
        if ($r === false) {
            throw new RuntimeException('Regex error (' . preg_last_error_msg() . ') in ' . $t['pattern']);
        }
        if ($r === 0) {
            return null;
        }
        $caps = [[$m[0][1] + $shift, $m[0][1] + $shift + strlen($m[0][0])]];
        if ($t['groups'] === 0) {
            return $caps;
        }
        $onig = array_fill(1, $t['groups'], null);
        foreach ($t['map'] as $k => $num) {
            if ($num !== null) {
                $g = $m[$k + 1] ?? null;
                if ($g !== null && $g[0] !== null) {
                    $onig[$num] = [$g[1] + $shift, $g[1] + $shift + strlen($g[0])];
                }
            }
        }
        foreach ($t['transfers'] as $from => $to) {
            $g = $m[$from] ?? null;
            $num = $t['map'][$to - 1];
            if ($g !== null && $g[0] !== null && $num !== null) {
                $onig[$num] = [$g[1] + $shift, $g[1] + $shift + strlen($g[0])];
            }
        }
        foreach ($onig as $c) {
            $caps[] = $c;
        }
        return $caps;
    }
}
