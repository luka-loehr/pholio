<?php

declare(strict_types=1);

namespace Pholio;

use LogicException;
use WeakMap;

require_once __DIR__ . '/../Exceptions.php';
require_once __DIR__ . '/../I18n.php';
require_once __DIR__ . '/Html.php';
require_once __DIR__ . '/Icons.php';
require_once __DIR__ . '/Markdown.php';
require_once __DIR__ . '/Toc.php';
require_once __DIR__ . '/../components/callout.php';
require_once __DIR__ . '/../components/tabs.php';
require_once __DIR__ . '/../components/accordion.php';
require_once __DIR__ . '/../components/steps.php';
require_once __DIR__ . '/../components/files.php';
require_once __DIR__ . '/../components/type-table.php';
require_once __DIR__ . '/../components/banner.php';
require_once __DIR__ . '/../components/inline-toc.php';
require_once __DIR__ . '/../components/image-zoom.php';
require_once __DIR__ . '/../components/footnotes.php';
require_once __DIR__ . '/../components/codeblock.php';

/** State of one page: the document (for InlineTOC) and the footnote counters. */
final class CataloguePage
{
    public ?Document $document = null;

    /** @var list<string> footnote identifiers (upper case) in order of first reference */
    public array $footnoteOrder = [];

    /** @var array<string,int> identifier => number of references */
    public array $footnoteCounts = [];
}

/**
 * Catalogue nodes of the article body: everything lib/Render.php does not
 * render itself.
 *
 * Render.php calls this class in four places:
 *   body()   RenderCatalogue::begin() before the blocks, RenderCatalogue::footnotes() after them
 *   block()  handlesBlock()/block() first
 *   inline() handlesInline()/inline() first
 *
 * Children (blocks in tabs, accordions, steps, type props, footnotes) go back
 * through Render::blocks()/Render::inlines(), so every rule lives in one place.
 * Anything unknown throws; there is no silent fallback.
 */
final class RenderCatalogue
{
    /** Components with their own renderer; the children (Tab, Accordion, …) only through their parents. */
    private const COMPONENTS = [
        'Tabs', 'Accordions', 'Steps', 'Files', 'TypeTable', 'Banner', 'InlineTOC', 'ImageZoom', 'DynamicCodeBlock',
    ];

    /** @var WeakMap<RenderContext, CataloguePage>|null */
    private static ?WeakMap $pages = null;

    private static function page(RenderContext $ctx): CataloguePage
    {
        self::$pages ??= new WeakMap();
        if (!isset(self::$pages[$ctx])) {
            self::$pages[$ctx] = new CataloguePage();
        }

        return self::$pages[$ctx];
    }

    /** Before rendering a page: remember the document, reset the footnote counters. */
    public static function begin(Document $document, RenderContext $ctx): void
    {
        $page = self::page($ctx);
        $page->document = $document;
        $page->footnoteOrder = [];
        $page->footnoteCounts = [];
    }

    // ------------------------------------------------------------------ Blocks

    /** @param array<string,mixed> $block */
    public static function handlesBlock(array $block): bool
    {
        $type = (string) $block['type'];
        if (in_array($type, ['code_block', 'code_tabs', 'footnote_definition', 'component_raw'], true)) {
            return true;
        }
        if ($type === 'list') {
            foreach ($block['items'] as $item) {
                if (array_key_exists('checked', $item)) {
                    return true;
                }
            }

            return false;
        }
        if ($type === 'component' || $type === 'component_void') {
            $name = (string) $block['name'];
            if (in_array($name, self::COMPONENTS, true)) {
                return true;
            }

            // Callout only when Render.php can't handle it: a custom icon or single-line content.
            return $name === 'Callout' && (isset($block['attrs']['icon']) || ($block['inline'] ?? false) === true);
        }

        return false;
    }

    /** @param array<string,mixed> $block */
    public static function block(array $block, RenderContext $ctx): string
    {
        $type = (string) $block['type'];

        switch ($type) {
            case 'footnote_definition':
                // The reference renders it in the section at the end of the page, not in place.
                return '';

            case 'list':
                return self::taskList($block, $ctx);

            case 'code_block':
                return self::codeBlock($block, 'standalone', $ctx);

            case 'code_tabs':
                return self::codeTabs($block, $ctx);

            case 'component_raw':
                if ((string) $block['name'] !== 'DynamicCodeBlock') {
                    throw new LogicException('unknown raw component in the AST: ' . $block['name']);
                }

                return self::codeBlock(
                    ['lang' => $block['attrs']['lang'], 'value' => (string) $block['value']],
                    'dynamic',
                    $ctx
                );
        }

        /** @var array<string,mixed> $attrs */
        $attrs = $block['attrs'];
        /** @var list<array<string,mixed>> $children */
        $children = $block['blocks'] ?? [];

        switch ((string) $block['name']) {
            case 'Tabs':
                return nd_tabs([
                    'items' => $attrs['items'] ?? throw new ContentException(
                        '<Tabs> without items is not supported: the original renders no tab list then.'
                    ),
                    'defaultIndex' => $attrs['defaultIndex'] ?? 0,
                    'groupId' => $attrs['groupId'] ?? null,
                    'persist' => $attrs['persist'] ?? false,
                    'updateAnchor' => $attrs['updateAnchor'] ?? false,
                    'label' => $attrs['label'] ?? null,
                ], array_map(
                    static fn (array $tab): array => [
                        'value' => $tab['attrs']['value'] ?? null,
                        'html' => self::content($tab, $ctx),
                    ],
                    self::children($children, 'Tab')
                ));

            case 'Accordions':
                return nd_accordions(
                    ['type' => $attrs['type'] ?? null, 'defaultValue' => $attrs['defaultValue'] ?? []],
                    array_map(
                        static fn (array $item): array => [
                            'title' => (string) $item['attrs']['title'],
                            'id' => $item['attrs']['id'] ?? null,
                            'value' => $item['attrs']['value'] ?? null,
                            'html' => self::content($item, $ctx),
                        ],
                        self::children($children, 'Accordion')
                    ),
                    I18n::t('Copy Link(accordion)(aria-label)')
                );

            case 'Steps':
                $steps = '';
                foreach (self::children($children, 'Step') as $step) {
                    $steps .= nd_step(self::content($step, $ctx));
                }

                return nd_steps($steps);

            case 'Files':
                return nd_files_tree(self::fileNodes($children));

            case 'TypeTable':
                $labels = [
                    'prop' => I18n::t('Prop(type table)'),
                    'type' => I18n::t('Type(type table)'),
                    'default' => I18n::t('Default(type table)'),
                ];
                $rows = [];
                foreach (self::children($children, 'TypeProp') as $prop) {
                    $p = $prop['attrs'];
                    if (isset($p['typeDescriptionLink'])) {
                        $p['typeDescriptionLink'] = $ctx->link((string) $p['typeDescriptionLink']);
                    }
                    $rows[] = nd_type_prop($p, self::content($prop, $ctx), $labels);
                }

                return nd_type_table($rows, $labels);

            case 'Banner':
                return nd_banner([
                    'id' => $attrs['id'] ?? null,
                    'variant' => $attrs['variant'] ?? 'normal',
                    'height' => $attrs['height'] ?? '3rem',
                    'changeLayout' => $attrs['changeLayout'] ?? true,
                ], self::content($block, $ctx), I18n::t('Close Banner(banner)(aria-label)'));

            case 'InlineTOC':
                return nd_inline_toc(
                    $attrs['label'] ?? I18n::t('Table of Contents(inline table of contents)'),
                    self::inlineTocItems($ctx)
                );

            case 'ImageZoom':
                return nd_image_zoom([
                    'src' => $ctx->asset((string) $attrs['src']),
                    'alt' => $attrs['alt'] ?? null,
                    'width' => $attrs['width'] ?? null,
                    'height' => $attrs['height'] ?? null,
                ]);

            case 'Callout':
                return self::calloutWithIcon($attrs, self::content($block, $ctx));
        }

        throw new LogicException('unknown catalogue component in the AST: ' . $block['name']);
    }

    /**
     * Content of a component with Markdown children.
     *
     * When the content sits on the same line as the opening and closing tag
     * (`<Tab value="…">Text</Tab>`), MDX produces phrasing content without `<p>`;
     * the parser marks such nodes with `inline: true`. The single paragraph is
     * then unwrapped.
     *
     * @param array<string,mixed> $block
     */
    private static function content(array $block, RenderContext $ctx): string
    {
        /** @var list<array<string,mixed>> $blocks */
        $blocks = $block['blocks'] ?? [];
        if (($block['inline'] ?? false) === true && count($blocks) === 1 && (string) $blocks[0]['type'] === 'paragraph') {
            return Render::inlines($blocks[0]['inlines'], $ctx);
        }

        return Render::blocks($blocks, $ctx);
    }

    /**
     * Children of a container; the parser allows only the matching components there.
     *
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>>
     */
    private static function children(array $blocks, string $name): array
    {
        $out = [];
        foreach ($blocks as $block) {
            $type = (string) $block['type'];
            if (($type === 'component' || $type === 'component_void') && (string) $block['name'] === $name) {
                $out[] = $block;
                continue;
            }
            throw new ContentException('expected <' . $name . '>, found: ' . ($block['name'] ?? $type));
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $blocks */
    private static function fileNodes(array $blocks): string
    {
        $out = '';
        foreach ($blocks as $block) {
            $name = (string) ($block['name'] ?? '');
            if ($name === 'File') {
                $out .= nd_file($block['attrs']);
            } elseif ($name === 'Folder') {
                $out .= nd_folder($block['attrs'], self::fileNodes($block['blocks'] ?? []));
            } else {
                throw new ContentException('a file tree allows only <Folder> and <File>, found: ' . ($name ?: $block['type']));
            }
        }

        return $out;
    }

    /**
     * Entries of the InlineTOC: the same set as the table of contents on the
     * right (Toc::build over Document::headings(), including the footnote label),
     * the equivalent of `items={toc}`.
     *
     * @return list<array{depth:int, title:string, url:string}>
     */
    private static function inlineTocItems(RenderContext $ctx): array
    {
        $document = self::page($ctx)->document
            ?? throw new LogicException('<InlineTOC> needs RenderCatalogue::begin() before rendering.');

        return Toc::build($document->headings());
    }

    /**
     * Callout with a custom icon or single-line content: `icon ?? iconMap[type]` in
     * callout.js; the icon has no size classes (as with `icon={<X />}` in MDX).
     *
     * @param array<string,mixed> $attrs
     */
    private static function calloutWithIcon(array $attrs, string $children): string
    {
        $type = (string) ($attrs['type'] ?? 'info');
        $html = nd_callout([
            'type' => $type,
            'title' => isset($attrs['title']) ? Html::e((string) $attrs['title']) : null,
        ], $children);
        if (!isset($attrs['icon'])) {
            return $html;
        }
        $icon = Icons::svg((string) $attrs['icon']);
        $default = nd_callout_icon(nd_callout_type($type));

        if ($default !== '') {
            $at = strpos($html, $default);
            if ($at === false) {
                throw new LogicException('callout: type icon not found.');
            }

            return substr_replace($html, $icon, $at, strlen($default));
        }
        $stripe = Html::tag('div', ['role' => 'none', 'class' => 'nd-callout-stripe']);

        return (string) preg_replace('/' . preg_quote($stripe, '/') . '/', $stripe . $icon, $html, 1);
    }

    /**
     * GFM task list (`listItem` in mdast-util-to-hast): `ul/ol.contains-task-list`,
     * `li.task-list-item`, a disabled checkbox and a space at the start of the
     * first paragraph. React separates that space from the following text with a comment.
     *
     * @param array<string,mixed> $block
     */
    private static function taskList(array $block, RenderContext $ctx): string
    {
        $tight = (bool) $block['tight'];
        $items = '';

        foreach ($block['items'] as $item) {
            $isTask = array_key_exists('checked', $item);
            $checkbox = $isTask
                ? Html::voidTag('input', ['type' => 'checkbox', 'disabled' => true, 'checked' => (bool) $item['checked']])
                : '';

            $inner = '';
            $first = true;
            /** @var list<array<string,mixed>> $children */
            $children = $item['blocks'];
            if ($isTask && ($children === [] || (string) $children[0]['type'] !== 'paragraph')) {
                // No leading paragraph: to-hast creates an empty one, without the space.
                $inner .= $tight ? $checkbox : Html::tag('p', [], $checkbox);
                $first = false;
            }
            foreach ($children as $child) {
                if ((string) $child['type'] === 'paragraph') {
                    $text = Render::inlines($child['inlines'], $ctx);
                    if ($first && $isTask) {
                        $text = $checkbox . ($text !== '' ? ' <!-- -->' . $text : '');
                    }
                    $inner .= $tight ? $text : Html::tag('p', [], $text);
                } else {
                    $inner .= Render::blocks([$child], $ctx);
                }
                $first = false;
            }

            $items .= Html::tag('li', ['class' => $isTask ? 'task-list-item' : null], $inner);
        }

        $tag = (bool) $block['ordered'] ? 'ol' : 'ul';
        $start = (int) ($block['start'] ?? 1);

        return Html::tag($tag, [
            'class' => 'contains-task-list',
            'start' => $tag === 'ol' && $start !== 1 ? $start : null,
        ], $items);
    }

    // ------------------------------------------------------------------ Code

    /**
     * Code block. The frame comes from components/codeblock.php, colours and
     * lines from lib/Highlight.php. The highlighter rejects an unknown language with an
     * InvalidArgumentException; that is a content error (exit code 3) at the fence.
     *
     * @param array<string,mixed> $block
     */
    private static function codeBlock(array $block, string $variant, RenderContext $ctx): string
    {
        $file = __DIR__ . '/Highlight.php';
        if (!is_file($file)) {
            throw new LogicException(
                'code blocks need lib/Highlight.php (the PHP port of Shiki), and the file is missing. '
                . 'No code block is emitted without highlighting.'
            );
        }
        require_once $file;

        $dynamic = $variant === 'dynamic';
        // Contract of Highlight::code: value, lang and meta unchanged from the AST;
        // DynamicCodeBlock without meta, notations and icon (option dynamic).
        try {
            $result = $dynamic
                ? Highlight::code((string) $block['value'], $block['lang'] ?? null, [], ['dynamic' => true])
                : Highlight::code((string) $block['value'], $block['lang'] ?? null, $block['meta'] ?? []);
        } catch (\InvalidArgumentException $e) {
            $file = self::page($ctx)->document?->file ?? '';
            throw new ContentException(
                $e->getMessage(),
                $file === '' ? null : $file,
                self::codeLine($file, (string) ($block['lang'] ?? ''), $dynamic),
                $e
            );
        }

        $lineNumbers = $result['lineNumbers'] ?? false;
        $attrs = [];
        if ($lineNumbers !== false) {
            // In the original the pre properties reach the figure through `...props`.
            $attrs['data-line-numbers'] = 'true';
            if (is_int($lineNumbers)) {
                $attrs['data-line-numbers-start'] = (string) $lineNumbers;
            }
        }

        return nd_code_block([
            'title' => $result['title'] ?? null,
            'icon' => $result['icon'] ?? null,
            'allowCopy' => (bool) ($result['allowCopy'] ?? true),
            'variant' => $variant,
            'figureClass' => [(string) $result['preClass']],
            'figureAttrs' => $attrs,
            'style' => (string) $result['preStyle'],
            'lineNumbersStart' => $lineNumbers === false ? null : (is_int($lineNumbers) ? $lineNumbers : 1),
        ], (string) $result['code'], I18n::t('Copy Text(code block)(aria-label)'));
    }

    /**
     * Line of the first code fence (or `<DynamicCodeBlock lang="…">`) naming $lang in
     * $file. The AST carries no positions; rendering stops at the first block with an
     * unknown language, so the first match is the failing one. null when not found.
     */
    private static function codeLine(string $file, string $lang, bool $dynamic): ?int
    {
        if ($file === '' || $lang === '' || !is_file($file)) {
            return null;
        }
        $quoted = preg_quote($lang, '/');
        $pattern = $dynamic
            ? '/<DynamicCodeBlock\b[^>]*\blang=(["\'])' . $quoted . '\1/'
            : '/^[\s>]*(`{3,}|~{3,})\s*' . $quoted . '(\s|$)/';
        foreach (preg_split('/\r\n|\r|\n/', (string) file_get_contents($file)) ?: [] as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                return $index + 1;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $block */
    private static function codeTabs(array $block, RenderContext $ctx): string
    {
        $panels = [];
        foreach ($block['items'] as $item) {
            $html = '';
            foreach ($item['blocks'] as $code) {
                $html .= self::codeBlock($code, 'tab', $ctx);
            }
            $panels[] = ['value' => (string) $item['value'], 'html' => $html];
        }

        return nd_code_tabs((string) $block['defaultValue'], $block['groupId'] ?? null, $panels);
    }

    // ------------------------------------------------------------------ Inline

    /** @param array<string,mixed> $node */
    public static function handlesInline(array $node): bool
    {
        return in_array((string) $node['type'], ['delete', 'footnote_reference', 'break'], true);
    }

    /** @param array<string,mixed> $node */
    public static function inline(array $node, RenderContext $ctx): string
    {
        switch ((string) $node['type']) {
            case 'delete':
                return Html::tag('del', [], Render::inlines($node['inlines'], $ctx));

            case 'break':
                // to-hast appends a text node "\n" to `br`; React separates it from the
                // following text with a comment, so the DOM has two text nodes.
                return "<br>\n<!-- -->";

            case 'footnote_reference':
                $page = self::page($ctx);
                $id = mb_strtoupper((string) $node['identifier'], 'UTF-8');
                $index = array_search($id, $page->footnoteOrder, true);
                if ($index === false) {
                    $page->footnoteOrder[] = $id;
                    $counter = count($page->footnoteOrder);
                } else {
                    $counter = $index + 1;
                }
                $page->footnoteCounts[$id] = ($page->footnoteCounts[$id] ?? 0) + 1;

                return nd_footnote_ref(nd_footnote_safe_id($id), $counter, $page->footnoteCounts[$id]);
        }

        throw new LogicException('unknown catalogue inline type in the AST: ' . $node['type']);
    }

    // ------------------------------------------------------------------ Footnotes

    /**
     * The `section.footnotes` after the last block (`footer.js`). Only footnotes
     * that are referenced, in order of first reference; references inside
     * footnote texts extend the list during the loop, as in the original.
     */
    public static function footnotes(Document $document, RenderContext $ctx): string
    {
        $page = self::page($ctx);
        $definitions = [];
        foreach ($document->blocks as $block) {
            if ((string) $block['type'] === 'footnote_definition') {
                $definitions[mb_strtoupper((string) $block['identifier'], 'UTF-8')] ??= $block;
            }
        }

        $items = [];
        for ($index = 0; $index < count($page->footnoteOrder); $index++) {
            $id = $page->footnoteOrder[$index];
            if (!isset($definitions[$id])) {
                continue;
            }
            /** @var list<array<string,mixed>> $blocks */
            $blocks = $definitions[$id]['blocks'];
            $safeId = nd_footnote_safe_id($id);

            $last = $blocks === [] ? null : $blocks[count($blocks) - 1];
            if ($last !== null && (string) $last['type'] === 'paragraph') {
                $html = Render::blocks(array_slice($blocks, 0, -1), $ctx);
                $text = Render::inlines($last['inlines'], $ctx);
                $back = nd_footnote_backrefs($safeId, $index, $page->footnoteCounts[$id] ?? 0);
                $html .= Html::tag('p', [], $text . ' ' . $back);
            } else {
                $html = Render::blocks($blocks, $ctx);
                $html .= nd_footnote_backrefs($safeId, $index, $page->footnoteCounts[$id] ?? 0);
            }
            $items[] = ['safeId' => $safeId, 'html' => $html];
        }

        if ($items === []) {
            return '';
        }

        return nd_footnotes_section($items, $ctx->copyLabel);
    }
}
