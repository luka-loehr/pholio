<?php

declare(strict_types=1);

namespace Pholio\Highlight;

/**
 * codeToHast from @shikijs/core 4.4.3 for exactly Pholio's settings:
 * themes {light: github-light, dark: github-dark}, defaultColor false, CSS variables `--shiki-*`,
 * mergeWhitespaces true, structure "classic", tabindex "0".
 */
final class Shiki
{
    private const THEMES = ['light' => 'github-light', 'dark' => 'github-dark'];

    /**
     * @param array<string, string|int|bool|null> $preProperties additional `pre` properties (meta) in order
     * @param bool $notation apply the notation transformers
     * @return array{pre: HastElement, code: HastElement}
     */
    public static function codeToHast(string $code, string $lang, array $preProperties, bool $notation): array
    {
        $registry = Registry::instance();
        $themes = [];
        foreach (self::THEMES as $key => $name) {
            $themes[$key] = $registry->theme($name);
        }
        $perTheme = [];
        foreach ($themes as $key => $theme) {
            $perTheme[$key] = self::tokensForTheme($code, $lang, $theme);
        }
        $lines = self::mergeVariants($perTheme);
        $lines = self::mergeWhitespace($lines);

        $fg = [];
        $bg = [];
        foreach ($themes as $key => $theme) {
            $fg[] = '--shiki-' . $key . ':' . ($theme->colorReplacements[strtolower($theme->fg)] ?? $theme->fg);
            $bg[] = '--shiki-' . $key . '-bg:' . ($theme->colorReplacements[strtolower($theme->bg)] ?? $theme->bg);
        }
        $properties = [
            'class' => 'shiki shiki-themes ' . implode(' ', array_map(static fn (Theme $t) => $t->name, $themes)),
            'style' => implode(';', $fg) . ';' . implode(';', $bg),
            'tabindex' => '0',
        ];
        foreach ($preProperties as $k => $v) {
            if (!str_starts_with($k, '_')) {
                $properties[$k] = $v;
            }
        }
        $pre = new HastElement('pre', $properties);
        $codeEl = new HastElement('code');
        foreach ($lines as $idx => $line) {
            if ($idx > 0) {
                $codeEl->children[] = new HastText("\n");
            }
            $lineEl = new HastElement('span', ['class' => 'line']);
            foreach ($line as $token) {
                $props = [];
                $style = [];
                foreach ($token['style'] as $k => $v) {
                    $style[] = $k . ':' . $v;
                }
                if ($style !== []) {
                    $props['style'] = implode(';', $style);
                }
                $lineEl->children[] = new HastElement('span', $props, [new HastText($token['content'])]);
            }
            $codeEl->children[] = $lineEl;
        }
        if ($notation) {
            Notation::apply($codeEl, $pre);
        }
        $pre->children[] = $codeEl;
        return ['pre' => $pre, 'code' => $codeEl];
    }

    /** splitLines(code) from @shikijs/primitive: lines without line breaks, split at "\r\n" and "\n". */
    /** @return list<string> */
    public static function splitLines(string $code): array
    {
        if ($code === '') {
            return [''];
        }
        return preg_split('/\r?\n/', $code) ?: [''];
    }

    /**
     * codeToTokensBase for one theme.
     *
     * @return list<list<array{content:string, color:?string, font:int}>>
     */
    private static function tokensForTheme(string $code, string $lang, Theme $theme): array
    {
        $registry = Registry::instance();
        $lines = self::splitLines($code);
        if ($registry->isPlain($lang)) {
            return array_map(static fn (string $l) => [['content' => $l, 'color' => null, 'font' => 0]], $lines);
        }
        $grammar = $registry->grammar($lang);
        $grammar->theme = $theme;
        $disabledBefore = Scanner::$withDisabledPatterns;
        $state = null;
        $final = [];
        foreach ($lines as $line) {
            if ($line === '') {
                $final[] = [];
                continue;
            }
            $r = $grammar->tokenizeLine2($line, $state);
            $tokens = $r['tokens'];
            $n = intdiv(count($tokens), 2);
            $len = strlen($line);
            $out = [];
            for ($j = 0; $j < $n; $j++) {
                $start = $tokens[2 * $j];
                $next = $j + 1 < $n ? $tokens[2 * $j + 2] : $len;
                if ($start === $next) {
                    continue;
                }
                $meta = $tokens[2 * $j + 1];
                $color = $theme->color(Metadata::foreground($meta));
                if ($color !== null) {
                    $color = $theme->colorReplacements[strtolower($color)] ?? $color;
                }
                $out[] = ['content' => substr($line, $start, $next - $start), 'color' => $color, 'font' => Metadata::fontStyle($meta)];
            }
            $final[] = $out;
            $state = $r['ruleStack'];
        }
        if (Scanner::$withDisabledPatterns !== $disabledBefore) {
            $registry->warnSimplified($lang);
        }
        return $final;
    }

    /**
     * alignThemesTokenization + flatTokenVariants (defaultColor false, css-vars).
     *
     * @param array<string, list<list<array{content:string, color:?string, font:int}>>> $perTheme
     * @return list<list<array{content:string, style:array<string,string>}>>
     */
    private static function mergeVariants(array $perTheme): array
    {
        $keys = array_keys($perTheme);
        $first = $perTheme[$keys[0]];
        $result = [];
        foreach ($first as $lineIdx => $_) {
            $lines = [];
            foreach ($keys as $k) {
                $lines[$k] = $perTheme[$k][$lineIdx];
            }
            $idx = array_fill_keys($keys, 0);
            $current = [];
            foreach ($keys as $k) {
                $current[$k] = $lines[$k][0] ?? null;
            }
            $aligned = array_fill_keys($keys, []);
            while (!in_array(null, $current, true)) {
                $min = min(array_map(static fn ($t) => strlen($t['content']), $current));
                foreach ($keys as $k) {
                    $t = $current[$k];
                    if (strlen($t['content']) === $min) {
                        $aligned[$k][] = $t;
                        $idx[$k]++;
                        $current[$k] = $lines[$k][$idx[$k]] ?? null;
                    } else {
                        $aligned[$k][] = ['content' => substr($t['content'], 0, $min)] + $t;
                        $current[$k] = ['content' => substr($t['content'], $min)] + $t;
                    }
                }
            }
            $out = [];
            foreach ($aligned[$keys[0]] as $ti => $token) {
                $styles = [];
                foreach ($keys as $k) {
                    $styles[$k] = self::styleObject($aligned[$k][$ti]);
                }
                $styleKeys = [];
                foreach ($styles as $s) {
                    foreach (array_keys($s) as $sk) {
                        $styleKeys[$sk] = true;
                    }
                }
                $merged = [];
                foreach ($keys as $k) {
                    foreach (array_keys($styleKeys) as $sk) {
                        $suffix = $sk === 'color' ? '' : ($sk === 'background-color' ? '-bg' : '-' . $sk);
                        $merged['--shiki-' . $k . $suffix] = ($styles[$k][$sk] ?? '') !== '' ? $styles[$k][$sk] : 'inherit';
                    }
                }
                $out[] = ['content' => $token['content'], 'style' => $merged];
            }
            $result[] = $out;
        }
        return $result;
    }

    /**
     * getTokenStyleObject
     *
     * @param array{content:string, color:?string, font:int} $t
     * @return array<string,string>
     */
    private static function styleObject(array $t): array
    {
        $s = [];
        if ($t['color'] !== null && $t['color'] !== '') {
            $s['color'] = $t['color'];
        }
        $f = $t['font'];
        if ($f) {
            if ($f & Theme::FONT_ITALIC) {
                $s['font-style'] = 'italic';
            }
            if ($f & Theme::FONT_BOLD) {
                $s['font-weight'] = 'bold';
            }
            $dec = [];
            if ($f & Theme::FONT_UNDERLINE) {
                $dec[] = 'underline';
            }
            if ($f & Theme::FONT_STRIKETHROUGH) {
                $dec[] = 'line-through';
            }
            if ($dec !== []) {
                $s['text-decoration'] = implode(' ', $dec);
            }
        }
        return $s;
    }

    /**
     * mergeWhitespaceTokens (the flat tokens no longer carry a fontStyle, so merging is always allowed).
     *
     * @param list<list<array{content:string, style:array<string,string>}>> $lines
     * @return list<list<array{content:string, style:array<string,string>}>>
     */
    private static function mergeWhitespace(array $lines): array
    {
        $ws = '/^[' . Notation::JS_WS . ']+\z/u';
        foreach ($lines as $li => $line) {
            $new = [];
            $carry = '';
            $count = count($line);
            foreach ($line as $idx => $token) {
                if (preg_match($ws, $token['content']) && $idx + 1 < $count) {
                    $carry .= $token['content'];
                } elseif ($carry !== '') {
                    $token['content'] = $carry . $token['content'];
                    $new[] = $token;
                    $carry = '';
                } else {
                    $new[] = $token;
                }
            }
            $lines[$li] = $new;
        }
        return $lines;
    }
}
