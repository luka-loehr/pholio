<?php

declare(strict_types=1);

namespace Pholio;

use Closure;
use LogicException;

require_once __DIR__ . '/Markdown.php';
require_once __DIR__ . '/../I18n.php';

/**
 * AST to clean Markdown: the body of a page's `.md` twin and of its entry in
 * llms-full.txt.
 *
 * The output is CommonMark with GFM tables, task lists, strikethrough and
 * footnotes, readable without knowing Pholio. Component tags become what they
 * mean:
 *
 *   Callout            blockquote starting with **Type:** and the title
 *   Tabs, Accordions   one heading per tab or item, one level below the enclosing heading
 *   code tabs          one heading per tab above its code blocks
 *   Cards, Card        a list of links with their descriptions
 *   Steps              numbered headings when every step starts with one, else an ordered list
 *   Files              a nested list, folders with a trailing slash
 *   TypeTable          a list of properties with type, default and description
 *   Banner             blockquote
 *   Screenshot, ImageZoom  an image with its alt text
 *   DynamicCodeBlock   a fenced code block
 *   InlineTOC          left out: the twin has its own headings
 *
 * Links and image sources go through the two closures, so the caller decides
 * how internal URLs are published (`.md` twins, absolute with `site.url`).
 * Callout labels and the type table words follow the site language (I18n).
 */
final class PageMarkdown
{
    /** Callout type => translation key of its label. */
    private const CALLOUT_LABELS = [
        'info' => 'Info(callout)(agent files)', 'tip' => 'Tip(callout)(agent files)',
        'warning' => 'Warning(callout)(agent files)', 'warn' => 'Warning(callout)(agent files)',
        'error' => 'Error(callout)(agent files)', 'success' => 'Success(callout)(agent files)',
        'idea' => 'Idea(callout)(agent files)',
    ];

    /** Level of the heading the current block sits under; 1 is the page title. */
    private int $level = 1;

    /** @var list<array<string,mixed>> */
    private array $footnotes = [];

    /**
     * @param Closure(string):string $link  link target as written => published target
     * @param Closure(string):string $asset image source as written => published URL
     */
    public function __construct(
        private readonly Closure $link,
        private readonly Closure $asset,
    ) {
    }

    /** The page body without title and description. */
    public function body(Document $document): string
    {
        $this->level = 1;
        $this->footnotes = [];
        $out = $this->blocks($document->blocks);

        $notes = [];
        foreach ($this->footnotes as $definition) {
            $text = $this->blocks($definition['blocks']);
            $notes[] = '[^' . $definition['label'] . ']: ' . self::indent($text, '    ', false);
        }
        if ($notes !== []) {
            $out .= ($out === '' ? '' : "\n\n") . implode("\n\n", $notes);
        }

        return $out;
    }

    // ------------------------------------------------------------------ Blocks

    /** @param list<array<string,mixed>> $blocks */
    private function blocks(array $blocks, string $separator = "\n\n"): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            $text = $this->block($block);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode($separator, $parts);
    }

    /** @param array<string,mixed> $block */
    private function block(array $block): string
    {
        switch ((string) $block['type']) {
            case 'heading':
                $this->level = (int) $block['level'];

                return str_repeat('#', $this->level) . ' ' . self::oneLine($this->inlines($block['inlines']));

            case 'paragraph':
                return $this->inlines($block['inlines']);

            case 'thematic_break':
                return '---';

            case 'blockquote':
                return self::quote($this->blocks($block['blocks']));

            case 'list':
                return $this->list($block);

            case 'table':
                return $this->table($block);

            case 'code_block':
                return self::fence(self::withoutNotations((string) $block['value']), $block['lang'] ?? null, $block['meta']['title'] ?? null);

            case 'code_tabs':
                $tabs = [];
                foreach ($block['items'] as $item) {
                    $tabs[] = $this->section((string) $item['value'], $item['blocks']);
                }

                return implode("\n\n", $tabs);

            case 'footnote_definition':
                $this->footnotes[] = $block;

                return '';

            case 'component_raw':
                return self::fence((string) $block['value'], (string) $block['attrs']['lang'], null);

            case 'component':
            case 'component_void':
                return $this->component($block);
        }

        throw new LogicException('unknown block type in the AST: ' . $block['type']);
    }

    /**
     * A heading one level below the current one, followed by $blocks. The level
     * is restored afterwards, so the next tab or item gets the same level.
     *
     * @param list<array<string,mixed>> $blocks
     */
    private function section(string $title, array $blocks): string
    {
        $parent = $this->level;
        $this->level = min($parent + 1, 6);
        $heading = str_repeat('#', $this->level) . ' ' . self::escape(self::oneLine($title));
        $body = $this->blocks($blocks);
        $this->level = $parent;

        return $body === '' ? $heading : $heading . "\n\n" . $body;
    }

    /** @param array<string,mixed> $block */
    private function list(array $block): string
    {
        $tight = (bool) $block['tight'];
        $number = (int) ($block['start'] ?? 1);
        $items = [];
        foreach ($block['items'] as $item) {
            $marker = (bool) $block['ordered'] ? ($number++) . '. ' : '- ';
            $task = array_key_exists('checked', $item) ? '[' . ($item['checked'] ? 'x' : ' ') . '] ' : '';
            $content = $this->blocks($item['blocks'], $tight ? "\n" : "\n\n");
            $items[] = $marker . $task . self::indent($content, str_repeat(' ', strlen($marker)), false);
        }

        return implode($tight ? "\n" : "\n\n", $items);
    }

    /** @param array<string,mixed> $block */
    private function table(array $block): string
    {
        $row = function (array $cells): string {
            $out = [];
            foreach ($cells as $cell) {
                $out[] = str_replace('|', '\|', self::oneLine($this->inlines($cell)));
            }

            return '| ' . implode(' | ', $out) . ' |';
        };

        $separator = [];
        foreach ($block['align'] as $align) {
            $separator[] = match ($align) {
                'left' => ':---',
                'right' => '---:',
                'center' => ':---:',
                default => '---',
            };
        }
        $lines = [$row($block['head']), '| ' . implode(' | ', $separator) . ' |'];
        foreach ($block['rows'] as $cells) {
            $lines[] = $row($cells);
        }

        return implode("\n", $lines);
    }

    /** @param array<string,mixed> $block */
    private function component(array $block): string
    {
        /** @var array<string,mixed> $attrs */
        $attrs = $block['attrs'] ?? [];
        /** @var list<array<string,mixed>> $children */
        $children = $block['blocks'] ?? [];

        switch ((string) $block['name']) {
            case 'Callout':
                $type = (string) ($attrs['type'] ?? 'info');
                $label = isset(self::CALLOUT_LABELS[$type]) ? I18n::t(self::CALLOUT_LABELS[$type]) : ucfirst($type);
                $head = '**' . $label . (isset($attrs['title']) ? ':** ' . self::escape((string) $attrs['title']) : '**');
                $body = $this->blocks($children);

                return self::quote($body === '' ? $head : $head . "\n\n" . $body);

            case 'Banner':
                return self::quote($this->blocks($children));

            case 'Cards':
                return $this->blocks($children, "\n");

            case 'Card':
                $title = self::escape((string) ($attrs['title'] ?? ''));
                $label = isset($attrs['href']) ? '[' . $title . '](' . self::destination(($this->link)((string) $attrs['href'])) . ')' : '**' . $title . '**';

                return '- ' . $label . (isset($attrs['description']) ? ': ' . self::escape((string) $attrs['description']) : '');

            case 'Tabs':
                $labels = is_array($attrs['items'] ?? null) ? $attrs['items'] : [];
                $tabs = [];
                foreach ($children as $i => $tab) {
                    $value = $tab['attrs']['value'] ?? $labels[$i] ?? (string) ($i + 1);
                    $tabs[] = $this->section((string) $value, $tab['blocks'] ?? []);
                }

                return implode("\n\n", $tabs);

            case 'Accordions':
                $items = [];
                foreach ($children as $item) {
                    $items[] = $this->section((string) $item['attrs']['title'], $item['blocks'] ?? []);
                }

                return implode("\n\n", $items);

            case 'Steps':
                return $this->steps($children);

            case 'Files':
                return implode("\n", $this->files($children, 0));

            case 'TypeTable':
                return $this->typeTable($children);

            case 'Screenshot':
            case 'ImageZoom':
                return '![' . self::escape((string) ($attrs['alt'] ?? '')) . '](' . self::destination(($this->asset)((string) $attrs['src'])) . ')';

            case 'InlineTOC':
                return '';
        }

        throw new LogicException('unknown component in the AST: ' . $block['name']);
    }

    /**
     * Steps as numbered headings when every step starts with a heading (the usual
     * way to write them), otherwise as an ordered list.
     *
     * @param list<array<string,mixed>> $steps
     */
    private function steps(array $steps): string
    {
        $headed = $steps !== [];
        foreach ($steps as $step) {
            $headed = $headed && ((string) ($step['blocks'][0]['type'] ?? '')) === 'heading';
        }

        $out = [];
        foreach ($steps as $i => $step) {
            $blocks = $step['blocks'] ?? [];
            if ($headed) {
                array_unshift($blocks[0]['inlines'], ['type' => 'text', 'value' => ($i + 1) . '. ']);
                $out[] = $this->blocks($blocks);
                continue;
            }
            $marker = ($i + 1) . '. ';
            $out[] = $marker . self::indent($this->blocks($blocks), str_repeat(' ', strlen($marker)), false);
        }

        return implode("\n\n", $out);
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @return list<string>
     */
    private function files(array $nodes, int $depth): array
    {
        $lines = [];
        foreach ($nodes as $node) {
            $name = self::escape((string) $node['attrs']['name']);
            $pad = str_repeat('  ', $depth);
            if ((string) $node['name'] === 'Folder') {
                $lines[] = $pad . '- ' . $name . '/';
                array_push($lines, ...$this->files($node['blocks'] ?? [], $depth + 1));
                continue;
            }
            $lines[] = $pad . '- ' . $name;
        }

        return $lines;
    }

    /** @param list<array<string,mixed>> $props */
    private function typeTable(array $props): string
    {
        $lines = [];
        foreach ($props as $prop) {
            $p = $prop['attrs'];
            $line = '- `' . $p['name'] . '`: `' . $p['type'] . '`';
            if (isset($p['typeDescription'])) {
                $text = self::escape((string) $p['typeDescription']);
                $line .= ' (' . (isset($p['typeDescriptionLink'])
                    ? '[' . $text . '](' . self::destination(($this->link)((string) $p['typeDescriptionLink'])) . ')'
                    : $text) . ')';
            }
            if (isset($p['default'])) {
                $line .= ', ' . I18n::t('default(type table)(agent files)') . ' `' . $p['default'] . '`';
            }
            if (($p['required'] ?? false) === true) {
                $line .= ', ' . I18n::t('required(type table)(agent files)');
            }
            if (($p['deprecated'] ?? false) === true) {
                $line .= ', ' . I18n::t('deprecated(type table)(agent files)');
            }
            $body = $this->blocks($prop['blocks'] ?? [], ' ');

            $lines[] = $body === '' ? $line : $line . '. ' . self::oneLine($body);
        }

        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------ Inline

    /** @param list<array<string,mixed>> $inlines */
    private function inlines(array $inlines): string
    {
        $out = '';
        foreach ($inlines as $node) {
            $out .= $this->inline($node);
        }

        return $out;
    }

    /** @param array<string,mixed> $node */
    private function inline(array $node): string
    {
        switch ((string) $node['type']) {
            case 'text':
                return self::escape((string) $node['value']);

            case 'code':
                return self::codeSpan((string) $node['value']);

            case 'strong':
                return '**' . $this->inlines($node['inlines']) . '**';

            case 'emphasis':
                return '*' . $this->inlines($node['inlines']) . '*';

            case 'delete':
                return '~~' . $this->inlines($node['inlines']) . '~~';

            case 'break':
                return "\\\n";

            case 'link':
                $title = isset($node['title']) ? ' "' . str_replace('"', '\"', (string) $node['title']) . '"' : '';

                return '[' . $this->inlines($node['inlines']) . '](' . self::destination(($this->link)((string) $node['href'])) . $title . ')';

            case 'image':
                return '![' . self::escape((string) $node['alt']) . '](' . self::destination(($this->asset)((string) $node['src'])) . ')';

            case 'footnote_reference':
                return '[^' . $node['label'] . ']';
        }

        throw new LogicException('unknown inline type in the AST: ' . $node['type']);
    }

    // ------------------------------------------------------------------ Helpers

    /**
     * Escape text so it stays text: backslashes, emphasis and code markers,
     * brackets, and `<` before a tag-like character. Underscores inside words
     * (snake_case) stay as they are.
     */
    public static function escape(string $text): string
    {
        $text = (string) preg_replace('/([\\\\`*\[\]])/', '\\\\$1', $text);
        $text = (string) preg_replace('/<(?=[A-Za-z\/!?])/', '\\<', $text);

        return (string) preg_replace('/(?<![A-Za-z0-9])_|_(?![A-Za-z0-9])/', '\\_', $text);
    }

    /** Every line prefixed with "> ", empty lines with ">". */
    public static function quote(string $text): string
    {
        return implode("\n", array_map(
            static fn(string $line): string => $line === '' ? '>' : '> ' . $line,
            explode("\n", $text),
        ));
    }

    /** Continuation lines indented by $pad; empty lines stay empty. */
    private static function indent(string $text, string $pad, bool $first): string
    {
        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            if (($i > 0 || $first) && $line !== '') {
                $lines[$i] = $pad . $line;
            }
        }

        return implode("\n", $lines);
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s*\\\\?\n\s*/', ' ', $text));
    }

    /**
     * Code without the notation comments the highlighter reads (`// [!code highlight]`,
     * `# [!code ++]`, `<!-- [!code focus] -->`); a line that held only the comment goes.
     */
    private static function withoutNotations(string $value): string
    {
        $lines = [];
        foreach (explode("\n", $value) as $line) {
            $stripped = (string) preg_replace('/\s*(?:\/\/|#|--|;|<!--|\{?\/\*)\s*\[!code\s[^\]]*\]\s*(?:-->|\*\/\}?)?\s*$/', '', $line);
            if ($stripped === '' && trim($line) !== '') {
                continue;
            }
            $lines[] = $stripped;
        }

        return implode("\n", $lines);
    }

    /** A fence longer than any backtick run inside, with the language and the title. */
    private static function fence(string $value, ?string $lang, ?string $title): string
    {
        preg_match_all('/`+/', $value, $runs);
        $longest = max([0, ...array_map('strlen', $runs[0])]);
        $fence = str_repeat('`', max(3, $longest + 1));
        $info = (string) $lang;
        if ($title !== null && $title !== '') {
            $info .= ($info === '' ? '' : ' ') . 'title="' . str_replace('"', '\"', $title) . '"';
        }

        return $fence . $info . "\n" . rtrim($value, "\n") . "\n" . $fence;
    }

    private static function codeSpan(string $value): string
    {
        preg_match_all('/`+/', $value, $runs);
        $ticks = str_repeat('`', max([0, ...array_map('strlen', $runs[0])]) + 1);
        $pad = $value !== '' && ($value[0] === '`' || $value[strlen($value) - 1] === '`') ? ' ' : '';

        return $ticks . $pad . $value . $pad . $ticks;
    }

    /** A link destination, in angle brackets when it holds spaces or parentheses. */
    private static function destination(string $url): string
    {
        return preg_match('/[\s()<>]/', $url) === 1 ? '<' . str_replace(['<', '>'], ['%3C', '%3E'], $url) . '>' : $url;
    }
}
