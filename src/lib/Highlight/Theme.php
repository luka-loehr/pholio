<?php

declare(strict_types=1);

namespace Pholio\Highlight;

/**
 * TextMate theme as in vscode-textmate (theme.ts) including Shiki's normalisation (normalize-theme.ts).
 *
 * The rules go into a trie by scope segments; `match()` returns the effective rule for a scope path (depth, then
 * specificity of the parent scopes). As in ColorMap, colours are kept in upper case, which is why the token spans
 * carry `#D73A49` while the foreground and background of the `pre` come from the raw theme.
 */
final class Theme
{
    public const FONT_NOT_SET = -1;
    public const FONT_ITALIC = 1;
    public const FONT_BOLD = 2;
    public const FONT_UNDERLINE = 4;
    public const FONT_STRIKETHROUGH = 8;

    public string $name;
    public string $fg;
    public string $bg;
    /** @var array<string,string> */
    public array $colorReplacements = [];

    /** @var list<string> colour id => colour (index 0 unused) */
    private array $id2color = [];
    /** @var array<string,int> */
    private array $color2id = [];
    private int $lastColorId = 0;

    /** @var array{0:int,1:int,2:int} fontStyle, foreground, background */
    private array $defaults;
    private ThemeTrieElement $root;
    /** @var array<string, list<ThemeTrieRule>> */
    private array $matchCache = [];

    /** @param array<string,mixed> $raw */
    public function __construct(array $raw)
    {
        $raw = self::normalize($raw);
        $this->name = (string) ($raw['name'] ?? '');
        $this->fg = (string) $raw['fg'];
        $this->bg = (string) $raw['bg'];
        $this->colorReplacements = $raw['colorReplacements'];
        $this->build($raw['settings']);
    }

    public static function fromFile(string $path): self
    {
        return new self(json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * normalizeTheme from @shikijs/primitive.
     *
     * @param array<string,mixed> $theme
     * @return array<string,mixed>
     */
    private static function normalize(array $theme): array
    {
        if (isset($theme['tokenColors']) && !isset($theme['settings'])) {
            $theme['settings'] = $theme['tokenColors'];
            unset($theme['tokenColors']);
        }
        $theme['type'] = ($theme['type'] ?? '') !== '' ? $theme['type'] : 'dark';
        $theme['colorReplacements'] = $theme['colorReplacements'] ?? [];
        $theme['settings'] = $theme['settings'] ?? [];
        $bg = $theme['bg'] ?? null;
        $fg = $theme['fg'] ?? null;
        if (!$bg || !$fg) {
            $global = null;
            foreach ($theme['settings'] as $s) {
                if (empty($s['name']) && empty($s['scope'])) {
                    $global = $s;
                    break;
                }
            }
            if (!empty($global['settings']['foreground'])) {
                $fg = $global['settings']['foreground'];
            }
            if (!empty($global['settings']['background'])) {
                $bg = $global['settings']['background'];
            }
            if (!$fg && !empty($theme['colors']['editor.foreground'])) {
                $fg = $theme['colors']['editor.foreground'];
            }
            if (!$bg && !empty($theme['colors']['editor.background'])) {
                $bg = $theme['colors']['editor.background'];
            }
            if (!$fg) {
                $fg = $theme['type'] === 'light' ? '#333333' : '#bbbbbb';
            }
            if (!$bg) {
                $bg = $theme['type'] === 'light' ? '#fffffe' : '#1e1e1e';
            }
            $theme['fg'] = $fg;
            $theme['bg'] = $bg;
        }
        $first = $theme['settings'][0] ?? null;
        if (!($first !== null && isset($first['settings']) && empty($first['scope']))) {
            array_unshift($theme['settings'], ['settings' => ['foreground' => $theme['fg'], 'background' => $theme['bg']]]);
        }
        // Replace non-hex colours (e.g. CSS variables); the GitHub themes contain none, the path is kept for
        // completeness.
        $count = 0;
        $map = [];
        $replace = static function (string $value) use (&$count, &$map, &$theme): string {
            if (isset($map[$value])) {
                return $map[$value];
            }
            $count++;
            $hex = '#' . str_pad(dechex($count), 8, '0', STR_PAD_LEFT);
            $map[$value] = $hex;
            $theme['colorReplacements'][$hex] = $value;
            return $hex;
        };
        foreach ($theme['settings'] as $i => $setting) {
            foreach (['foreground', 'background'] as $key) {
                $v = $setting['settings'][$key] ?? null;
                if (is_string($v) && $v !== '' && $v[0] !== '#') {
                    $theme['settings'][$i]['settings'][$key] = $replace($v);
                }
            }
        }
        return $theme;
    }

    /** @param list<array<string,mixed>> $settings */
    private function build(array $settings): void
    {
        // parseTheme
        $rules = [];
        foreach ($settings as $i => $entry) {
            if (!isset($entry['settings'])) {
                continue;
            }
            if (isset($entry['scope']) && is_string($entry['scope'])) {
                $scope = (string) preg_replace(['/^[,]+/', '/[,]+$/'], '', $entry['scope']);
                $scopes = explode(',', $scope);
            } elseif (isset($entry['scope']) && is_array($entry['scope'])) {
                $scopes = $entry['scope'];
            } else {
                $scopes = [''];
            }
            $fontStyle = self::FONT_NOT_SET;
            if (isset($entry['settings']['fontStyle']) && is_string($entry['settings']['fontStyle'])) {
                $fontStyle = 0;
                foreach (explode(' ', $entry['settings']['fontStyle']) as $seg) {
                    $fontStyle |= match ($seg) {
                        'italic' => self::FONT_ITALIC,
                        'bold' => self::FONT_BOLD,
                        'underline' => self::FONT_UNDERLINE,
                        'strikethrough' => self::FONT_STRIKETHROUGH,
                        default => 0,
                    };
                }
            }
            $fg = self::validColor($entry['settings']['foreground'] ?? null);
            $bg = self::validColor($entry['settings']['background'] ?? null);
            foreach ($scopes as $s) {
                $segments = explode(' ', trim((string) $s));
                $last = array_pop($segments);
                $parents = null;
                if (count($segments) > 0) {
                    $parents = array_reverse($segments);
                }
                $rules[] = ['scope' => $last, 'parents' => $parents, 'index' => $i, 'font' => $fontStyle,
                    'fg' => $fg, 'bg' => $bg];
            }
        }
        // resolveParsedThemeRules
        usort($rules, static function (array $a, array $b): int {
            $r = strcmp($a['scope'], $b['scope']) <=> 0;
            if ($r !== 0) {
                return $r;
            }
            $r = self::strArrCmp($a['parents'], $b['parents']);
            if ($r !== 0) {
                return $r;
            }
            return $a['index'] <=> $b['index'];
        });
        $defFont = 0;
        $defFg = '#000000';
        $defBg = '#ffffff';
        while ($rules !== [] && $rules[0]['scope'] === '') {
            $d = array_shift($rules);
            if ($d['font'] !== self::FONT_NOT_SET) {
                $defFont = $d['font'];
            }
            if ($d['fg'] !== null) {
                $defFg = $d['fg'];
            }
            if ($d['bg'] !== null) {
                $defBg = $d['bg'];
            }
        }
        $this->defaults = [$defFont, $this->colorId($defFg), $this->colorId($defBg)];
        $this->root = new ThemeTrieElement(new ThemeTrieRule(0, null, self::FONT_NOT_SET, 0, 0), []);
        foreach ($rules as $rule) {
            $this->root->insert(0, $rule['scope'], $rule['parents'], $rule['font'], $this->colorId($rule['fg']),
                $this->colorId($rule['bg']));
        }
    }

    private static function validColor(mixed $c): ?string
    {
        if (is_string($c) && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $c)) {
            return $c;
        }
        return null;
    }

    /**
     * @param list<string>|null $a
     * @param list<string>|null $b
     */
    public static function strArrCmp(?array $a, ?array $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if (!$a) {
            return -1;
        }
        if (!$b) {
            return 1;
        }
        if (count($a) === count($b)) {
            foreach ($a as $i => $x) {
                $r = strcmp($x, $b[$i]) <=> 0;
                if ($r !== 0) {
                    return $r;
                }
            }
            return 0;
        }
        return count($a) - count($b);
    }

    private function colorId(?string $color): int
    {
        if ($color === null) {
            return 0;
        }
        $color = strtoupper($color);
        if (isset($this->color2id[$color])) {
            return $this->color2id[$color];
        }
        $id = ++$this->lastColorId;
        $this->color2id[$color] = $id;
        $this->id2color[$id] = $color;
        return $id;
    }

    public function color(int $id): ?string
    {
        return $this->id2color[$id] ?? null;
    }

    /** @return array{0:int,1:int,2:int} */
    public function defaults(): array
    {
        return $this->defaults;
    }

    /**
     * Theme.match: effective style for the innermost scope, taking the parents into account.
     *
     * @return array{0:int,1:int,2:int}|null fontStyle, foreground, background
     */
    public function match(ScopeStack $path): ?array
    {
        $name = $path->scopeName;
        $candidates = $this->matchCache[$name] ??= $this->root->match($name);
        foreach ($candidates as $rule) {
            if (self::parentsMatch($path->parent, $rule->parentScopes)) {
                return [$rule->fontStyle, $rule->foreground, $rule->background];
            }
        }
        return null;
    }

    /** @param list<string> $parentScopes */
    private static function parentsMatch(?ScopeStack $path, array $parentScopes): bool
    {
        $n = count($parentScopes);
        for ($index = 0; $index < $n; $index++) {
            $pattern = $parentScopes[$index];
            $mustMatch = false;
            if ($pattern === '>') {
                if ($index === $n - 1) {
                    return false;
                }
                $pattern = $parentScopes[++$index];
                $mustMatch = true;
            }
            while ($path !== null) {
                if (self::scopeMatches($path->scopeName, $pattern)) {
                    break;
                }
                if ($mustMatch) {
                    return false;
                }
                $path = $path->parent;
            }
            if ($path === null) {
                return false;
            }
            $path = $path->parent;
        }
        return true;
    }

    private static function scopeMatches(string $scopeName, string $pattern): bool
    {
        return $pattern === $scopeName
            || (str_starts_with($scopeName, $pattern) && ($scopeName[strlen($pattern)] ?? '') === '.');
    }
}

/** Rule in the theme trie (ThemeTrieElementRule). */
final class ThemeTrieRule
{
    /** @var list<string> */
    public array $parentScopes;

    /** @param list<string>|null $parentScopes */
    public function __construct(
        public int $scopeDepth,
        ?array $parentScopes,
        public int $fontStyle,
        public int $foreground,
        public int $background,
    ) {
        $this->parentScopes = $parentScopes ?? [];
    }

    public function acceptOverwrite(int $scopeDepth, int $fontStyle, int $foreground, int $background): void
    {
        if ($this->scopeDepth <= $scopeDepth) {
            $this->scopeDepth = $scopeDepth;
        }
        if ($fontStyle !== Theme::FONT_NOT_SET) {
            $this->fontStyle = $fontStyle;
        }
        if ($foreground !== 0) {
            $this->foreground = $foreground;
        }
        if ($background !== 0) {
            $this->background = $background;
        }
    }
}

/** Node in the theme trie (ThemeTrieElement). */
final class ThemeTrieElement
{
    /** @var array<string, ThemeTrieElement> */
    private array $children = [];

    /** @param list<ThemeTrieRule> $rulesWithParentScopes */
    public function __construct(private ThemeTrieRule $mainRule, private array $rulesWithParentScopes)
    {
    }

    /** @return list<ThemeTrieRule> */
    public function match(string $scope): array
    {
        if ($scope !== '') {
            $dot = strpos($scope, '.');
            $head = $dot === false ? $scope : substr($scope, 0, $dot);
            $tail = $dot === false ? '' : substr($scope, $dot + 1);
            if (isset($this->children[$head])) {
                return $this->children[$head]->match($tail);
            }
        }
        $rules = [...$this->rulesWithParentScopes, $this->mainRule];
        // Array.prototype.sort is stable; so is usort in PHP 8.
        usort($rules, [self::class, 'cmpBySpecificity']);
        return $rules;
    }

    private static function cmpBySpecificity(ThemeTrieRule $a, ThemeTrieRule $b): int
    {
        if ($a->scopeDepth !== $b->scopeDepth) {
            return $b->scopeDepth - $a->scopeDepth;
        }
        $ai = 0;
        $bi = 0;
        while (true) {
            if (($a->parentScopes[$ai] ?? null) === '>') {
                $ai++;
            }
            if (($b->parentScopes[$bi] ?? null) === '>') {
                $bi++;
            }
            if ($ai >= count($a->parentScopes) || $bi >= count($b->parentScopes)) {
                break;
            }
            $diff = strlen($b->parentScopes[$bi]) - strlen($a->parentScopes[$ai]);
            if ($diff !== 0) {
                return $diff;
            }
            $ai++;
            $bi++;
        }
        return count($b->parentScopes) - count($a->parentScopes);
    }

    /** @param list<string>|null $parentScopes */
    public function insert(int $scopeDepth, string $scope, ?array $parentScopes, int $fontStyle, int $foreground, int $background): void
    {
        if ($scope === '') {
            $this->insertHere($scopeDepth, $parentScopes, $fontStyle, $foreground, $background);
            return;
        }
        $dot = strpos($scope, '.');
        $head = $dot === false ? $scope : substr($scope, 0, $dot);
        $tail = $dot === false ? '' : substr($scope, $dot + 1);
        if (!isset($this->children[$head])) {
            $clones = array_map(static fn (ThemeTrieRule $r) => clone $r, $this->rulesWithParentScopes);
            $this->children[$head] = new self(clone $this->mainRule, $clones);
        }
        $this->children[$head]->insert($scopeDepth + 1, $tail, $parentScopes, $fontStyle, $foreground, $background);
    }

    /** @param list<string>|null $parentScopes */
    private function insertHere(int $scopeDepth, ?array $parentScopes, int $fontStyle, int $foreground, int $background): void
    {
        if ($parentScopes === null) {
            $this->mainRule->acceptOverwrite($scopeDepth, $fontStyle, $foreground, $background);
            return;
        }
        foreach ($this->rulesWithParentScopes as $rule) {
            if (Theme::strArrCmp($rule->parentScopes, $parentScopes) === 0) {
                $rule->acceptOverwrite($scopeDepth, $fontStyle, $foreground, $background);
                return;
            }
        }
        if ($fontStyle === Theme::FONT_NOT_SET) {
            $fontStyle = $this->mainRule->fontStyle;
        }
        if ($foreground === 0) {
            $foreground = $this->mainRule->foreground;
        }
        if ($background === 0) {
            $background = $this->mainRule->background;
        }
        $this->rulesWithParentScopes[] = new ThemeTrieRule($scopeDepth, $parentScopes, $fontStyle, $foreground, $background);
    }
}
