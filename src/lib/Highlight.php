<?php

declare(strict_types=1);

namespace Pholio;

use Pholio\Highlight\Hast;
use Pholio\Highlight\LanguageIcons;
use Pholio\Highlight\Registry;
use Pholio\Highlight\Shiki;

require_once __DIR__ . '/Highlight/OnigRegex.php';
require_once __DIR__ . '/Highlight/Scanner.php';
require_once __DIR__ . '/Highlight/RegExpSource.php';
require_once __DIR__ . '/Highlight/Theme.php';
require_once __DIR__ . '/Highlight/Rules.php';
require_once __DIR__ . '/Highlight/Grammar.php';
require_once __DIR__ . '/Highlight/Registry.php';
require_once __DIR__ . '/Highlight/Hast.php';
require_once __DIR__ . '/Highlight/Notation.php';
require_once __DIR__ . '/Highlight/LanguageIcons.php';
require_once __DIR__ . '/Highlight/Shiki.php';

/**
 * Syntax highlighting like Fumadocs rehype-code (Shiki 4.4.3, JavaScript regex engine, github-light/github-dark,
 * defaultColor false), in plain PHP: real TextMate grammars and themes from `vendor-data/shiki`.
 *
 * Input is a `code_block` from Markdown.php: `lang` and `meta` {raw, title?, lineNumbers?, noCopy?, tab?,
 * tabGroup?}. As in the original, the key order of `meta` determines the order of the `pre` attributes.
 *
 * Returns:
 * - html: the `pre` as hast-util-to-html serialises the Shiki HAST (including title/allowCopy/data-line-numbers/icon)
 * - code: only the inner `<code>…</code>` (for the CodeBlock component)
 * - preClass, preStyle: classes and CSS variables of the `pre` (the component puts them on the `figure`)
 * - title, lang, lineNumbers (false|true|int), tab, icon (SVG or null), allowCopy
 *
 * Unknown languages throw, as in Fumadocs (where the build aborts with "Language `x` not found").
 */
final class Highlight
{
    /**
     * Meta line (info string without the language) like Fumadocs: remark-code-tab takes out tab/tab-group, then
     * parseMetaString (title, tab, noCopy, lineNumbers). Result in the shape of Markdown.php.
     *
     * @return array<string,mixed>
     */
    public static function parseMeta(string $info): array
    {
        $meta = trim($info);
        $out = [];
        $re = '/(?<=^|\s)([a-zA-Z0-9_-]+)(?:=(?:"([^"]*)"|\'([^\']*)\'|(\d+)))?/';
        $parse = static function (string $meta, array $allowed) use ($re): array {
            $attrs = [];
            $rest = (string) preg_replace_callback($re, static function (array $m) use (&$attrs, $allowed): string {
                if (!in_array($m[1], $allowed, true)) {
                    return $m[0];
                }
                if (isset($m[4]) && $m[4] !== '') {
                    $attrs[$m[1]] = (int) $m[4];
                } elseif (isset($m[2]) && ($m[2] !== '' || str_contains($m[0], '"'))) {
                    $attrs[$m[1]] = $m[2];
                } elseif (isset($m[3]) && ($m[3] !== '' || str_contains($m[0], '\''))) {
                    $attrs[$m[1]] = $m[3];
                } else {
                    $attrs[$m[1]] = null;
                }
                return '';
            }, $meta, -1, $count, PREG_UNMATCHED_AS_NULL);
            return [$attrs, $rest];
        };
        if ($meta !== '') {
            [$tabAttrs, $rest] = $parse($meta, ['tab', 'tab-group']);
            if (isset($tabAttrs['tab']) && is_string($tabAttrs['tab'])) {
                $out['tab'] = $tabAttrs['tab'];
                if (isset($tabAttrs['tab-group']) && is_string($tabAttrs['tab-group'])) {
                    $out['tabGroup'] = $tabAttrs['tab-group'];
                }
                $meta = $rest;
            }
        }
        [$attrs, $rest] = $parse($meta, ['title', 'tab', 'noCopy', 'lineNumbers']);
        $result = ['raw' => $rest];
        foreach ($attrs as $k => $v) {
            if ($k === 'noCopy') {
                $result['noCopy'] = true;
            } elseif ($k === 'lineNumbers') {
                $result['lineNumbers'] = is_int($v) ? $v : true;
            } else {
                $result[$k] = $v;
            }
        }
        return $result + $out;
    }

    /**
     * @param array<string,mixed> $meta
     * @param array{dynamic?:bool} $options dynamic: like <DynamicCodeBlock> (no meta, notation or icon)
     * @return array{html:string, code:string, preClass:string, preStyle:string, title:?string, lang:string,
     *     lineNumbers:bool|int, tab:?string, icon:?string, allowCopy:bool}
     */
    public static function code(string $code, ?string $lang, array $meta = [], array $options = []): array
    {
        $dynamic = (bool) ($options['dynamic'] ?? false);
        $lang = $lang === null || $lang === '' ? 'plaintext' : $lang;
        if (!Registry::instance()->has($lang)) {
            throw new \InvalidArgumentException('Language `' . $lang . '` not found, you may need to load it first');
        }
        $props = [];
        $title = null;
        $lineNumbers = false;
        $allowCopy = true;
        if (!$dynamic) {
            foreach ($meta as $key => $value) {
                if ($key === 'title') {
                    $title = $value === null ? null : (string) $value;
                    $props['title'] = $value === null ? null : (is_int($value) ? $value : (string) $value);
                } elseif ($key === 'noCopy') {
                    $allowCopy = false;
                    $props['allowCopy'] = 'false';
                } elseif ($key === 'lineNumbers') {
                    $lineNumbers = is_int($value) ? $value : true;
                    $props['data-line-numbers'] = true;
                    if (is_int($value)) {
                        $props['data-line-numbers-start'] = $value;
                    }
                }
            }
        }
        $icon = $dynamic ? null : LanguageIcons::svg($lang);
        $hast = Shiki::codeToHast($code, $lang, $props, !$dynamic);
        if ($icon !== null) {
            $hast['pre']->properties['icon'] = $icon;
        }
        $class = $hast['pre']->properties['class'];
        return [
            'html' => Hast::toHtml($hast['pre']),
            'code' => Hast::toHtml($hast['code']),
            'preClass' => is_array($class) ? implode(' ', $class) : (string) $class,
            'preStyle' => (string) $hast['pre']->properties['style'],
            'title' => $title,
            'lang' => $lang,
            'lineNumbers' => $lineNumbers,
            'tab' => isset($meta['tab']) ? (string) $meta['tab'] : null,
            'icon' => $icon,
            'allowCopy' => $allowCopy,
        ];
    }
}
