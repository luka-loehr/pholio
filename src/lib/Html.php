<?php

declare(strict_types=1);

namespace Pholio;

/**
 * Small HTML helpers for the generator: escaping, attribute builder, link detection.
 *
 * What counts is the DOM after parsing, not the exact characters. Everything is
 * therefore escaped with htmlspecialchars; the choice of entity (&#039; instead
 * of &#x27;) makes no difference to the parsed page.
 */
final class Html
{
    /** Escape text for element content or an attribute value. */
    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Build an attribute list.
     *
     * - null and false are dropped.
     * - true becomes an empty attribute (`disabled=""`).
     * - `style` may be an array of declarations: ['--callout-color' => '…'].
     * - `class` may be an array of tokens; empty tokens are dropped.
     * - Integer values are written as numbers.
     *
     * @param array<string, mixed> $attrs
     */
    public static function attrs(array $attrs): string
    {
        $out = '';

        foreach ($attrs as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            if ($value === true) {
                $out .= ' ' . $name . '=""';
                continue;
            }

            if ($name === 'style' && is_array($value)) {
                $value = self::style($value);
                if ($value === '') {
                    continue;
                }
            } elseif ($name === 'class' && is_array($value)) {
                $value = self::classes($value);
                if ($value === '') {
                    continue;
                }
            } elseif (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }

            $out .= ' ' . $name . '="' . self::e((string) $value) . '"';
        }

        return $out;
    }

    /**
     * Join class tokens. null, false and empty strings are dropped, duplicates
     * are kept (they do no harm, and the order does not matter to the browser).
     *
     * @param list<string|null|false> $tokens
     */
    public static function classes(array $tokens): string
    {
        $parts = [];
        foreach ($tokens as $token) {
            if ($token === null || $token === false || $token === '') {
                continue;
            }
            foreach (preg_split('/\s+/', trim((string) $token)) ?: [] as $piece) {
                if ($piece !== '') {
                    $parts[] = $piece;
                }
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Declarations to a style value: no space after the
     * colon and `;` between declarations.
     *
     * @param array<string, string|int|float|null|false> $declarations
     */
    public static function style(array $declarations): string
    {
        $parts = [];
        foreach ($declarations as $property => $value) {
            if ($value === null || $value === false || $value === '') {
                continue;
            }
            $parts[] = $property . ':' . $value;
        }

        return implode(';', $parts);
    }

    /**
     * External link? The rule:
     * a scheme at the start (`^\w+:`) or a protocol-relative path (`//`).
     */
    public static function isExternal(string $href): bool
    {
        return preg_match('/^\w+:/', $href) === 1 || str_starts_with($href, '//');
    }

    /** Void element (`img`, `br`, …). */
    public static function voidTag(string $name, array $attrs = []): string
    {
        return '<' . $name . self::attrs($attrs) . '>';
    }

    /** Element with already rendered content. */
    public static function tag(string $name, array $attrs = [], string $children = ''): string
    {
        return '<' . $name . self::attrs($attrs) . '>' . $children . '</' . $name . '>';
    }
}
