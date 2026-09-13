<?php

declare(strict_types=1);

namespace Pholio\Highlight;

/**
 * Minimal HAST (element and text nodes as objects, so identity comparisons like `indexOf` in the transformers
 * work) and a serialisation like hast-util-to-html 9 with default options.
 */
final class HastElement
{
    /** @var array<string,mixed> data like `node.data` (e.g. parsed notation comments) */
    public array $data = [];

    /**
     * @param array<string, string|int|bool|list<string>|null> $properties in insertion order
     * @param list<HastElement|HastText> $children
     */
    public function __construct(public string $tagName, public array $properties = [], public array $children = [])
    {
    }
}

final class HastText
{
    public function __construct(public string $value)
    {
    }
}

final class Hast
{
    /** addClassToHast from @shikijs/core. */
    public static function addClass(?HastElement $node, ?string $className): void
    {
        if ($node === null || $className === null || $className === '') {
            return;
        }
        $class = $node->properties['class'] ?? [];
        if (is_string($class)) {
            $class = preg_split('/\s+/u', $class) ?: [];
        }
        if (!is_array($class)) {
            $class = [];
        }
        foreach (preg_split('/\s+/u', $className) ?: [] as $c) {
            if ($c !== '' && !in_array($c, $class, true)) {
                $class[] = $c;
            }
        }
        $node->properties['class'] = $class;
    }

    public static function toHtml(HastElement|HastText $node): string
    {
        if ($node instanceof HastText) {
            return strtr($node->value, ['&' => '&#x26;', '<' => '&#x3C;']);
        }
        $attrs = [];
        foreach ($node->properties as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            if ($value === true) {
                $attrs[] = $name;
                continue;
            }
            $value = is_array($value) ? implode(' ', $value) : (string) $value;
            $attrs[] = $name . '="' . strtr($value, ['&' => '&#x26;', '"' => '&#x22;', "'" => '&#x27;', '`' => '&#x60;', "\0" => '&#x0;']) . '"';
        }
        $html = '<' . $node->tagName . ($attrs === [] ? '' : ' ' . implode(' ', $attrs)) . '>';
        foreach ($node->children as $child) {
            $html .= self::toHtml($child);
        }
        return $html . '</' . $node->tagName . '>';
    }
}
