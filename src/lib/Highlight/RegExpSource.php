<?php

declare(strict_types=1);

namespace Pholio\Highlight;

/**
 * RegExpSource, RegExpSourceList and CompiledRule from vscode-textmate (rule.ts).
 *
 * `\z` becomes `$(?!\n)(?<!\n)` as in the original; patterns with `\A` or `\G` get four variants in which the
 * anchor is replaced by U+FFFF where it must not hold at the current position.
 */
final class RegExpSource
{
    public string $source;
    public bool $hasAnchor = false;
    public bool $hasBackReferences;
    /** @var array{A0_G0:string, A0_G1:string, A1_G0:string, A1_G1:string}|null */
    private ?array $anchorCache = null;

    public function __construct(string $source, public int $ruleId)
    {
        $len = strlen($source);
        $out = '';
        $last = 0;
        for ($pos = 0; $pos < $len; $pos++) {
            if ($source[$pos] === '\\' && $pos + 1 < $len) {
                $next = $source[$pos + 1];
                if ($next === 'z') {
                    $out .= substr($source, $last, $pos - $last) . '$(?!\n)(?<!\n)';
                    $last = $pos + 2;
                } elseif ($next === 'A' || $next === 'G') {
                    $this->hasAnchor = true;
                }
                $pos++;
            }
        }
        $this->source = $last === 0 ? $source : $out . substr($source, $last);
        if ($this->hasAnchor) {
            $this->anchorCache = $this->buildAnchorCache();
        }
        $this->hasBackReferences = (bool) preg_match('/\\\\(\d+)/', $this->source);
    }

    public function cloneSource(): self
    {
        return new self($this->source, $this->ruleId);
    }

    public function setSource(string $source): void
    {
        if ($this->source === $source) {
            return;
        }
        $this->source = $source;
        if ($this->hasAnchor) {
            $this->anchorCache = $this->buildAnchorCache();
        }
    }

    /** @param list<?array{0:int,1:int}> $captures */
    public function resolveBackReferences(string $lineText, array $captures): string
    {
        return (string) preg_replace_callback('/\\\\(\d+)/', static function (array $m) use ($lineText, $captures): string {
            $c = $captures[(int) $m[1]] ?? null;
            $value = $c === null ? '' : substr($lineText, $c[0], $c[1] - $c[0]);
            return self::escapeRegExpCharacters($value);
        }, $this->source);
    }

    public static function escapeRegExpCharacters(string $value): string
    {
        return (string) preg_replace('/[\-\\\\{}*+?|^$.,\[\]()#\x{9}-\x{D}\x{20}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u', '\\\\$0', $value);
    }

    /** @return array{A0_G0:string, A0_G1:string, A1_G0:string, A1_G1:string} */
    private function buildAnchorCache(): array
    {
        $r = ['A0_G0' => '', 'A0_G1' => '', 'A1_G0' => '', 'A1_G1' => ''];
        $s = $this->source;
        $len = strlen($s);
        for ($pos = 0; $pos < $len; $pos++) {
            $ch = $s[$pos];
            foreach ($r as $k => $_) {
                $r[$k] .= $ch;
            }
            if ($ch === '\\' && $pos + 1 < $len) {
                $next = $s[$pos + 1];
                if ($next === 'A') {
                    $r['A0_G0'] .= "\u{FFFF}";
                    $r['A0_G1'] .= "\u{FFFF}";
                    $r['A1_G0'] .= 'A';
                    $r['A1_G1'] .= 'A';
                } elseif ($next === 'G') {
                    $r['A0_G0'] .= "\u{FFFF}";
                    $r['A0_G1'] .= 'G';
                    $r['A1_G0'] .= "\u{FFFF}";
                    $r['A1_G1'] .= 'G';
                } else {
                    foreach ($r as $k => $_) {
                        $r[$k] .= $next;
                    }
                }
                $pos++;
            }
        }
        return $r;
    }

    public function resolveAnchors(bool $allowA, bool $allowG): string
    {
        if (!$this->hasAnchor || $this->anchorCache === null) {
            return $this->source;
        }
        return $this->anchorCache[($allowA ? 'A1' : 'A0') . '_' . ($allowG ? 'G1' : 'G0')];
    }
}

/** List of patterns of a rule context with a compile cache per anchor variant. */
final class RegExpSourceList
{
    /** @var list<RegExpSource> */
    private array $items = [];
    private bool $hasAnchors = false;
    private ?CompiledRule $cached = null;
    /** @var array<string, CompiledRule> */
    private array $anchorCache = [];

    public function push(RegExpSource $item): void
    {
        $this->items[] = $item;
        $this->hasAnchors = $this->hasAnchors || $item->hasAnchor;
    }

    public function unshift(RegExpSource $item): void
    {
        array_unshift($this->items, $item);
        $this->hasAnchors = $this->hasAnchors || $item->hasAnchor;
    }

    public function length(): int
    {
        return count($this->items);
    }

    public function setSource(int $index, string $source): void
    {
        if ($this->items[$index]->source !== $source) {
            $this->cached = null;
            $this->anchorCache = [];
            $this->items[$index]->setSource($source);
        }
    }

    public function compile(): CompiledRule
    {
        return $this->cached ??= new CompiledRule(
            array_map(static fn (RegExpSource $e) => $e->source, $this->items),
            array_map(static fn (RegExpSource $e) => $e->ruleId, $this->items),
        );
    }

    public function compileAG(bool $allowA, bool $allowG): CompiledRule
    {
        if (!$this->hasAnchors) {
            return $this->compile();
        }
        $key = ($allowA ? '1' : '0') . ($allowG ? '1' : '0');
        return $this->anchorCache[$key] ??= new CompiledRule(
            array_map(static fn (RegExpSource $e) => $e->resolveAnchors($allowA, $allowG), $this->items),
            array_map(static fn (RegExpSource $e) => $e->ruleId, $this->items),
        );
    }
}

/** Compiled pattern list with the matching rule ids. */
final class CompiledRule
{
    private Scanner $scanner;

    /**
     * @param list<string> $regExps
     * @param list<int> $rules
     */
    public function __construct(array $regExps, private array $rules)
    {
        $this->scanner = new Scanner($regExps);
    }

    /** @return array{ruleId:int, captures:list<?array{0:int,1:int}>}|null */
    public function findNextMatch(string $string, int $start): ?array
    {
        $r = $this->scanner->findNextMatch($string, $start);
        if ($r === null) {
            return null;
        }
        return ['ruleId' => $this->rules[$r['index']], 'captures' => $r['captures']];
    }
}
