<?php

declare(strict_types=1);

namespace Pholio;

use Closure;
use LogicException;

require_once __DIR__ . '/../I18n.php';
require_once __DIR__ . '/Html.php';
require_once __DIR__ . '/Icons.php';
require_once __DIR__ . '/Markdown.php';
require_once __DIR__ . '/Slug.php';
require_once __DIR__ . '/../components/heading.php';
require_once __DIR__ . '/../components/callout.php';
require_once __DIR__ . '/../components/card.php';
require_once __DIR__ . '/../components/screenshot.php';
require_once __DIR__ . '/../components/image.php';
require_once __DIR__ . '/../components/table.php';
require_once __DIR__ . '/RenderCatalogue.php';

/**
 * Everything the article body needs to know about its surroundings.
 *
 * A context belongs to exactly one page, because the slugger counts duplicate
 * headings per document. The rewrites are closures, so the builder can
 * configure base URLs without the renderer knowing about them.
 */
final class RenderContext
{
    /**
     * @param Slugger  $slugger   Assigns heading ids (github-slugger compatible).
     * @param Closure  $link      fn(string $href): string, rewrites link targets.
     * @param Closure  $asset     fn(string $src): string, rewrites image paths.
     * @param string   $copyLabel aria-label of the copy button on headings.
     * @param ?Closure $imageSize fn(string $src): ?array{0:int,1:int}, intrinsic size of an
     *                            inline image, read from the image file.
     *                            null: unknown.
     */
    public function __construct(
        public readonly Slugger $slugger,
        public readonly Closure $link,
        public readonly Closure $asset,
        public readonly string $copyLabel,
        public readonly ?Closure $imageSize = null,
    ) {
    }

    /**
     * Default context: prefix rewrites for links and images.
     *
     * - Links: `$linkPrefix` at a path boundary becomes `$baseUrl`. null: links stay unchanged.
     * - Images: `$assetPrefix` at a path boundary becomes `$assetTarget` (default `$baseUrl`).
     *   null: image paths stay unchanged.
     * - `$copyLabel` defaults to the translation of the heading anchor label.
     */
    public static function create(
        string $baseUrl = '/',
        ?string $assetPrefix = null,
        ?string $assetTarget = null,
        ?string $copyLabel = null,
        ?Closure $imageSize = null,
        ?string $linkPrefix = null,
    ): self {
        $assetTarget ??= $baseUrl;

        return new self(
            new Slugger(),
            static fn (string $href): string => $linkPrefix === null
                ? $href
                : self::replacePrefix($href, $linkPrefix, $baseUrl),
            static fn (string $src): string => $assetPrefix === null
                ? $src
                : self::replacePrefix($src, $assetPrefix, $assetTarget),
            $copyLabel ?? I18n::t('Copy Anchor Link(heading anchor)(aria-label)'),
            $imageSize,
        );
    }

    /** Replace a prefix, but only at a path boundary (`/`, `#`, `?` or the end). */
    public static function replacePrefix(string $value, string $from, string $to): string
    {
        if ($from === $to) {
            return $value;
        }
        if ($value === $from) {
            return $to;
        }
        foreach (['/', '#', '?'] as $boundary) {
            if (str_starts_with($value, $from . $boundary)) {
                // The site root "/" must not double the slash of the remaining path.
                return ($to === '/' && $boundary === '/' ? '' : $to) . substr($value, strlen($from));
            }
        }

        return $value;
    }

    public function link(string $href): string
    {
        return ($this->link)($href);
    }

    public function asset(string $src): string
    {
        return ($this->asset)($src);
    }

    /** @return array{0:int,1:int}|null */
    public function imageSize(string $src): ?array
    {
        if ($this->imageSize === null) {
            return null;
        }

        return ($this->imageSize)($src);
    }
}

/**
 * AST to HTML for the article body, the content of `div.prose`.
 *
 * Markdown elements map onto the components (headings to `Heading`, `a` to a
 * link, `img` to the framed image, `table` to a scroll frame), with the HTML
 * that remark/rehype write for GFM. Unknown nodes throw; there is no silent
 * fallback.
 */
final class Render
{
    /** The body of a page: the innerHTML of `div.prose`. */
    public static function body(Document $doc, RenderContext $ctx): string
    {
        RenderCatalogue::begin($doc, $ctx);

        return self::blocks($doc->blocks, $ctx) . RenderCatalogue::footnotes($doc, $ctx);
    }

    /** @param list<array<string,mixed>> $blocks */
    public static function blocks(array $blocks, RenderContext $ctx): string
    {
        $out = '';
        foreach ($blocks as $block) {
            $out .= self::block($block, $ctx);
        }

        return $out;
    }

    /** @param array<string,mixed> $block */
    private static function block(array $block, RenderContext $ctx): string
    {
        $type = (string) $block['type'];
        if (RenderCatalogue::handlesBlock($block)) {
            return RenderCatalogue::block($block, $ctx);
        }

        switch ($type) {
            case 'heading':
                return self::heading($block, $ctx);

            case 'paragraph':
                return Html::tag('p', [], self::inlines($block['inlines'], $ctx));

            case 'list':
                return self::list($block, $ctx);

            case 'table':
                return self::table($block, $ctx);

            case 'blockquote':
                return Html::tag('blockquote', [], self::blocks($block['blocks'], $ctx));

            case 'thematic_break':
                return '<hr>';

            case 'component':
            case 'component_void':
                return self::component($block, $ctx);
        }

        throw new LogicException('unknown block type in the AST: ' . $type);
    }

    /** @param array<string,mixed> $block */
    private static function heading(array $block, RenderContext $ctx): string
    {
        $text = Markdown::plainText($block['inlines']);

        return nd_heading(
            [
                'level' => (int) $block['level'],
                'id' => $ctx->slugger->slug($text),
                'copyLabel' => $ctx->copyLabel,
            ],
            self::inlines($block['inlines'], $ctx)
        );
    }

    /**
     * List. `tight` decides whether paragraphs inside items are unwrapped, the
     * same rule as in mdast-util-to-hast. `start` appears only on ordered lists
     * that don't begin at 1.
     *
     * @param array<string,mixed> $block
     */
    private static function list(array $block, RenderContext $ctx): string
    {
        $tight = (bool) $block['tight'];
        $items = '';

        /** @var list<array{blocks:list<array<string,mixed>>}> $entries */
        $entries = $block['items'];
        foreach ($entries as $item) {
            $inner = '';
            foreach ($item['blocks'] as $child) {
                if ($tight && (string) $child['type'] === 'paragraph') {
                    $inner .= self::inlines($child['inlines'], $ctx);
                    continue;
                }
                $inner .= self::block($child, $ctx);
            }
            $items .= Html::tag('li', [], $inner);
        }

        if (!(bool) $block['ordered']) {
            return Html::tag('ul', [], $items);
        }

        $start = (int) $block['start'];

        return Html::tag('ol', $start === 1 ? [] : ['start' => $start], $items);
    }

    /**
     * Table. The header row goes into `thead`, every other row into `tbody`;
     * `tbody` is omitted when there are no data rows (as remark-gfm does).
     *
     * @param array<string,mixed> $block
     */
    private static function table(array $block, RenderContext $ctx): string
    {
        /** @var list<string|null> $align */
        $align = $block['align'];

        $head = '';
        foreach ($block['head'] as $column => $cell) {
            $head .= nd_table_cell('th', $align[$column] ?? null, self::inlines($cell, $ctx));
        }
        $inner = Html::tag('thead', [], Html::tag('tr', [], $head));

        $rows = '';
        foreach ($block['rows'] as $row) {
            $cells = '';
            foreach ($row as $column => $cell) {
                $cells .= nd_table_cell('td', $align[$column] ?? null, self::inlines($cell, $ctx));
            }
            $rows .= Html::tag('tr', [], $cells);
        }
        if ($rows !== '') {
            $inner .= Html::tag('tbody', [], $rows);
        }

        return nd_table([], $inner);
    }

    /** @param array<string,mixed> $block */
    private static function component(array $block, RenderContext $ctx): string
    {
        $name = (string) $block['name'];
        /** @var array<string,string> $attrs */
        $attrs = $block['attrs'];
        $children = isset($block['blocks']) ? self::blocks($block['blocks'], $ctx) : '';

        switch ($name) {
            case 'Callout':
                return nd_callout(
                    [
                        'type' => $attrs['type'] ?? 'info',
                        'title' => isset($attrs['title']) ? Html::e($attrs['title']) : null,
                    ],
                    $children
                );

            case 'Cards':
                return nd_cards([], $children);

            case 'Card':
                return nd_card([
                    'title' => Html::e($attrs['title'] ?? ''),
                    'description' => isset($attrs['description']) ? Html::e($attrs['description']) : null,
                    'href' => isset($attrs['href']) ? $ctx->link($attrs['href']) : null,
                    'icon' => isset($attrs['icon']) ? Icons::svg($attrs['icon']) : null,
                ], $children);

            case 'Screenshot':
                return nd_screenshot([
                    'src' => $ctx->asset($attrs['src']),
                    'dark' => isset($attrs['dark']) ? $ctx->asset($attrs['dark']) : null,
                    'alt' => $attrs['alt'] ?? null,
                ]);
        }

        throw new LogicException('unknown component in the AST: ' . $name);
    }

    // ------------------------------------------------------------------ Inline

    /** @param list<array<string,mixed>> $inlines */
    public static function inlines(array $inlines, RenderContext $ctx): string
    {
        $out = '';
        foreach ($inlines as $node) {
            $out .= self::inline($node, $ctx);
        }

        return $out;
    }

    /** @param array<string,mixed> $node */
    private static function inline(array $node, RenderContext $ctx): string
    {
        $type = (string) $node['type'];
        if (RenderCatalogue::handlesInline($node)) {
            return RenderCatalogue::inline($node, $ctx);
        }

        switch ($type) {
            case 'text':
                return Html::e((string) $node['value']);

            case 'code':
                return Html::tag('code', [], Html::e((string) $node['value']));

            case 'strong':
                return Html::tag('strong', [], self::inlines($node['inlines'], $ctx));

            case 'emphasis':
                return Html::tag('em', [], self::inlines($node['inlines'], $ctx));

            case 'break':
                return '<br>';

            case 'link':
                return self::link($node, $ctx);

            case 'image':
                return self::image($node, $ctx);
        }

        throw new LogicException('unknown inline type in the AST: ' . $type);
    }

    /**
     * Link. `rel`/`target` are added only to external targets
     * (`^\w+:` or `//`); internal links stay plain `a` elements.
     *
     * @param array<string,mixed> $node
     */
    private static function link(array $node, RenderContext $ctx): string
    {
        $href = (string) $node['href'];
        $attrs = ['href' => $ctx->link($href)];
        if (Html::isExternal($href)) {
            $attrs['rel'] = 'noreferrer noopener';
            $attrs['target'] = '_blank';
        }

        return Html::tag('a', $attrs, self::inlines($node['inlines'], $ctx));
    }

    /** @param array<string,mixed> $node */
    private static function image(array $node, RenderContext $ctx): string
    {
        $src = (string) $node['src'];
        $size = $ctx->imageSize($src);

        return nd_image([
            'src' => $ctx->asset($src),
            'alt' => (string) $node['alt'],
            'width' => $size[0] ?? null,
            'height' => $size[1] ?? null,
        ]);
    }
}
