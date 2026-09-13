<?php

declare(strict_types=1);

namespace Pholio\Highlight;

use RuntimeException;

/**
 * Translates Oniguruma patterns (TextMate grammars) to PCRE.
 *
 * The yardstick is not Oniguruma itself but what Fumadocs uses: Shiki's JavaScript engine
 * (oniguruma-to-es 4.3.6 with the rules allowOrphanBackrefs, asciiWordBoundaries, captureGroup, singleline,
 * recursionLimit 5). Its semantics are reproduced here:
 *
 * - `^` = `\A`, `$` = `\Z` = `(?=\n?\z)` (singleline).
 * - `.` = `[^\n]`, with (?m) (Oniguruma dotAll) any character.
 * - `\w` = `[\p{L}\p{M}\p{N}\p{Pc}]`, `\d` = `\p{Nd}`, `\s` = `\p{White_Space}`, `\h` = hex digit.
 * - `\b`/`\B` ASCII only.
 * - POSIX classes like `PosixClassMap` in oniguruma-to-es.
 * - `\G`: if every alternative starts with `\G`, it becomes an anchor at the search start (sticky). Otherwise `\G`
 *   after a fixed-width element never holds, and in all other cases the "clip" strategy applies: the search runs on
 *   the string cut at the start position, and `\G` is its beginning.
 * - Backreferences to groups that are not yet closed or never participate match empty (JavaScript behaviour).
 * - Non-recursive subroutine calls `\g<name>` are inserted as a copy of the group; its captured value is transferred
 *   to the original group as in `EmulatedRegExp`.
 * - Class intersections `&&` and nested classes are expressed with lookaheads.
 */
final class OnigRegex
{
    private const POSIX = ['alnum', 'alpha', 'ascii', 'blank', 'cntrl', 'digit', 'graph', 'lower', 'print', 'punct',
        'space', 'upper', 'word', 'xdigit'];

    private const ASCII_WORD = '[0-9A-Za-z_]';

    /** Unicode properties (slug => PCRE name), derived from JsUnicodePropertyMap. */
    private const PROPERTIES = [
        'c' => 'C', 'other' => 'C', 'cc' => 'Cc', 'control' => 'Cc', 'cntrl' => 'Cc', 'cf' => 'Cf', 'format' => 'Cf',
        'cn' => 'Cn', 'unassigned' => 'Cn', 'co' => 'Co', 'privateuse' => 'Co', 'cs' => 'Cs', 'surrogate' => 'Cs',
        'l' => 'L', 'letter' => 'L', 'lc' => 'L&', 'casedletter' => 'L&', 'll' => 'Ll', 'lowercaseletter' => 'Ll',
        'lm' => 'Lm', 'modifierletter' => 'Lm', 'lo' => 'Lo', 'otherletter' => 'Lo', 'lt' => 'Lt',
        'titlecaseletter' => 'Lt', 'lu' => 'Lu', 'uppercaseletter' => 'Lu', 'm' => 'M', 'mark' => 'M',
        'combiningmark' => 'M', 'mc' => 'Mc', 'spacingmark' => 'Mc', 'me' => 'Me', 'enclosingmark' => 'Me',
        'mn' => 'Mn', 'nonspacingmark' => 'Mn', 'n' => 'N', 'number' => 'N', 'nd' => 'Nd', 'decimalnumber' => 'Nd',
        'digit' => 'Nd', 'nl' => 'Nl', 'letternumber' => 'Nl', 'no' => 'No', 'othernumber' => 'No', 'p' => 'P',
        'punctuation' => 'P', 'punct' => 'P', 'pc' => 'Pc', 'connectorpunctuation' => 'Pc', 'pd' => 'Pd',
        'dashpunctuation' => 'Pd', 'pe' => 'Pe', 'closepunctuation' => 'Pe', 'pf' => 'Pf',
        'finalpunctuation' => 'Pf', 'pi' => 'Pi', 'initialpunctuation' => 'Pi', 'po' => 'Po',
        'otherpunctuation' => 'Po', 'ps' => 'Ps', 'openpunctuation' => 'Ps', 's' => 'S', 'symbol' => 'S',
        'sc' => 'Sc', 'currencysymbol' => 'Sc', 'sk' => 'Sk', 'modifiersymbol' => 'Sk', 'sm' => 'Sm',
        'mathsymbol' => 'Sm', 'so' => 'So', 'othersymbol' => 'So', 'z' => 'Z', 'separator' => 'Z', 'zl' => 'Zl',
        'lineseparator' => 'Zl', 'zp' => 'Zp', 'paragraphseparator' => 'Zp', 'zs' => 'Zs',
        'spaceseparator' => 'Zs', 'alphabetic' => 'Alphabetic', 'alpha' => 'Alphabetic',
        'lowercase' => 'Lowercase', 'lower' => 'Lowercase', 'uppercase' => 'Uppercase', 'upper' => 'Uppercase',
        'whitespace' => 'White_Space', 'space' => 'White_Space', 'any' => 'Any', 'asciihexdigit' => 'AHex',
        'ahex' => 'AHex', 'hexdigit' => 'Hex', 'hex' => 'Hex', 'math' => 'Math', 'dash' => 'Dash',
        'emoji' => 'Emoji', 'idstart' => 'ID_Start', 'ids' => 'ID_Start', 'idcontinue' => 'ID_Continue',
        'idc' => 'ID_Continue', 'xidstart' => 'XID_Start', 'xidcontinue' => 'XID_Continue',
    ];

    /** @var array<string, array{pattern:string, strategy:?string, groups:int, map:list<?int>, transfers:array<int,int>}> */
    private static array $cache = [];

    /** @var list<string> */
    private array $cp;
    private int $pos = 0;
    private int $len;
    private bool $extended = false;
    /** @var list<bool> */
    private array $modX = [];
    private int $totalGroups = 0;
    private int $groupCounter = 0;
    /** @var array<int, array<string,mixed>> capturing groups by number */
    private array $groupsByNum = [];
    /** @var array<string, list<array<string,mixed>>> */
    private array $groupsByName = [];
    private int $highestOrphan = 0;

    // Generation
    private int $pcreGroup = 0;
    /** @var list<?int> PCRE group number - 1 => Oniguruma number or null (hidden) */
    private array $map = [];
    /** @var array<int,int> PCRE number => PCRE number of the original group */
    private array $transfers = [];
    /** @var array<int,int> Oniguruma number => PCRE number (first emission) */
    private array $onigToPcre = [];
    private ?string $strategy = null;
    private bool $cloning = false;
    private int $lookbehindBound = 200;
    private int $lookbehindDepth = 0;
    private bool $unboundedInLookbehind = false;

    /**
     * @return array{pattern:string, strategy:?string, groups:int, map:list<?int>, transfers:array<int,int>}
     */
    public static function translate(string $source): array
    {
        if (isset(self::$cache[$source])) {
            return self::$cache[$source];
        }
        $t = new self($source);
        return self::$cache[$source] = $t->run();
    }

    private function __construct(string $source)
    {
        $this->cp = mb_str_split($source, 1, 'UTF-8');
        $this->len = count($this->cp);
    }

    /** @return array{pattern:string, strategy:?string, groups:int, map:list<?int>, transfers:array<int,int>} */
    private function run(): array
    {
        $this->totalGroups = $this->countCapturingGroups();
        $this->modX = [false];
        $root = ['t' => 'regex', 'alts' => $this->parseAlternatives(true)];
        $this->link($root, null);

        // \G analysis like FirstPassVisitor.Regex.enter
        $sticky = false;
        $hasLead = false;
        $hasNoLead = false;
        $leads = [];
        foreach ($root['alts'] as $i => $alt) {
            if (count($alt['body']) === 1 && $alt['body'][0]['t'] === 'assert' && $alt['body'][0]['kind'] === 'search_start') {
                $root['alts'][$i]['body'] = [];
                continue;
            }
            $lead = $this->leadingG($alt['body']);
            if ($lead !== null) {
                $hasLead = true;
                array_push($leads, ...$lead);
            } else {
                $hasNoLead = true;
            }
        }
        $supported = [];
        if ($hasLead && !$hasNoLead) {
            $sticky = true;
            foreach ($leads as $g) {
                $supported[$g['id']] = true;
            }
        }

        // Unbounded quantifiers in lookbehinds (allowed in JavaScript) need a maximum in PCRE2; the largest bound
        // with which the pattern compiles wins.
        $regex = '';
        foreach ([200, 100, 50, 20, 8] as $bound) {
            $this->lookbehindBound = $bound;
            $this->lookbehindDepth = 0;
            $this->unboundedInLookbehind = false;
            $this->pcreGroup = 0;
            $this->map = [];
            $this->transfers = [];
            $this->onigToPcre = [];
            $this->strategy = null;
            $flags = ['i' => false, 's' => false];
            $body = $this->joinAlts($root['alts'], $flags, $supported);
            // Backreferences without a group: oniguruma-to-es appends empty capturing groups to the last alternative.
            for ($n = $this->groupCounter + 1; $n <= $this->highestOrphan; $n++) {
                $this->pcreGroup++;
                $this->map[] = $n;
                $body .= '()';
            }
            $pattern = $sticky ? '\G(?:' . $body . ')' : $body;
            $regex = '/' . str_replace('/', '\/', $pattern) . '/u';
            if (@preg_match($regex, '') !== false) {
                break;
            }
            if ($this->unboundedInLookbehind && $bound === 8 && version_compare(explode(' ', PCRE_VERSION)[0], '10.43', '<')) {
                // PCRE2 before 10.43 has no variable-length lookbehinds (in the bundled grammars this only affects
                // the end of `using` declarations in JS/TS). Instead of failing the whole grammar, this one
                // pattern never matches.
                trigger_error('Highlight: PCRE2 ' . PCRE_VERSION . ' cannot compile a variable-length lookbehind, pattern '
                    . 'disabled (PCRE2 >= 10.43 required for full parity): ' . mb_substr(implode('', $this->cp), 0, 80), E_USER_WARNING);
                $regex = '/(?!)/u';
                $this->map = array_fill(0, $this->groupCounter, null);
                break;
            }
            if (!$this->unboundedInLookbehind || $bound === 8) {
                throw new RuntimeException('Oniguruma pattern cannot be translated to PCRE: ' . implode('', $this->cp)
                    . ' => ' . $regex . ' (' . preg_last_error_msg() . ')');
            }
        }
        $groups = max($this->groupCounter, $this->highestOrphan);
        return [
            'pattern' => $regex,
            'strategy' => $this->strategy,
            'groups' => $groups,
            'map' => $this->map,
            'transfers' => $this->transfers,
        ];
    }

    private function totalCaptures(): int
    {
        return $this->groupCounter;
    }

    // ------------------------------------------------------------------------------------------------ Parser

    /** Counts capturing groups up front (to tell backreferences from octal escapes, as the tokenizer does). */
    private function countCapturingGroups(): int
    {
        $n = 0;
        $inClass = 0;
        for ($i = 0; $i < $this->len; $i++) {
            $c = $this->cp[$i];
            if ($c === '\\') {
                $i++;
                continue;
            }
            if ($inClass > 0) {
                if ($c === '[') {
                    $inClass++;
                } elseif ($c === ']') {
                    $inClass--;
                }
                continue;
            }
            if ($c === '[') {
                $inClass = 1;
                if (($this->cp[$i + 1] ?? '') === '^') {
                    $i++;
                }
                if (($this->cp[$i + 1] ?? '') === ']') {
                    $i++;
                }
                continue;
            }
            if ($c === '(') {
                $next = $this->cp[$i + 1] ?? '';
                if ($next !== '?' && $next !== '*') {
                    $n++;
                } elseif ($next === '?' && (($this->cp[$i + 2] ?? '') === '\''
                    || (($this->cp[$i + 2] ?? '') === '<' && !in_array($this->cp[$i + 3] ?? '', ['=', '!'], true)))) {
                    $n++;
                }
            }
        }
        return $n;
    }

    /** @return list<array<string,mixed>> */
    private function parseAlternatives(bool $top): array
    {
        $alts = [['t' => 'alt', 'body' => []]];
        while ($this->pos < $this->len) {
            $c = $this->cp[$this->pos];
            if ($c === ')') {
                if ($top) {
                    throw new RuntimeException('Unexpected ")" in ' . implode('', $this->cp));
                }
                return $alts;
            }
            if ($c === '|') {
                $this->pos++;
                $alts[] = ['t' => 'alt', 'body' => []];
                continue;
            }
            $k = count($alts) - 1;
            $this->parseAtomInto($alts[$k]['body']);
        }
        if (!$top) {
            throw new RuntimeException('Unclosed group in ' . implode('', $this->cp));
        }
        return $alts;
    }

    /** @param list<array<string,mixed>> $body */
    private function parseAtomInto(array &$body): void
    {
        $c = $this->cp[$this->pos];
        $ext = end($this->modX);
        if ($ext) {
            if ($c === '#') {
                while ($this->pos < $this->len && $this->cp[$this->pos] !== "\n") {
                    $this->pos++;
                }
                return;
            }
            if (preg_match('/^\s$/u', $c)) {
                $this->pos++;
                return;
            }
        }
        // Quantifiers
        $q = $this->tryQuantifiers();
        if ($q !== null) {
            foreach ($q as $quant) {
                $last = array_pop($body);
                if ($last === null) {
                    throw new RuntimeException('Quantifier without a target in ' . implode('', $this->cp));
                }
                $quant['body'] = $last;
                $body[] = $quant;
            }
            return;
        }
        $this->pos++;
        switch ($c) {
            case '\\':
                $body[] = $this->parseEscape(false);
                return;
            case '[':
                $body[] = $this->parseClass();
                return;
            case '(':
                $node = $this->parseGroup();
                if ($node !== null) {
                    $body[] = $node;
                }
                return;
            case '.':
                $body[] = ['t' => 'set', 'kind' => 'dot', 'negate' => false];
                return;
            case '^':
                $body[] = ['t' => 'assert', 'kind' => 'string_start'];
                return;
            case '$':
                $body[] = ['t' => 'assert', 'kind' => 'string_end_newline'];
                return;
            default:
                $body[] = ['t' => 'char', 'cp' => mb_ord($c, 'UTF-8')];
        }
    }

    /** @return list<array<string,mixed>>|null */
    private function tryQuantifiers(): ?array
    {
        $rest = implode('', array_slice($this->cp, $this->pos, 40));
        if (!preg_match('/^(?:[?*+][?+]?|\{(?:\d+(?:,\d*)?|,\d+)\}\??)+/', $rest, $m)) {
            return null;
        }
        $all = $m[0];
        $this->pos += mb_strlen($all, 'UTF-8');
        $out = [];
        $offset = 0;
        while ($offset < strlen($all)) {
            preg_match('/[?*+][?+]?|\{(?:\d+(?:,\d*)?|,\d+)\}\??/A', $all, $mm, 0, $offset);
            $s = $mm[0];
            if ($s[0] === '{' && preg_match('/^\{(\d+),(\d+)\}\?$/', $s, $r) && (int) $r[1] > (int) $r[2]) {
                $s = substr($s, 0, -1);
            }
            $offset += strlen($s);
            $out[] = $this->quantifierFromString($s);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function quantifierFromString(string $s): array
    {
        if ($s[0] === '{') {
            preg_match('/^\{(\d*)(?:,(\d*))?/', $s, $m);
            $min = (int) $m[1];
            $max = !isset($m[2]) ? $min : ($m[2] === '' ? -1 : (int) $m[2]);
            $kind = null;
            if ($max !== -1 && $min > $max) {
                $kind = 'possessive';
                [$min, $max] = [$max, $min];
            }
            if (str_ends_with($s, '?')) {
                $kind = 'lazy';
            }
            return ['t' => 'quant', 'kind' => $kind ?? 'greedy', 'min' => $min, 'max' => $max];
        }
        $min = $s[0] === '+' ? 1 : 0;
        $max = $s[0] === '?' ? 1 : -1;
        $kind = ($s[1] ?? '') === '+' ? 'possessive' : ((($s[1] ?? '') === '?') ? 'lazy' : 'greedy');
        return ['t' => 'quant', 'kind' => $kind, 'min' => $min, 'max' => $max];
    }

    /** @return array<string,mixed>|null */
    private function parseGroup(): ?array
    {
        $cp = $this->cp;
        $p = $this->pos;
        if (($cp[$p] ?? '') === '*') {
            $end = $p;
            while ($end < $this->len && $cp[$end] !== ')') {
                $end++;
            }
            $name = implode('', array_slice($cp, $p + 1, $end - $p - 1));
            $this->pos = $end + 1;
            if (preg_match('/^FAIL$/i', $name)) {
                return ['t' => 'fail'];
            }
            throw new RuntimeException('Callout not supported: (*' . $name . ')');
        }
        if (($cp[$p] ?? '') !== '?') {
            return $this->finishGroup(['t' => 'cap', 'num' => ++$this->groupCounter, 'name' => null]);
        }
        $n1 = $cp[$p + 1] ?? '';
        $n2 = $cp[$p + 2] ?? '';
        if ($n1 === '#') {
            $i = $p + 2;
            while ($i < $this->len && $cp[$i] !== ')') {
                if ($cp[$i] === '\\') {
                    $i++;
                }
                $i++;
            }
            $this->pos = $i + 1;
            return null;
        }
        if ($n1 === ':') {
            $this->pos = $p + 2;
            return $this->finishGroup(['t' => 'group', 'atomic' => false, 'flags' => null]);
        }
        if ($n1 === '>') {
            $this->pos = $p + 2;
            return $this->finishGroup(['t' => 'group', 'atomic' => true, 'flags' => null]);
        }
        if ($n1 === '=' || $n1 === '!') {
            $this->pos = $p + 2;
            return $this->finishGroup(['t' => 'look', 'behind' => false, 'negate' => $n1 === '!']);
        }
        if ($n1 === '<' && ($n2 === '=' || $n2 === '!')) {
            $this->pos = $p + 3;
            return $this->finishGroup(['t' => 'look', 'behind' => true, 'negate' => $n2 === '!']);
        }
        if ($n1 === '<' || $n1 === '\'') {
            $close = $n1 === '<' ? '>' : '\'';
            $i = $p + 2;
            $name = '';
            while ($i < $this->len && $cp[$i] !== $close) {
                $name .= $cp[$i];
                $i++;
            }
            $this->pos = $i + 1;
            return $this->finishGroup(['t' => 'cap', 'num' => ++$this->groupCounter, 'name' => $name]);
        }
        if ($n1 === '~') {
            $this->pos = $p + 2;
            if (($cp[$this->pos] ?? '') === '|') {
                throw new RuntimeException('Absence function (?~| not supported');
            }
            return $this->finishGroup(['t' => 'absent']);
        }
        // Flags: (?imx-imx) or (?imx-imx:
        $i = $p + 1;
        $s = '';
        while ($i < $this->len && preg_match('/^[-imx]$/', $cp[$i])) {
            $s .= $cp[$i];
            $i++;
        }
        $term = $cp[$i] ?? '';
        if ($s === '' || ($term !== ')' && $term !== ':')) {
            throw new RuntimeException('Group option not supported in ' . implode('', $cp));
        }
        $parts = explode('-', $s, 2);
        $on = $parts[0];
        $off = $parts[1] ?? '';
        $flags = [
            'enable' => ['i' => str_contains($on, 'i'), 's' => str_contains($on, 'm')],
            'disable' => ['i' => str_contains($off, 'i'), 's' => str_contains($off, 'm')],
        ];
        $newX = (end($this->modX) || str_contains($on, 'x')) && !str_contains($off, 'x');
        $this->pos = $i + 1;
        if ($term === ')') {
            $this->modX[count($this->modX) - 1] = $newX;
            return ['t' => 'flags', 'flags' => $flags];
        }
        $this->modX[] = $newX;
        $node = ['t' => 'group', 'atomic' => false, 'flags' => $flags];
        $node['alts'] = $this->parseAlternatives(false);
        $this->pos++;
        array_pop($this->modX);
        return $node;
    }

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private function finishGroup(array $node): array
    {
        if ($node['t'] === 'cap') {
            if ($node['name'] !== null) {
                $this->groupsByName[$node['name']][] = $node['num'];
            }
        }
        $this->modX[] = end($this->modX);
        $node['alts'] = $this->parseAlternatives(false);
        array_pop($this->modX);
        $this->pos++;
        return $node;
    }

    /** @return array<string,mixed> */
    private function parseEscape(bool $inClass): array
    {
        if ($this->pos >= $this->len) {
            throw new RuntimeException('Incomplete escape');
        }
        $c = $this->cp[$this->pos];
        $this->pos++;
        if (!$inClass) {
            switch ($c) {
                case 'A':
                    return ['t' => 'assert', 'kind' => 'string_start'];
                case 'z':
                    return ['t' => 'assert', 'kind' => 'string_end'];
                case 'Z':
                    return ['t' => 'assert', 'kind' => 'string_end_newline'];
                case 'G':
                    return ['t' => 'assert', 'kind' => 'search_start'];
                case 'b':
                case 'B':
                    return ['t' => 'assert', 'kind' => 'word_boundary', 'negate' => $c === 'B'];
                case 'y':
                case 'Y':
                    throw new RuntimeException('\\y not supported');
                case 'K':
                    return ['t' => 'keep'];
                case 'N':
                    return ['t' => 'set', 'kind' => 'newline', 'negate' => true];
                case 'R':
                    return ['t' => 'set', 'kind' => 'newline', 'negate' => false];
                case 'O':
                    return ['t' => 'set', 'kind' => 'any', 'negate' => false];
                case 'X':
                    return ['t' => 'set', 'kind' => 'text_segment', 'negate' => false];
                case 'g':
                case 'k':
                    $open = $this->cp[$this->pos] ?? '';
                    if ($open === '<' || $open === '\'') {
                        $close = $open === '<' ? '>' : '\'';
                        $i = $this->pos + 1;
                        $name = '';
                        while ($i < $this->len && $this->cp[$i] !== $close) {
                            $name .= $this->cp[$i];
                            $i++;
                        }
                        $this->pos = $i + 1;
                        return $c === 'g' ? $this->subroutine($name) : $this->namedBackref($name);
                    }
                    break;
            }
        }
        switch ($c) {
            case 'd':
            case 'D':
                return ['t' => 'set', 'kind' => 'digit', 'negate' => $c === 'D'];
            case 'h':
            case 'H':
                return ['t' => 'set', 'kind' => 'hex', 'negate' => $c === 'H'];
            case 's':
            case 'S':
                return ['t' => 'set', 'kind' => 'space', 'negate' => $c === 'S'];
            case 'w':
            case 'W':
                return ['t' => 'set', 'kind' => 'word', 'negate' => $c === 'W'];
            case 'p':
            case 'P':
                if (($this->cp[$this->pos] ?? '') === '{') {
                    $i = $this->pos + 1;
                    $name = '';
                    while ($i < $this->len && $this->cp[$i] !== '}') {
                        $name .= $this->cp[$i];
                        $i++;
                    }
                    $this->pos = $i + 1;
                    $neg = $c === 'P';
                    if (str_starts_with($name, '^')) {
                        $name = substr($name, 1);
                        $neg = !$neg;
                    }
                    $slug = strtolower((string) preg_replace('/[- _]+/', '', $name));
                    if (in_array($slug, self::POSIX, true) && !isset(self::PROPERTIES[$slug])) {
                        return ['t' => 'set', 'kind' => 'posix', 'value' => $slug, 'negate' => $neg];
                    }
                    return ['t' => 'set', 'kind' => 'property', 'value' => self::PROPERTIES[$slug] ?? $name,
                        'negate' => $neg];
                }
                return $this->char('p' === $c ? 112 : 80);
            case 'c':
            case 'C':
                $n = $c === 'c' ? ($this->cp[$this->pos] ?? '') : ($this->cp[$this->pos + 1] ?? '');
                $this->pos += $c === 'c' ? 1 : 2;
                return $this->char(mb_ord(strtoupper($n)) - 64);
            case 'u':
                $hex = implode('', array_slice($this->cp, $this->pos, 4));
                if (!preg_match('/^[0-9A-Fa-f]{4}$/', $hex)) {
                    throw new RuntimeException('Invalid \\u escape');
                }
                $this->pos += 4;
                return $this->char((int) hexdec($hex));
            case 'x':
                if (($this->cp[$this->pos] ?? '') === '{') {
                    $i = $this->pos + 1;
                    $hex = '';
                    while ($i < $this->len && $this->cp[$i] !== '}') {
                        $hex .= $this->cp[$i];
                        $i++;
                    }
                    $this->pos = $i + 1;
                    return $this->char((int) hexdec(trim($hex)));
                }
                $hex = '';
                while (strlen($hex) < 2 && preg_match('/^[0-9A-Fa-f]$/', $this->cp[$this->pos] ?? '')) {
                    $hex .= $this->cp[$this->pos];
                    $this->pos++;
                }
                if ($hex === '') {
                    throw new RuntimeException('Invalid \\x escape');
                }
                $value = (int) hexdec($hex);
                if ($value >= 0x80 && strlen($hex) === 2) {
                    // Decode a multi-byte sequence \xE4\xB8... as UTF-8
                    $bytes = chr($value);
                    while (($this->cp[$this->pos] ?? '') === '\\' && ($this->cp[$this->pos + 1] ?? '') === 'x'
                        && preg_match('/^[89A-Fa-f][0-9A-Fa-f]$/', ($this->cp[$this->pos + 2] ?? '') . ($this->cp[$this->pos + 3] ?? ''))) {
                        $bytes .= chr((int) hexdec($this->cp[$this->pos + 2] . $this->cp[$this->pos + 3]));
                        $this->pos += 4;
                    }
                    if (!mb_check_encoding($bytes, 'UTF-8')) {
                        throw new RuntimeException('Invalid multi-byte sequence');
                    }
                    $chars = mb_str_split($bytes, 1, 'UTF-8');
                    if (count($chars) === 1) {
                        return $this->char(mb_ord($chars[0], 'UTF-8'));
                    }
                    $nodes = array_map(fn ($ch) => $this->char(mb_ord($ch, 'UTF-8')), $chars);
                    return ['t' => 'seq', 'body' => $nodes];
                }
                return $this->char($value);
            case 'a':
                return $this->char(7);
            case 'b':
                return $this->char(8);
            case 'e':
                return $this->char(27);
            case 'f':
                return $this->char(12);
            case 'n':
                return $this->char(10);
            case 'r':
                return $this->char(13);
            case 't':
                return $this->char(9);
            case 'v':
                return $this->char(11);
        }
        if (ctype_digit($c)) {
            $digits = $c;
            while (strlen($digits) < 3 && ctype_digit($this->cp[$this->pos] ?? 'x')) {
                $digits .= $this->cp[$this->pos];
                $this->pos++;
            }
            if (!$inClass && (($digits !== '0' && strlen($digits) === 1) || ($digits[0] !== '0' && (int) $digits <= $this->totalGroups))) {
                return $this->numberedBackref((int) $digits);
            }
            preg_match_all('/^[0-7]+|\d/', $digits, $m);
            $nodes = [];
            foreach ($m[0] as $k => $part) {
                if ($k === 0 && $part !== '8' && $part !== '9') {
                    $nodes[] = $this->char((int) octdec($part));
                } else {
                    $nodes[] = $this->char(ord($part));
                }
            }
            return count($nodes) === 1 ? $nodes[0] : ['t' => 'seq', 'body' => $nodes];
        }
        return $this->char(mb_ord($c, 'UTF-8'));
    }

    /** @return array<string,mixed> */
    private function char(int $cp): array
    {
        return ['t' => 'char', 'cp' => $cp];
    }

    /** @return array<string,mixed> */
    private function numberedBackref(int $n): array
    {
        if ($n > $this->groupCounter) {
            $this->highestOrphan = max($this->highestOrphan, $n);
            return ['t' => 'bref', 'refs' => [], 'orphan' => true];
        }
        return ['t' => 'bref', 'refs' => [$n], 'orphan' => false];
    }

    /** @return array<string,mixed> */
    private function namedBackref(string $name): array
    {
        if (preg_match('/^(-?)0*([1-9]\d*)$/', $name, $m)) {
            $n = (int) $m[2];
            return $this->numberedBackref($m[1] === '-' ? $this->groupCounter + 1 - $n : $n);
        }
        return ['t' => 'bref', 'refs' => $this->groupsByName[$name] ?? [], 'orphan' => false];
    }

    /** @return array<string,mixed> */
    private function subroutine(string $name): array
    {
        if (preg_match('/^([-+]?)0*([1-9]\d*)$/', $name, $m)) {
            $n = (int) $m[2];
            $ref = ['' => $n, '+' => $this->groupCounter + $n, '-' => $this->groupCounter + 1 - $n][$m[1]];
            return ['t' => 'sub', 'ref' => $ref];
        }
        if ($name === '0') {
            return ['t' => 'sub', 'ref' => 0];
        }
        return ['t' => 'sub', 'ref' => $name];
    }

    /** @return array<string,mixed> */
    private function parseClass(): array
    {
        $negate = false;
        if (($this->cp[$this->pos] ?? '') === '^') {
            $negate = true;
            $this->pos++;
        }
        $segments = [[]];
        $first = true;
        while (true) {
            if ($this->pos >= $this->len) {
                throw new RuntimeException('Unclosed character class in ' . implode('', $this->cp));
            }
            $c = $this->cp[$this->pos];
            $seg = count($segments) - 1;
            if ($c === ']' && !$first) {
                $this->pos++;
                break;
            }
            $wasFirst = $first;
            $first = false;
            if ($c === ']' && $wasFirst) {
                $this->pos++;
                $segments[$seg][] = $this->char(93);
                continue;
            }
            if ($c === '&' && ($this->cp[$this->pos + 1] ?? '') === '&') {
                $this->pos += 2;
                $segments[] = [];
                $first = false;
                continue;
            }
            if ($c === '[') {
                $rest = implode('', array_slice($this->cp, $this->pos, 12));
                if (preg_match('/^\[:(\^?)([A-Za-z]+):\]/', $rest, $m)) {
                    $this->pos += mb_strlen($m[0]);
                    $segments[$seg][] = ['t' => 'set', 'kind' => 'posix', 'value' => $m[2], 'negate' => $m[1] === '^'];
                    continue;
                }
                $this->pos++;
                $segments[$seg][] = $this->parseClass();
                continue;
            }
            if ($c === '-') {
                $body = &$segments[$seg];
                $prev = $body ? $body[count($body) - 1] : null;
                $next = $this->cp[$this->pos + 1] ?? '';
                $nextIsSpecial = $next === ']' || $next === '' || ($next === '&' && ($this->cp[$this->pos + 2] ?? '') === '&')
                    || ($next === '[' && !preg_match('/^\[:\^?[A-Za-z]+:\]/', implode('', array_slice($this->cp, $this->pos + 1, 12))));
                if ($prev !== null && $prev['t'] === 'char' && empty($prev['rangeDone']) && !$nextIsSpecial) {
                    $this->pos++;
                    $end = $this->classAtom();
                    if ($end['t'] === 'char') {
                        array_pop($body);
                        $body[] = ['t' => 'range', 'min' => $prev['cp'], 'max' => $end['cp']];
                        unset($body);
                        continue;
                    }
                    $body[] = $this->char(45);
                    $body[] = $end;
                    unset($body);
                    continue;
                }
                unset($body);
                $this->pos++;
                $segments[$seg][] = $this->char(45);
                continue;
            }
            $segments[$seg][] = $this->classAtom();
        }
        if (count($segments) === 1) {
            return ['t' => 'class', 'negate' => $negate, 'kind' => 'union', 'body' => $segments[0]];
        }
        $body = [];
        foreach ($segments as $s) {
            $body[] = count($s) === 1 ? $s[0] : ['t' => 'class', 'negate' => false, 'kind' => 'union', 'body' => $s];
        }
        return ['t' => 'class', 'negate' => $negate, 'kind' => 'intersection', 'body' => $body];
    }

    /** @return array<string,mixed> */
    private function classAtom(): array
    {
        $c = $this->cp[$this->pos];
        $this->pos++;
        if ($c === '\\') {
            $node = $this->parseEscape(true);
            if ($node['t'] === 'seq') {
                // Multi-byte sequence in a class: only the first character here; the rest as separate elements is rare
                return $node['body'][0];
            }
            return $node;
        }
        return $this->char(mb_ord($c, 'UTF-8'));
    }

    // ---------------------------------------------------------------------------------- Structure analysis

    private int $nextId = 0;
    /** @var array<int, array{t:string, parent:?int, kids:list<int>}> */
    private array $info = [];

    /**
     * Assigns ids and parent links (for the \G and backreference analysis) and refills the group register.
     *
     * @param array<string,mixed> $node
     */
    private function link(array &$node, ?int $parent): void
    {
        $node['id'] = ++$this->nextId;
        $id = $node['id'];
        $this->info[$id] = ['t' => $node['t'], 'parent' => $parent, 'kids' => []];
        if ($parent !== null) {
            $this->info[$parent]['kids'][] = $id;
        }
        if (isset($node['alts'])) {
            foreach ($node['alts'] as &$alt) {
                $alt['id'] = ++$this->nextId;
                $this->info[$alt['id']] = ['t' => 'alt', 'parent' => $id, 'kids' => []];
                $this->info[$id]['kids'][] = $alt['id'];
                foreach ($alt['body'] as &$child) {
                    $this->link($child, $alt['id']);
                }
                unset($child);
            }
            unset($alt);
        }
        if ($node['t'] === 'quant') {
            $this->link($node['body'], $id);
        }
        if ($node['t'] === 'cap') {
            $this->groupsByNum[$node['num']] = $node;
        }
    }

    /** Like canParticipateWithNode in oniguruma-to-es: is the group to the left of the backreference? */
    private function canParticipate(int $captureId, int $refId): bool
    {
        $cur = $refId;
        while ($cur !== null) {
            $info = $this->info[$cur];
            if ($info['t'] === 'regex') {
                return false;
            }
            if ($info['t'] !== 'alt') {
                if ($cur === $captureId) {
                    return false;
                }
                foreach ($this->info[$info['parent']]['kids'] as $kid) {
                    if ($kid === $cur) {
                        break;
                    }
                    if ($kid === $captureId || $this->isAncestor($kid, $captureId)) {
                        return true;
                    }
                }
            }
            $cur = $info['parent'];
        }
        return false;
    }

    private function isAncestor(int $ancestor, int $id): bool
    {
        $p = $this->info[$id]['parent'];
        while ($p !== null) {
            if ($p === $ancestor) {
                return true;
            }
            $p = $this->info[$p]['parent'];
        }
        return false;
    }

    /**
     * @param list<array<string,mixed>> $els
     * @return list<array<string,mixed>>|null
     */
    private function leadingG(array $els): ?array
    {
        foreach ($els as $el) {
            if ($el['t'] === 'assert' && $el['kind'] === 'search_start') {
                return [$el];
            }
            if ($el['t'] === 'look' && !$el['negate'] && count($el['alts']) === 1 && count($el['alts'][0]['body']) === 1
                && $el['alts'][0]['body'][0]['t'] === 'assert' && $el['alts'][0]['body'][0]['kind'] === 'search_start') {
                return [$el['alts'][0]['body'][0]];
            }
            if (in_array($el['t'], ['assert', 'flags', 'keep', 'look'], true)) {
                continue;
            }
            if ($el['t'] === 'cap' || $el['t'] === 'group') {
                $all = [];
                foreach ($el['alts'] as $alt) {
                    $g = $this->leadingG($alt['body']);
                    if ($g === null) {
                        return null;
                    }
                    array_push($all, ...$g);
                }
                return $all;
            }
            return null;
        }
        return null;
    }

    /** @param array<string,mixed> $n */
    private static function isAlwaysNonZero(array $n): bool
    {
        $types = ['char', 'class', 'set'];
        if (in_array($n['t'], $types, true)) {
            // \R and \X become groups in oniguruma-to-es
            return !($n['t'] === 'set' && (($n['kind'] === 'newline' && !$n['negate']) || $n['kind'] === 'text_segment'));
        }
        return $n['t'] === 'quant' && $n['min'] > 0 && in_array($n['body']['t'], $types, true);
    }

    // ------------------------------------------------------------------------------------------ Generation

    /**
     * @param list<array<string,mixed>> $alts
     * @param array{i:bool,s:bool} $flags
     * @param array<int,bool> $supported
     * @param list<int> $ancestors
     */
    private function joinAlts(array $alts, array &$flags, array $supported, array $ancestors = []): string
    {
        $out = [];
        $start = $flags;
        foreach ($alts as $alt) {
            $f = $flags;
            $out[] = $this->genSeq($alt['body'], $f, $supported, $ancestors);
            // Directives in this alternative keep applying to the following ones
            $flags = $f;
        }
        $flags = $start;
        return implode('|', $out);
    }

    /**
     * @param list<array<string,mixed>> $body
     * @param array{i:bool,s:bool} $flags
     * @param array<int,bool> $supported
     * @param list<int> $ancestors
     */
    private function genSeq(array $body, array &$flags, array $supported, array $ancestors = []): string
    {
        $s = '';
        foreach ($body as $k => $node) {
            if ($node['t'] === 'flags') {
                $f = $node['flags'];
                foreach (['i', 's'] as $key) {
                    if ($f['enable'][$key]) {
                        $flags[$key] = true;
                    }
                    if ($f['disable'][$key]) {
                        $flags[$key] = false;
                    }
                }
                $mods = ($f['enable']['i'] ? 'i' : '') . ($f['disable']['i'] ? '-i' : '');
                if ($mods !== '') {
                    $s .= '(?' . $mods . ')';
                }
                continue;
            }
            if ($node['t'] === 'assert' && $node['kind'] === 'search_start') {
                if (isset($supported[$node['id']])) {
                    continue;
                }
                $prev = $body[$k - 1] ?? null;
                if ($prev !== null && self::isAlwaysNonZero($prev)) {
                    $s .= '(?!)';
                } else {
                    $s .= '\A';
                    $this->strategy = 'clip';
                }
                continue;
            }
            $s .= $this->gen($node, $flags, $supported, $ancestors, array_slice($body, 0, $k));
        }
        return $s;
    }

    /**
     * @param array<string,mixed> $node
     * @param array{i:bool,s:bool} $flags
     * @param array<int,bool> $supported
     * @param list<int> $ancestors
     * @param list<array<string,mixed>> $leftSiblings
     */
    private function gen(array $node, array $flags, array $supported, array $ancestors, array $leftSiblings): string
    {
        switch ($node['t']) {
            case 'char':
                return self::lit($node['cp']);
            case 'seq':
                $s = '';
                foreach ($node['body'] as $c) {
                    $s .= self::lit($c['cp']);
                }
                return '(?:' . $s . ')';
            case 'set':
                return $this->setToRegex($node, $flags);
            case 'class':
                return $this->classToRegex($node);
            case 'assert':
                return match ($node['kind']) {
                    'string_start' => '\A',
                    'string_end' => '\z',
                    'string_end_newline' => '(?=\n?\z)',
                    'word_boundary' => $node['negate']
                        ? '(?:(?<=' . self::ASCII_WORD . ')(?=' . self::ASCII_WORD . ')|(?<!' . self::ASCII_WORD . ')(?!' . self::ASCII_WORD . '))'
                        : '(?:(?<=' . self::ASCII_WORD . ')(?!' . self::ASCII_WORD . ')|(?<!' . self::ASCII_WORD . ')(?=' . self::ASCII_WORD . '))',
                    default => throw new RuntimeException('Unknown assertion ' . $node['kind']),
                };
            case 'keep':
                return '\K';
            case 'fail':
                return '(?!)';
            case 'group':
                $f = $flags;
                $prefix = $node['atomic'] ? '?>' : '?';
                $mods = '';
                if ($node['flags'] !== null) {
                    foreach (['i', 's'] as $key) {
                        if ($node['flags']['enable'][$key]) {
                            $f[$key] = true;
                        }
                        if ($node['flags']['disable'][$key]) {
                            $f[$key] = false;
                        }
                    }
                    $mods = ($node['flags']['enable']['i'] ? 'i' : '') . ($node['flags']['disable']['i'] ? '-i' : '');
                }
                $inner = $this->joinAlts($node['alts'], $f, $supported, [...$ancestors, $node['id']]);
                return $node['atomic'] ? '(?>' . $inner . ')' : '(?' . $mods . ':' . $inner . ')';
            case 'look':
                $f = $flags;
                if ($node['behind']) {
                    $this->lookbehindDepth++;
                }
                $inner = $this->joinAlts($node['alts'], $f, $supported, [...$ancestors, $node['id']]);
                if ($node['behind']) {
                    $this->lookbehindDepth--;
                }
                return '(?' . ($node['behind'] ? '<' : '') . ($node['negate'] ? '!' : '=') . $inner . ')';
            case 'absent':
                $f = $flags;
                $inner = $this->joinAlts($node['alts'], $f, $supported, [...$ancestors, $node['id']]);
                return '(?:(?:(?!' . $inner . ')(?s:.))*)';
            case 'cap':
                $num = ++$this->pcreGroup;
                if ($this->cloning) {
                    $this->map[] = null;
                    if (isset($this->onigToPcre[$node['num']])) {
                        $this->transfers[$num] = $this->onigToPcre[$node['num']];
                    }
                } else {
                    $this->map[] = $node['num'];
                    $this->onigToPcre[$node['num']] = $num;
                }
                $f = $flags;
                $inner = $this->joinAlts($node['alts'], $f, $supported, [...$ancestors, $node['id']]);
                return '(' . $inner . ')';
            case 'quant':
                $inner = $this->gen($node['body'], $flags, $supported, [...$ancestors, $node['id']], []);
                if ($node['body']['t'] === 'quant' || $node['body']['t'] === 'flags') {
                    $inner = '(?:' . $inner . ')';
                }
                if ($node['max'] === -1 && $this->lookbehindDepth > 0) {
                    $this->unboundedInLookbehind = true;
                    $node['max'] = max($this->lookbehindBound, $node['min']);
                }
                $q = match (true) {
                    $node['min'] === 0 && $node['max'] === 1 => '?',
                    $node['min'] === 0 && $node['max'] === -1 => '*',
                    $node['min'] === 1 && $node['max'] === -1 => '+',
                    $node['min'] === $node['max'] => '{' . $node['min'] . '}',
                    default => '{' . $node['min'] . ',' . ($node['max'] === -1 ? '' : $node['max']) . '}',
                };
                return $inner . $q . ['greedy' => '', 'lazy' => '?', 'possessive' => '+'][$node['kind']];
            case 'bref':
                if ($node['orphan']) {
                    return '(?:)';
                }
                $parts = [];
                foreach (array_reverse($node['refs']) as $ref) {
                    if (isset($this->groupsByNum[$ref], $this->onigToPcre[$ref], $node['id'])
                        && $this->canParticipate($this->groupsByNum[$ref]['id'], $node['id'])) {
                        $g = $this->onigToPcre[$ref];
                        $parts[] = '(?(' . $g . ')\g{' . $g . '})';
                    }
                }
                if ($parts === []) {
                    return '(?!)';
                }
                return count($parts) === 1 ? $parts[0] : '(?>' . implode('|', $parts) . ')';
            case 'sub':
                $ref = $node['ref'];
                if (is_string($ref)) {
                    $nums = $this->groupsByName[$ref] ?? [];
                    if (count($nums) !== 1) {
                        throw new RuntimeException('Subroutine call to an unknown or duplicate name ' . $ref);
                    }
                    $ref = $nums[0];
                }
                if ($ref === 0 || in_array($this->groupsByNum[$ref]['id'], $ancestors, true)) {
                    return $ref === 0 ? '(?R)' : '(?' . $this->onigToPcre[$ref] . ')';
                }
                $target = $this->groupsByNum[$ref];
                $was = $this->cloning;
                $this->cloning = true;
                $f = $flags;
                $out = $this->gen($target, $f, $supported, $ancestors, []);
                $this->cloning = $was;
                return $out;
        }
        throw new RuntimeException('Unknown node ' . $node['t']);
    }

    private static function lit(int $cp): string
    {
        if (($cp >= 48 && $cp <= 57) || ($cp >= 65 && $cp <= 90) || ($cp >= 97 && $cp <= 122)) {
            return chr($cp);
        }
        return sprintf('\x{%X}', $cp);
    }

    /**
     * Character set as a class fragment: ['pos' => fragment for [..], 'neg' => fragment of the complement] or
     * ['re' => complete expression that matches exactly one character].
     *
     * @param array<string,mixed> $node
     * @param array{i:bool,s:bool} $flags
     * @return array{pos?:string, neg?:string, re?:string}
     */
    private function setFragment(array $node, array $flags = ['i' => false, 's' => false]): array
    {
        $kind = $node['kind'];
        $r = match ($kind) {
            'digit' => ['pos' => '\p{Nd}', 'neg' => '\P{Nd}'],
            'hex' => ['pos' => '0-9A-Fa-f'],
            'space' => ['pos' => '\p{White_Space}', 'neg' => '\P{White_Space}'],
            'word' => ['pos' => '\p{L}\p{M}\p{N}\p{Pc}'],
            'dot' => $flags['s'] ? ['re' => '(?s:.)'] : ['neg' => '\n'],
            'any' => ['re' => '(?s:.)'],
            'property' => ['pos' => '\p{' . $node['value'] . '}', 'neg' => '\P{' . $node['value'] . '}'],
            'posix' => match ($node['value']) {
                'alnum' => ['pos' => '\p{Alphabetic}\p{Nd}'],
                'alpha' => ['pos' => '\p{Alphabetic}', 'neg' => '\P{Alphabetic}'],
                'ascii' => ['pos' => '\x{0}-\x{7F}', 'neg' => '\x{80}-\x{10FFFF}'],
                'blank' => ['pos' => '\p{Zs}\t'],
                'cntrl' => ['pos' => '\p{Cc}', 'neg' => '\P{Cc}'],
                'digit' => ['pos' => '\p{Nd}', 'neg' => '\P{Nd}'],
                'graph' => ['neg' => '\p{White_Space}\p{Cc}\p{Cn}\p{Cs}'],
                'lower' => ['pos' => '\p{Lowercase}', 'neg' => '\P{Lowercase}'],
                'print' => ['re' => '(?:[^\p{White_Space}\p{Cc}\p{Cn}\p{Cs}]|\p{Zs})'],
                'punct' => ['pos' => '\p{P}\p{S}'],
                'space' => ['pos' => '\p{White_Space}', 'neg' => '\P{White_Space}'],
                'upper' => ['pos' => '\p{Uppercase}', 'neg' => '\P{Uppercase}'],
                'word' => ['pos' => '\p{Alphabetic}\p{M}\p{Nd}\p{Pc}'],
                'xdigit' => ['pos' => '0-9A-Fa-f'],
                default => throw new RuntimeException('Unknown POSIX class ' . $node['value']),
            },
            'newline' => $node['negate'] ? ['neg' => '\n'] : ['re' => '(?>\r\n?|[\n\x{B}\f\x{85}\x{2028}\x{2029}])'],
            'text_segment' => ['re' => '(?>\r\n|\P{M}\p{M}*)'],
            default => throw new RuntimeException('Unknown character set ' . $kind),
        };
        if ($kind === 'newline') {
            return $r;
        }
        if (!empty($node['negate'])) {
            return self::negateFragment($r);
        }
        return $r;
    }

    /**
     * @param array{pos?:string, neg?:string, re?:string} $r
     * @return array{pos?:string, neg?:string, re?:string}
     */
    private static function negateFragment(array $r): array
    {
        if (isset($r['re'])) {
            return ['re' => '(?:(?!' . $r['re'] . ')(?s:.))'];
        }
        $out = [];
        if (isset($r['neg'])) {
            $out['pos'] = $r['neg'];
        }
        if (isset($r['pos'])) {
            $out['neg'] = $r['pos'];
        }
        return $out;
    }

    /** @param array{pos?:string, neg?:string, re?:string} $r */
    private static function fragmentRegex(array $r): string
    {
        if (isset($r['pos'])) {
            return '[' . $r['pos'] . ']';
        }
        if (isset($r['neg'])) {
            return '[^' . $r['neg'] . ']';
        }
        return $r['re'];
    }

    /**
     * @param array<string,mixed> $node
     * @param array{i:bool,s:bool} $flags
     */
    private function setToRegex(array $node, array $flags): string
    {
        return self::fragmentRegex($this->setFragment($node, $flags));
    }

    /** @param array<string,mixed> $node */
    private function classToRegex(array $node): string
    {
        return self::fragmentRegex($this->classFragment($node));
    }

    /**
     * @param array<string,mixed> $node
     * @return array{pos?:string, neg?:string, re?:string}
     */
    private function classFragment(array $node): array
    {
        if ($node['kind'] === 'intersection') {
            $res = [];
            foreach ($node['body'] as $item) {
                $res[] = self::fragmentRegex($this->itemFragment($item));
            }
            $last = array_pop($res);
            $re = '(?:';
            foreach ($res as $x) {
                $re .= '(?=' . $x . ')';
            }
            $r = ['re' => $re . $last . ')'];
            return $node['negate'] ? self::negateFragment($r) : $r;
        }
        $pos = '';
        $complex = [];
        foreach ($node['body'] as $item) {
            $f = $this->itemFragment($item);
            if (isset($f['pos'])) {
                $pos .= $f['pos'];
            } elseif (isset($f['neg'])) {
                $complex[] = '[^' . $f['neg'] . ']';
            } else {
                $complex[] = $f['re'];
            }
        }
        if ($complex === []) {
            if ($pos === '') {
                // empty class: never matches (negated: any character)
                return $node['negate'] ? ['re' => '(?s:.)'] : ['re' => '(?!)'];
            }
            return $node['negate'] ? ['neg' => $pos] : ['pos' => $pos];
        }
        if ($pos !== '') {
            array_unshift($complex, '[' . $pos . ']');
        }
        $r = ['re' => '(?:' . implode('|', $complex) . ')'];
        return $node['negate'] ? self::negateFragment($r) : $r;
    }

    /**
     * @param array<string,mixed> $item
     * @return array{pos?:string, neg?:string, re?:string}
     */
    private function itemFragment(array $item): array
    {
        return match ($item['t']) {
            'char' => ['pos' => self::lit($item['cp'])],
            'range' => ['pos' => self::lit($item['min']) . '-' . self::lit($item['max'])],
            'set' => $this->setFragment($item),
            'class' => $this->classFragment($item),
            default => throw new RuntimeException('Unexpected class element ' . $item['t']),
        };
    }
}
