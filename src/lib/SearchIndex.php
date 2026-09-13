<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/Markdown.php';
require_once __DIR__ . '/Slug.php';
require_once __DIR__ . '/Tree.php';

/**
 * Build-time search index. The documents are produced exactly as Fumadocs does.
 *
 * Chain in the reference (fumadocs-core 16.15.9):
 *   remark-structure  → `structuredData` per page (headings and text blocks),
 *   buildDocuments    → one `page` document per page, an optional `text` document
 *                       for the description, then every heading, then every text
 *                       block, ids `<url>` and `<url>-<n>`,
 *   zbsearch          → full-text index over the `content` field.
 *
 * This file produces the document list; `theme/js/search.js` ranks it from the
 * JSON output (`search-index.json`).
 *
 * JSON shape (compact and specific to Pholio, because the client is ours too):
 *   {
 *     "base":      "/docs",
 *     "tokenizer": "english" | "german",
 *     "pages":     [ { "u": <url>, "t": <title>, "b": [<breadcrumbs>]|null } … ],
 *     "docs":      [ [ <page index>, <type>, <number>|null, <anchor>|null, <content> ] … ]
 *   }
 * Type: 0 = page, 1 = heading, 2 = text. `number` is the `-<n>` of the id (null
 * for the `page` document), `anchor` the part after `#` (null = page URL).
 * The order of `docs` is the insertion order of `insertMultipleAsync` and thus
 * the internal document id, which breaks ties between equal scores.
 * `tokenizer` names the zbsearch splitter profile `search.js` tokenizes with.
 */
final class SearchIndex
{
    public const TYPE_PAGE = 0;
    public const TYPE_HEADING = 1;
    public const TYPE_TEXT = 2;

    /** Splitter profiles `theme/js/search.js` implements, named after the zbsearch languages. */
    public const TOKENIZERS = ['english', 'german'];

    /**
     * @param callable(array{url:string,slugs:list<string>,file:string,data:array<string,mixed>}):Document $loadDocument
     * @param list<string>|null $fileOrder Order of the source files (relative to the
     *        content directory). It determines the internal document ids and thus
     *        which hit comes first when two scores are exactly equal. Without it,
     *        the tree's alphabetical order applies. A parity run against a
     *        reference passes the reference bundler's order here, because it
     *        can't be derived from the file system.
     * @param bool $includeDrafts Pages whose file name starts with `_` (drafts,
     *        sample pages) are only indexed with `--dev`.
     * @param string $tokenizer Splitter profile recorded in the index, one of TOKENIZERS.
     * @return array{base:string, tokenizer:string, pages:list<array{u:string,t:string,b:?list<string>}>, docs:list<array{0:int,1:int,2:?int,3:?string,4:string}>}
     */
    public static function build(
        Tree $tree,
        callable $loadDocument,
        string $baseUrl,
        ?array $fileOrder = null,
        bool $includeDrafts = false,
        string $tokenizer = 'english'
    ): array {
        if (!in_array($tokenizer, self::TOKENIZERS, true)) {
            throw new \InvalidArgumentException(
                'Unknown search tokenizer "' . $tokenizer . '", expected one of: ' . implode(', ', self::TOKENIZERS)
            );
        }

        $pages = [];
        $docs = [];

        foreach (self::orderPages($tree->pages(), $fileOrder) as $page) {
            if (!$includeDrafts && str_starts_with(basename((string) $page['file']), '_')) {
                continue;
            }
            $url = (string) $page['url'];
            if (!str_starts_with($url, $baseUrl)) {
                throw new \RuntimeException('Page URL does not start with the base URL: ' . $url);
            }

            $document = $loadDocument($page);
            $structured = self::structuredData($document);

            $data = $page['data'];
            $title = (string) ($data['title'] ?? '');
            $description = isset($data['description']) ? (string) $data['description'] : null;

            $pageIndex = count($pages);
            $pages[] = [
                'u' => $url,
                't' => $title,
                'b' => self::breadcrumbs($tree, $url),
            ];

            $docs[] = [$pageIndex, self::TYPE_PAGE, null, null, $title];

            $number = 0;
            if ($description !== null && $description !== '') {
                $duplicate = false;
                foreach ($structured['contents'] as $item) {
                    if ($item['content'] === $description) {
                        $duplicate = true;
                        break;
                    }
                }
                if (!$duplicate) {
                    $docs[] = [$pageIndex, self::TYPE_TEXT, $number++, null, $description];
                }
            }

            foreach ($structured['headings'] as $heading) {
                $docs[] = [$pageIndex, self::TYPE_HEADING, $number++, $heading['id'], $heading['content']];
            }

            foreach ($structured['contents'] as $content) {
                $docs[] = [$pageIndex, self::TYPE_TEXT, $number++, $content['heading'], $content['content']];
            }
        }

        return ['base' => $baseUrl, 'tokenizer' => $tokenizer, 'pages' => $pages, 'docs' => $docs];
    }

    /**
     * Pages in the requested insertion order.
     *
     * @param list<array{url:string,slugs:list<string>,file:string,data:array<string,mixed>}> $pages
     * @param list<string>|null $fileOrder
     * @return list<array{url:string,slugs:list<string>,file:string,data:array<string,mixed>}>
     */
    private static function orderPages(array $pages, ?array $fileOrder): array
    {
        if ($fileOrder === null) {
            return $pages;
        }

        $rank = array_flip($fileOrder);
        $indexed = [];
        foreach ($pages as $position => $page) {
            $file = (string) $page['file'];
            if (!isset($rank[$file])) {
                throw new \RuntimeException('File missing from the given page order: ' . $file);
            }
            $indexed[] = [$rank[$file], $position, $page];
        }
        usort($indexed, static fn(array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        return array_map(static fn(array $item): array => $item[2], $indexed);
    }

    /**
     * Breadcrumbs like `buildBreadcrumbs`: the tree root's name plus the names of
     * all named nodes on the path, without the page itself. That includes folders
     * and separators, because `findPath` returns both. Pages outside the tree
     * (the root index page) have no breadcrumbs.
     *
     * @return list<string>|null
     */
    private static function breadcrumbs(Tree $tree, string $url): ?array
    {
        $path = $tree->pathTo($url);
        if ($path === []) {
            return null;
        }

        $items = [];
        $rootName = $tree->root()->name;
        if (is_string($rootName) && $rootName !== '') {
            $items[] = $rootName;
        }
        array_pop($path); // the page itself
        foreach ($path as $node) {
            if (is_string($node->name) && $node->name !== '') {
                $items[] = $node->name;
            }
        }

        return $items;
    }

    /**
     * `remarkStructure` with its default options: it visits headings, paragraphs,
     * blockquotes, table cells and childless component tags. Every visited node
     * is serialised as Markdown, trimmed and, when not empty, stored as content
     * under the most recently seen heading.
     *
     * @return array{headings:list<array{id:string,content:string}>, contents:list<array{heading:?string,content:string}>}
     */
    public static function structuredData(Document $document): array
    {
        $state = [
            'headings' => [],
            'contents' => [],
            'lastHeading' => null,
            'slugger' => new Slugger(),
        ];

        self::visitBlocks($document->blocks, $state);

        return ['headings' => $state['headings'], 'contents' => $state['contents']];
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @param array<string,mixed> $state
     */
    private static function visitBlocks(array $blocks, array &$state): void
    {
        foreach ($blocks as $block) {
            self::visitBlock($block, $state);
        }
    }

    /** @param array<string,mixed> $state */
    private static function visitBlock(array $block, array &$state): void
    {
        $type = (string) $block['type'];

        switch ($type) {
            case 'heading':
                /** @var list<array<string,mixed>> $inlines */
                $inlines = $block['inlines'];
                $id = $state['slugger']->slug(Markdown::plainText($inlines));
                $content = trim(MdStringifier::run($block));
                if ($content !== '') {
                    $state['headings'][] = ['id' => $id, 'content' => $content];
                }
                $state['lastHeading'] = $id;
                return;

            case 'paragraph':
            case 'blockquote':
                self::addContent(MdStringifier::run($block), $state);
                return;

            case 'table':
                /** @var list<list<array<string,mixed>>> $head */
                $head = $block['head'];
                foreach ($head as $cell) {
                    self::addContent(MdStringifier::run(['type' => 'table_cell', 'inlines' => $cell]), $state);
                }
                /** @var list<list<list<array<string,mixed>>>> $rows */
                $rows = $block['rows'];
                foreach ($rows as $row) {
                    foreach ($row as $cell) {
                        self::addContent(MdStringifier::run(['type' => 'table_cell', 'inlines' => $cell]), $state);
                    }
                }
                return;

            case 'list':
                /** @var list<array{blocks:list<array<string,mixed>>}> $items */
                $items = $block['items'];
                foreach ($items as $item) {
                    self::visitBlocks($item['blocks'], $state);
                }
                return;

            case 'component':
                if (($block['inline'] ?? false) === true) {
                    // `<Tab>Text</Tab>` on one line: remark-mdx turns it into a flow
                    // element with phrasing children, which `remark-structure` never
                    // visits, so the reference index lacks this text.
                    return;
                }
                if ($block['name'] === 'TypeTable') {
                    // In the reference a childless tag with a `type={{…}}` expression.
                    self::addContent(MdStringifier::run($block), $state);
                    return;
                }
                /** @var list<array<string,mixed>> $inner */
                $inner = $block['blocks'];
                if ($inner === []) {
                    // Childless: the tag itself is serialised (`mdxTypes`).
                    self::addContent(MdStringifier::run($block), $state);
                    return;
                }
                self::visitBlocks($inner, $state);
                return;

            case 'component_void':
                self::addContent(MdStringifier::run($block), $state);
                return;

            case 'footnote_definition':
                // `footnoteDefinition` is not in `types`, but its paragraphs are.
                /** @var list<array<string,mixed>> $inner */
                $inner = $block['blocks'];
                self::visitBlocks($inner, $state);
                return;

            default:
                // code_block, code_tabs (tabs with code blocks in the reference),
                // component_raw (DynamicCodeBlock, childless and `children-only`),
                // thematic_break, image … produce no content.
                return;
        }
    }

    /** @param array<string,mixed> $state */
    private static function addContent(string $raw, array &$state): void
    {
        $content = trim($raw);
        if ($content === '') {
            return;
        }
        $state['contents'][] = ['heading' => $state['lastHeading'], 'content' => $content];
    }
}

/**
 * Serialises an AST node to Markdown. A port of `mdast-util-to-markdown` (MIT)
 * to the extent `remark-structure` uses it, including the peculiarities of the
 * Fumadocs stringifier:
 *
 * - `link` and `heading` output only their content (Fumadocs handlers),
 * - `image` outputs nothing,
 * - component tags are written like `mdast-util-mdx-jsx`; Screenshot and other
 *   unknown tags only as their content (`children-only` → empty),
 * - Fumadocs wraps the handlers and they lose their `peek` on the way;
 *   `containerPhrasing` therefore calls the next sibling's full handler to learn
 *   its first character. That dry run sets `attentionEncodeSurroundingInfo`,
 *   which is exactly what produces character references such as
 *   `&#x2A;*bold**` in the reference.
 */
final class MdStringifier
{
    /** Components written as a tag; all others output only their content. */
    private const KEEP_TAGS = ['File', 'TypeTable', 'Callout', 'Card'];

    /** Constructs in which attention characters are not escaped. */
    private const FULL_PHRASING_SPANS = [
        'autolink',
        'destinationLiteral',
        'destinationRaw',
        'reference',
        'titleQuote',
        'titleApostrophe',
    ];

    /** @var list<string> */
    private array $stack = [];
    /** @var array{after:bool,before:bool}|null */
    private ?array $attention = null;
    /** @var list<array{character:string,before?:string,after?:string,atBreak?:bool,inConstruct?:string|list<string>,notInConstruct?:string|list<string>}> */
    private array $unsafe;

    private function __construct()
    {
        $this->unsafe = self::unsafePatterns();
    }

    /** @param array<string,mixed> $node */
    public static function run(array $node): string
    {
        $state = new self();
        $value = $state->handle($node, ['before' => "\n", 'after' => "\n"]);

        return $value;
    }

    // ------------------------------------------------------------------ Handler

    /**
     * @param array<string,mixed> $node
     * @param array{before:string,after:string} $info
     */
    private function handle(array $node, array $info): string
    {
        $type = (string) $node['type'];

        switch ($type) {
            case 'paragraph':
                $exit = $this->enter('paragraph');
                $sub = $this->enter('phrasing');
                $value = $this->containerPhrasing($node['inlines'], $info);
                $sub();
                $exit();
                return $value;

            // Fumadocs replaces the `heading` and `link` handlers with
            // `containerPhrasing`, without `enter('phrasing')`.
            case 'heading':
            case 'link':
                return $this->containerPhrasing($node['inlines'], $info);

            case 'table_cell':
                $exit = $this->enter('tableCell');
                $sub = $this->enter('phrasing');
                $value = $this->containerPhrasing($node['inlines'], ['before' => '|', 'after' => '|']);
                $sub();
                $exit();
                return $value;

            case 'blockquote':
                $exit = $this->enter('blockquote');
                $value = $this->containerFlow($node['blocks']);
                $exit();
                return self::indentLines($value);

            case 'text':
                return $this->safe((string) $node['value'], $info);

            case 'strong':
                return $this->attention($node, $info, '**');

            case 'emphasis':
                return $this->attention($node, $info, '*');

            case 'code':
                return $this->inlineCode((string) $node['value']);

            case 'delete':
                // `handleDelete` from mdast-util-gfm-strikethrough.
                $exit = $this->enter('strikethrough');
                $value = '~~' . $this->containerPhrasing($node['inlines'], ['before' => '~~', 'after' => '~']) . '~~';
                $exit();
                return $value;

            case 'footnote_reference':
                // `footnoteReference` from mdast-util-gfm-footnote.
                $exit = $this->enter('footnoteReference');
                $sub = $this->enter('reference');
                $label = $this->safe((string) ($node['label'] ?? $node['identifier']), ['before' => '[^', 'after' => ']']);
                $sub();
                $exit();
                return '[^' . $label . ']';

            case 'footnote_definition':
                return $this->containerFlow($node['blocks']);

            case 'code_block':
            case 'code_tabs':
            case 'component_raw':
                return '';

            case 'break':
                return $this->hardBreak($info);

            case 'image':
                return '';

            case 'component':
            case 'component_void':
                return $this->jsxElement($node);

            case 'list':
            case 'table':
            case 'thematic_break':
                return '';

            default:
                throw new \RuntimeException('No serialiser for node type: ' . $type);
        }
    }

    /**
     * `containerPhrasing`, including the dry run for the following character.
     *
     * @param list<array<string,mixed>> $children
     * @param array{before:string,after:string} $info
     */
    private function containerPhrasing(array $children, array $info): string
    {
        $results = [];
        $before = $info['before'];
        $encodeAfter = null;

        for ($index = 0; $index < count($children); $index++) {
            $child = $children[$index];

            if ($index + 1 < count($children)) {
                // Without `peek` the reference calls the full handler, with all
                // its side effects.
                $next = $this->handle($children[$index + 1], ['before' => '', 'after' => '']);
                $after = $next === '' ? '' : self::firstChar($next);
            } else {
                $after = $info['after'];
            }

            $value = $this->handle($child, ['before' => $before, 'after' => $after]);

            if ($encodeAfter !== null && $encodeAfter === self::firstChar($value)) {
                $value = self::encodeCharacterReference(self::codePointAt($value, 0))
                    . substr($value, strlen(self::firstChar($value)));
            }

            $info2 = $this->attention;
            $this->attention = null;
            $encodeAfter = null;

            if ($info2 !== null) {
                if ($results !== [] && $info2['before'] && $before === self::lastChar($results[count($results) - 1])) {
                    $last = $results[count($results) - 1];
                    $results[count($results) - 1] = substr($last, 0, strlen($last) - strlen($before))
                        . self::encodeCharacterReference(self::codePointOf($before));
                }
                if ($info2['after']) {
                    $encodeAfter = $after;
                }
            }

            $results[] = $value;
            $before = self::lastChar($value);
        }

        return implode('', $results);
    }

    /**
     * `containerFlow`, needed only for blockquotes: blocks separated by a blank
     * line.
     *
     * @param list<array<string,mixed>> $blocks
     */
    private function containerFlow(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            $value = $this->handle($block, ['before' => "\n", 'after' => "\n"]);
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode("\n\n", $parts);
    }

    private static function indentLines(string $value): string
    {
        $lines = explode("\n", $value);
        foreach ($lines as $i => $line) {
            $lines[$i] = $line === '' ? '>' : '> ' . $line;
        }

        return implode("\n", $lines);
    }

    /**
     * `emphasis`/`strong` from mdast-util-to-markdown.
     *
     * @param array<string,mixed> $node
     * @param array{before:string,after:string} $info
     */
    private function attention(array $node, array $info, string $marker): string
    {
        $construct = $marker === '**' ? 'strong' : 'emphasis';
        $exit = $this->enter($construct);
        $between = $this->containerPhrasing($node['inlines'], [
            'before' => $marker,
            'after' => substr($marker, 0, 1),
        ]);

        $open = self::encodeInfo(
            self::codePointOf(self::lastChar($info['before'])),
            self::codePointAt($between, 0),
            substr($marker, 0, 1)
        );
        if ($open['inside'] && $between !== '') {
            $head = self::firstChar($between);
            $between = self::encodeCharacterReference(self::codePointOf($head)) . substr($between, strlen($head));
        }

        $close = self::encodeInfo(
            self::codePointOf(self::firstChar($info['after'])),
            self::codePointOf(self::lastChar($between)),
            substr($marker, 0, 1)
        );
        if ($close['inside'] && $between !== '') {
            $tail = self::lastChar($between);
            $between = substr($between, 0, strlen($between) - strlen($tail))
                . self::encodeCharacterReference(self::codePointOf($tail));
        }

        $exit();
        $this->attention = ['after' => $close['outside'], 'before' => $open['outside']];

        return $marker . $between . $marker;
    }

    /** `inlineCode` from mdast-util-to-markdown (without the table variant). */
    private function inlineCode(string $value): string
    {
        $sequence = '`';
        while (preg_match('/(^|[^`])' . $sequence . '([^`]|$)/', $value) === 1) {
            $sequence .= '`';
        }

        if (
            preg_match('/[^ \r\n]/', $value) === 1
            && ((preg_match('/^[ \r\n]/', $value) === 1 && preg_match('/[ \r\n]$/', $value) === 1)
                || preg_match('/^`|`$/', $value) === 1)
        ) {
            $value = ' ' . $value . ' ';
        }

        foreach ($this->unsafe as $pattern) {
            if (($pattern['atBreak'] ?? false) !== true) {
                continue;
            }
            $expression = self::compilePattern($pattern);
            $offset = 0;
            while ($offset <= strlen($value) && preg_match($expression, $value, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $position = $match[0][1];
                $start = $position;
                if (($value[$position] ?? '') === "\n" && ($value[$position - 1] ?? '') === "\r") {
                    $start--;
                }
                $value = substr($value, 0, $start) . ' ' . substr($value, $position + 1);
                $offset = $start + 1;
            }
        }

        return $sequence . $value . $sequence;
    }

    /** @param array{before:string,after:string} $info */
    private function hardBreak(array $info): string
    {
        foreach ($this->unsafe as $pattern) {
            if ($pattern['character'] === "\n" && $this->patternInScope($pattern)) {
                return preg_match('/[ \t]/', $info['before']) === 1 ? '' : ' ';
            }
        }

        return "\\\n";
    }

    /**
     * Component tag like `mdast-util-mdx-jsx` (`printWidth` infinite, quote `"`,
     * `tightSelfClosing` off).
     *
     * @param array<string,mixed> $node
     */
    private function jsxElement(array $node): string
    {
        $name = (string) $node['name'];
        /** @var list<array<string,mixed>> $children */
        $children = $node['blocks'] ?? [];
        $selfClosing = $children === [];

        if (!in_array($name, self::KEEP_TAGS, true)) {
            // `children-only`: only the content, so nothing for childless tags.
            if ($selfClosing) {
                return '';
            }
            return $this->containerFlow($children);
        }

        if ($name === 'TypeTable') {
            return self::typeTable($children);
        }

        $exit = $this->enter('mdxJsxFlowElement');
        $attributes = [];
        /** @var array<string,mixed> $attrs */
        $attrs = $node['attrs'] ?? [];
        foreach ($attrs as $key => $value) {
            $string = self::attributeString($name, (string) $key, $value);
            if ($string === null || $string === '') {
                continue;
            }
            $attributes[] = $key . '="' . str_replace('"', '&#x22;', $string) . '"';
        }

        $value = '<' . $name;
        if ($attributes !== []) {
            $value .= ' ' . implode(' ', $attributes);
        }
        if ($selfClosing) {
            $value .= ' /';
        }
        $value .= '>';

        if (!$selfClosing) {
            $value .= "\n" . $this->containerFlow($children) . "\n";
            $value .= '</' . $name . '>';
        }

        $exit();

        return $value;
    }

    /**
     * Attribute value as the Fumadocs stringifier sees it: strings as they are,
     * MDX expressions as their source text (`attr.value.value`). Pholio's grammar
     * writes expressions declaratively, so the reference's source text is
     * recovered here. The boolean shorthand (`persist`) has no value in the
     * reference and is dropped.
     */
    private static function attributeString(string $component, string $key, mixed $value): ?string
    {
        if ($value === null || $value === true) {
            return null;
        }
        if ($value === false) {
            return 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            return '[' . implode(', ', array_map(static fn($item): string => self::jsLiteral($item), $value)) . ']';
        }
        if ($component === 'Card' && $key === 'icon') {
            // `icon="book-open"` stands for `icon={<BookOpen />}`.
            $pascal = implode('', array_map('ucfirst', explode('-', (string) $value)));

            return '<' . $pascal . ' />';
        }

        return (string) $value;
    }

    /** JavaScript literal in the catalogue's style: single quotes. */
    private static function jsLiteral(mixed $value): string
    {
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value) . "'";
    }

    /**
     * `<TypeTable>` with `<TypeProp>` children, written as the childless reference
     * tag `<TypeTable type={{ name: { description, … } }} />`. The expression
     * contains line breaks, so mdast-util-mdx-jsx puts the attribute on its own
     * line and closes with `/>` without a space.
     *
     * @param list<array<string,mixed>> $children
     */
    private static function typeTable(array $children): string
    {
        $lines = ['{'];
        foreach ($children as $prop) {
            if (($prop['type'] ?? '') !== 'component' || ($prop['name'] ?? '') !== 'TypeProp') {
                continue;
            }
            /** @var array<string,mixed> $attrs */
            $attrs = $prop['attrs'] ?? [];
            $description = [];
            foreach ($prop['blocks'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'paragraph') {
                    $description[] = Markdown::plainText($block['inlines']);
                }
            }
            $lines[] = '  ' . (string) ($attrs['name'] ?? '') . ': {';
            if ($description !== []) {
                $lines[] = '    description: ' . self::jsLiteral(implode(' ', $description)) . ',';
            }
            foreach ($attrs as $key => $value) {
                if ($key === 'name') {
                    continue;
                }
                $lines[] = '    ' . $key . ': ' . self::jsLiteral($value) . ',';
            }
            $lines[] = '  },';
        }
        $lines[] = '}';
        $literal = implode("\n", $lines);

        return "<TypeTable\n  type=\"" . str_replace('"', '&#x22;', $literal) . "\"\n/>";
    }

    // -------------------------------------------------------------------- safe

    /**
     * `safe` from mdast-util-to-markdown: escapes characters that could take on
     * Markdown meaning in the current context.
     *
     * @param array{before:string,after:string} $info
     */
    private function safe(string $input, array $info): string
    {
        $value = $info['before'] . $input . $info['after'];
        $positions = [];
        $infos = [];

        foreach ($this->unsafe as $pattern) {
            if (!$this->patternInScope($pattern)) {
                continue;
            }
            $expression = self::compilePattern($pattern);
            $offset = 0;
            while (
                $offset <= strlen($value)
                && preg_match($expression, $value, $match, PREG_OFFSET_CAPTURE, $offset) === 1
            ) {
                $before = isset($pattern['before']) || ($pattern['atBreak'] ?? false) === true;
                $after = isset($pattern['after']);
                $position = $match[0][1] + ($before ? strlen($match[1][0] ?? '') : 0);

                if (in_array($position, $positions, true)) {
                    if ($infos[$position]['before'] && !$before) {
                        $infos[$position]['before'] = false;
                    }
                    if ($infos[$position]['after'] && !$after) {
                        $infos[$position]['after'] = false;
                    }
                } else {
                    $positions[] = $position;
                    $infos[$position] = ['before' => $before, 'after' => $after];
                }

                $offset = $match[0][1] + max(1, strlen($match[0][0]));
                // JS `lastIndex` sits after the whole match.
                $offset = $match[0][1] + strlen($match[0][0]);
                if ($offset <= $match[0][1]) {
                    $offset = $match[0][1] + 1;
                }
            }
        }

        sort($positions);

        $result = [];
        $start = strlen($info['before']);
        $end = strlen($value) - strlen($info['after']);

        for ($index = 0; $index < count($positions); $index++) {
            $position = $positions[$index];
            if ($position < $start || $position >= $end) {
                continue;
            }

            $next = $positions[$index + 1] ?? null;
            $previous = $positions[$index - 1] ?? null;
            if (
                ($position + 1 < $end && $next === $position + 1 && $infos[$position]['after']
                    && !$infos[$position + 1]['before'] && !$infos[$position + 1]['after'])
                || ($previous === $position - 1 && $infos[$position]['before']
                    && !$infos[$position - 1]['before'] && !$infos[$position - 1]['after'])
            ) {
                continue;
            }

            if ($start !== $position) {
                $result[] = self::escapeBackslashes(substr($value, $start, $position - $start), '\\');
            }

            $start = $position;
            $character = $value[$position];

            if (preg_match('/[!-\/:-@\[-`{-~]/', $character) === 1) {
                $result[] = '\\';
            } else {
                $result[] = self::encodeCharacterReference(self::codePointAt($value, $position));
                $start++;
            }
        }

        $result[] = self::escapeBackslashes(substr($value, $start, $end - $start), $info['after']);

        return implode('', $result);
    }

    private static function escapeBackslashes(string $value, string $after): string
    {
        $whole = $value . $after;
        $positions = [];
        $offset = 0;
        while (preg_match('/\\\\(?=[!-\/:-@\[-`{-~])/', $whole, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $positions[] = $match[0][1];
            $offset = $match[0][1] + 1;
        }

        $results = [];
        $start = 0;
        foreach ($positions as $position) {
            if ($position >= strlen($value)) {
                break;
            }
            if ($start !== $position) {
                $results[] = substr($value, $start, $position - $start);
            }
            $results[] = '\\';
            $start = $position;
        }
        $results[] = substr($value, $start);

        return implode('', $results);
    }

    /** @param array<string,mixed> $pattern */
    private function patternInScope(array $pattern): bool
    {
        return $this->listInScope($pattern['inConstruct'] ?? null, true)
            && !$this->listInScope($pattern['notInConstruct'] ?? null, false);
    }

    /** @param string|list<string>|null $list */
    private function listInScope(string|array|null $list, bool $none): bool
    {
        if (is_string($list)) {
            $list = [$list];
        }
        if ($list === null || $list === []) {
            return $none;
        }
        foreach ($list as $item) {
            if (in_array($item, $this->stack, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $pattern */
    private static function compilePattern(array $pattern): string
    {
        $before = (($pattern['atBreak'] ?? false) === true ? '[\\r\\n][\\t ]*' : '')
            . (isset($pattern['before']) ? '(?:' . $pattern['before'] . ')' : '');

        $source = ($before !== '' ? '(' . $before . ')' : '')
            . (preg_match('/[|\\\\{}()\[\]^$+*?.-]/', $pattern['character']) === 1 ? '\\' : '')
            . $pattern['character']
            . (isset($pattern['after']) ? '(?:' . $pattern['after'] . ')' : '');

        return '%' . $source . '%';
    }

    private function enter(string $name): \Closure
    {
        $this->stack[] = $name;

        return function (): void {
            array_pop($this->stack);
        };
    }

    // ------------------------------------------------------------ Characters

    /**
     * `encodeInfo` from mdast-util-to-markdown.
     *
     * @return array{inside:bool,outside:bool}
     */
    private static function encodeInfo(?int $outside, ?int $inside, string $marker): array
    {
        $outsideKind = self::classify($outside);
        $insideKind = self::classify($inside);

        if ($outsideKind === null) {
            if ($insideKind === null) {
                return $marker === '_'
                    ? ['inside' => true, 'outside' => true]
                    : ['inside' => false, 'outside' => false];
            }
            if ($insideKind === 1) {
                return ['inside' => true, 'outside' => true];
            }
            return ['inside' => false, 'outside' => true];
        }

        if ($outsideKind === 1) {
            if ($insideKind === null) {
                return ['inside' => false, 'outside' => false];
            }
            if ($insideKind === 1) {
                return ['inside' => true, 'outside' => true];
            }
            return ['inside' => false, 'outside' => false];
        }

        if ($insideKind === null) {
            return ['inside' => false, 'outside' => false];
        }
        if ($insideKind === 1) {
            return ['inside' => true, 'outside' => false];
        }

        return ['inside' => false, 'outside' => false];
    }

    /**
     * `classifyCharacter`: 1 = whitespace, 2 = punctuation, null = letter.
     *
     * A missing character (in the reference `charCodeAt` past the end of the
     * string, i.e. `NaN`) counts as a letter, not as whitespace.
     */
    private static function classify(?int $code): ?int
    {
        if ($code === null) {
            return null;
        }
        $char = self::fromCodePoint($code);
        if ($code === 10 || $code === 13 || $code === 32 || $code === 9 || preg_match('/\s/u', $char) === 1) {
            return 1;
        }
        if (preg_match('/\p{P}|\p{S}/u', $char) === 1) {
            return 2;
        }

        return null;
    }

    private static function encodeCharacterReference(?int $code): string
    {
        return '&#x' . strtoupper(dechex((int) $code)) . ';';
    }

    /** First character (a whole UTF-8 code point) or empty. */
    private static function firstChar(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $length = self::charLength(ord($value[0]));

        return substr($value, 0, $length);
    }

    /** Last character (a whole UTF-8 code point) or empty. */
    private static function lastChar(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $index = strlen($value) - 1;
        while ($index > 0 && (ord($value[$index]) & 0xC0) === 0x80) {
            $index--;
        }

        return substr($value, $index);
    }

    private static function charLength(int $byte): int
    {
        if ($byte < 0x80) {
            return 1;
        }
        if ($byte < 0xE0) {
            return 2;
        }
        if ($byte < 0xF0) {
            return 3;
        }

        return 4;
    }

    private static function codePointOf(string $char): ?int
    {
        if ($char === '') {
            return null;
        }

        return self::codePointAt($char, 0);
    }

    private static function codePointAt(string $value, int $offset): ?int
    {
        if ($offset >= strlen($value)) {
            return null;
        }
        $char = substr($value, $offset, self::charLength(ord($value[$offset])));
        $points = unpack('N', mb_convert_encoding($char, 'UCS-4BE', 'UTF-8'));

        return $points === false ? null : (int) $points[1];
    }

    private static function fromCodePoint(int $code): string
    {
        return mb_convert_encoding(pack('N', $code), 'UTF-8', 'UCS-4BE');
    }

    /**
     * The reference's `unsafe` list in exactly this order: core, then the mdx
     * extensions, then GFM.
     *
     * @return list<array<string,mixed>>
     */
    private static function unsafePatterns(): array
    {
        $spans = self::FULL_PHRASING_SPANS;
        $links = ['autolink', 'link', 'image', 'label'];

        return [
            ['character' => "\t", 'after' => '[\\r\\n]', 'inConstruct' => 'phrasing'],
            ['character' => "\t", 'before' => '[\\r\\n]', 'inConstruct' => 'phrasing'],
            ['character' => "\t", 'inConstruct' => ['codeFencedLangGraveAccent', 'codeFencedLangTilde']],
            ['character' => "\r", 'inConstruct' => ['codeFencedLangGraveAccent', 'codeFencedLangTilde', 'codeFencedMetaGraveAccent', 'codeFencedMetaTilde', 'destinationLiteral', 'headingAtx']],
            ['character' => "\n", 'inConstruct' => ['codeFencedLangGraveAccent', 'codeFencedLangTilde', 'codeFencedMetaGraveAccent', 'codeFencedMetaTilde', 'destinationLiteral', 'headingAtx']],
            ['character' => ' ', 'after' => '[\\r\\n]', 'inConstruct' => 'phrasing'],
            ['character' => ' ', 'before' => '[\\r\\n]', 'inConstruct' => 'phrasing'],
            ['character' => ' ', 'inConstruct' => ['codeFencedLangGraveAccent', 'codeFencedLangTilde']],
            ['character' => '!', 'after' => '\\[', 'inConstruct' => 'phrasing', 'notInConstruct' => $spans],
            ['character' => '"', 'inConstruct' => 'titleQuote'],
            ['character' => '#', 'atBreak' => true],
            ['character' => '#', 'inConstruct' => 'headingAtx', 'after' => '(?:[\r\n]|$)'],
            ['character' => '&', 'after' => '[#A-Za-z]', 'inConstruct' => 'phrasing'],
            ['character' => "'", 'inConstruct' => 'titleApostrophe'],
            ['character' => '(', 'inConstruct' => 'destinationRaw'],
            ['character' => '(', 'before' => '\\]', 'inConstruct' => 'phrasing', 'notInConstruct' => $spans],
            ['character' => ')', 'atBreak' => true, 'before' => '\\d+'],
            ['character' => ')', 'inConstruct' => 'destinationRaw'],
            ['character' => '*', 'atBreak' => true, 'after' => '(?:[ \t\r\n*])'],
            ['character' => '*', 'inConstruct' => 'phrasing', 'notInConstruct' => $spans],
            ['character' => '+', 'atBreak' => true, 'after' => '(?:[ \t\r\n])'],
            ['character' => '-', 'atBreak' => true, 'after' => '(?:[ \t\r\n-])'],
            ['character' => '.', 'atBreak' => true, 'before' => '\\d+', 'after' => '(?:[ \t\r\n]|$)'],
            ['character' => '<', 'atBreak' => true, 'after' => '[!/?A-Za-z]'],
            ['character' => '<', 'after' => '[!/?A-Za-z]', 'inConstruct' => 'phrasing', 'notInConstruct' => $spans],
            ['character' => '<', 'inConstruct' => 'destinationLiteral'],
            ['character' => '=', 'atBreak' => true],
            ['character' => '>', 'atBreak' => true],
            ['character' => '>', 'inConstruct' => 'destinationLiteral'],
            ['character' => '[', 'atBreak' => true],
            ['character' => '[', 'inConstruct' => 'phrasing', 'notInConstruct' => $spans],
            ['character' => '[', 'inConstruct' => ['label', 'reference']],
            ['character' => '\\', 'after' => '[\\r\\n]', 'inConstruct' => 'phrasing'],
            ['character' => ']', 'inConstruct' => ['label', 'reference']],
            ['character' => '_', 'atBreak' => true],
            ['character' => '_', 'inConstruct' => 'phrasing', 'notInConstruct' => $spans],
            ['character' => '`', 'atBreak' => true],
            ['character' => '`', 'inConstruct' => ['codeFencedLangGraveAccent', 'codeFencedMetaGraveAccent']],
            ['character' => '`', 'inConstruct' => 'phrasing', 'notInConstruct' => $spans],
            ['character' => '~', 'atBreak' => true],
            ['character' => '{', 'inConstruct' => ['phrasing']],
            ['character' => '{', 'atBreak' => true],
            ['character' => '<', 'inConstruct' => ['phrasing']],
            ['character' => '<', 'atBreak' => true],
            ['character' => '@', 'before' => '[+\\-.\\w]', 'after' => '[\\-.\\w]', 'inConstruct' => 'phrasing', 'notInConstruct' => $links],
            ['character' => '.', 'before' => '[Ww]', 'after' => '[\\-.\\w]', 'inConstruct' => 'phrasing', 'notInConstruct' => $links],
            ['character' => ':', 'before' => '[ps]', 'after' => '\\/', 'inConstruct' => 'phrasing', 'notInConstruct' => $links],
            ['character' => '[', 'inConstruct' => ['label', 'phrasing', 'reference']],
            ['character' => '~', 'inConstruct' => 'phrasing', 'notInConstruct' => $spans],
            ['character' => "\r", 'inConstruct' => 'tableCell'],
            ['character' => "\n", 'inConstruct' => 'tableCell'],
            ['character' => '|', 'atBreak' => true, 'after' => '[\t :-]'],
            ['character' => '|', 'inConstruct' => 'tableCell'],
            ['character' => ':', 'atBreak' => true, 'after' => '-'],
            ['character' => '-', 'atBreak' => true, 'after' => '[:|-]'],
            ['character' => '-', 'atBreak' => true, 'after' => '[:|-]'],
        ];
    }
}
