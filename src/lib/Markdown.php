<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../Exceptions.php';

require_once __DIR__ . '/Icons.php';

/**
 * Strict Markdown parser for the Pholio content grammar.
 *
 * The parser supports exactly the constructs the grammar defines and throws a
 * MarkdownException with file, line and message for everything else. The result
 * is an AST of plain PHP arrays with explicit "type" keys.
 *
 * Behaviour follows remark/remark-gfm in MDX mode:
 * - There are no indented code blocks (MDX turns them off); indentation has no meaning.
 * - Emphasis follows the CommonMark delimiter algorithm including the rule of three.
 * - Table cells are trimmed; the header row sets the number of columns.
 *
 * AST nodes (blocks)
 * - heading            {level:2..4, inlines}
 * - paragraph          {inlines}
 * - thematic_break     {}
 * - blockquote         {blocks}
 * - list               {ordered, start, tight, items:[{blocks, checked?:bool}]}
 *                      checked exists only on GFM task items ("- [x] …"), otherwise the key is absent.
 * - table              {align:[left|right|center|null], head:[inlines], rows:[[inlines]]}
 * - code_block         {lang:?string, meta:{raw:string, title?:string, lineNumbers?:true|int,
 *                       noCopy?:true, tab?:string, tabGroup?:string}, value:string}
 *                      Fenced with ``` or ~~~. Info string as in micromark code-fenced (lang = first word,
 *                      rest = meta, escapes and entities decoded). meta as in the reference:
 *                      remark-code-tab takes out tab/tab-group, then rehype-code parseMetaString
 *                      (title, noCopy, lineNumbers); raw is the rest ("__raw"), e.g. " {1,3-4}".
 * - code_tabs          {defaultValue:string, groupId:?string, items:[{value:string, blocks:[code_block]}]}
 *                      Consecutive code blocks with tab="…" as in the reference remark-code-tab
 *                      (a single one too). Inside <Tabs> they become <Tab value> children instead.
 * - footnote_definition {label, identifier, blocks}  (GFM, identifier as in normalizeIdentifier)
 * - component          {name, attrs, blocks, inline?:true}  components with content; inline is set only when
 *                      the content and the closing tag are on the line of the opening tag
 *                      (<Tab value="a">Text</Tab>). MDX reads that as phrasing without <p>, multi-line
 *                      content as paragraphs; the renderer then unwraps the single paragraph.
 * - component_void     {name, attrs}          self-closing components
 * - component_raw      {name, attrs, value}   components with literal content (<DynamicCodeBlock>)
 *
 * AST nodes (inlines)
 * - text {value}, code {value}, strong {inlines}, emphasis {inlines}, delete {inlines} (GFM ~~ and ~),
 *   link {href, title?, inlines}, image {src, alt, title?}, break {} (two spaces or \ at the end of a line),
 *   footnote_reference {label, identifier}
 *
 * Component attributes are always strings in double quotes or boolean attributes without a value.
 * In the AST: string, bool (bare = true, "true"/"false"), int (digits), list<string>
 * (items="A|B", \| escaped), icon (lucide name from Icons::names()). MDX expressions ({…}) and
 * import/export lines fail with a hint to the Pholio notation.
 *
 * Frontmatter keys: title, heading, description, keywords, updated, full, icon, noindex. Frontmatter aliases
 * (`content.frontmatter_aliases`, e.g. ["date" => "updated"]) map other key names onto them.
 */

/** Error in the content grammar: file, line and message. */
final class MarkdownException extends ContentException
{
    public function __construct(string $sourceFile, int $sourceLine, string $reason)
    {
        parent::__construct($reason, $sourceFile !== '' ? $sourceFile : '(unnamed)', $sourceLine);
    }
}

/** Result of a parse run: frontmatter, block list and source file. */
final class Document
{
    /**
     * @param array<string,string> $frontmatter
     * @param list<array<string,mixed>> $blocks
     */
    public function __construct(
        public readonly array $frontmatter,
        public readonly array $blocks,
        public readonly string $file = ''
    ) {
    }

    /**
     * All headings in document order as [{level, text}].
     *
     * As rehype-toc on the hast: when there are footnotes, mdast-util-to-hast (footer.js)
     * appends <h2 id="footnote-label">Footnotes</h2> at the end of the page; headings
     * from the footnote texts follow after it.
     *
     * @return list<array{level:int,text:string}>
     */
    public function headings(): array
    {
        $out = [];
        $definitions = [];
        self::collectHeadings($this->blocks, $out, $definitions);

        if ($definitions !== []) {
            $out[] = ['level' => 2, 'text' => 'Footnotes'];
            foreach ($definitions as $definition) {
                $nested = [];
                /** @var list<array<string,mixed>> $inner */
                $inner = $definition['blocks'];
                self::collectHeadings($inner, $out, $nested);
            }
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @param list<array{level:int,text:string}> $out
     * @param list<array<string,mixed>> $definitions
     */
    private static function collectHeadings(array $blocks, array &$out, array &$definitions): void
    {
        foreach ($blocks as $block) {
            $type = (string) $block['type'];

            if ($type === 'heading') {
                /** @var list<array<string,mixed>> $inlines */
                $inlines = $block['inlines'];
                $out[] = ['level' => (int) $block['level'], 'text' => Markdown::plainText($inlines)];
                continue;
            }

            if ($type === 'footnote_definition') {
                $definitions[] = $block;
                continue;
            }

            if ($type === 'list') {
                /** @var list<array{blocks:list<array<string,mixed>>}> $items */
                $items = $block['items'];
                foreach ($items as $item) {
                    self::collectHeadings($item['blocks'], $out, $definitions);
                }
                continue;
            }

            if ($type === 'blockquote' || $type === 'component') {
                /** @var list<array<string,mixed>> $inner */
                $inner = $block['blocks'];
                self::collectHeadings($inner, $out, $definitions);
            }
        }
    }
}

final class Markdown
{
    /** Allowed frontmatter keys. */
    private const FRONTMATTER_KEYS = ['title', 'heading', 'description', 'keywords', 'updated', 'full', 'icon', 'noindex'];

    /** Allowed values of <Callout type="…">, including the reference design's aliases. */
    private const CALLOUT_TYPES = ['info', 'warning', 'error', 'success', 'idea', 'warn', 'tip'];

    /**
     * Component catalogue: name => [kind, attributes].
     * Kind: block (Markdown content, may be self-closing), void (self-closing only),
     * raw (literal content). Attribute => [type, required?, allowed values for enum].
     */
    private const COMPONENTS = [
        'Callout' => ['block', [
            'title' => ['string', false],
            'type' => ['enum', false, self::CALLOUT_TYPES],
            'icon' => ['icon', false],
        ]],
        'Cards' => ['block', []],
        'Card' => ['void', [
            'title' => ['string', true],
            'description' => ['string', false],
            'href' => ['string', false],
            'icon' => ['icon', false],
        ]],
        'Screenshot' => ['void', [
            'src' => ['string', true],
            'dark' => ['string', false],
            'alt' => ['string', false],
        ]],
        'Tabs' => ['block', [
            'items' => ['list', false],
            'groupId' => ['string', false],
            'persist' => ['bool', false],
            'updateAnchor' => ['bool', false],
            'defaultIndex' => ['int', false],
            'label' => ['string', false],
        ]],
        'Tab' => ['block', [
            'value' => ['string', false],
        ]],
        'Accordions' => ['block', [
            'type' => ['enum', false, ['single', 'multiple']],
            'defaultValue' => ['list', false],
        ]],
        'Accordion' => ['block', [
            'title' => ['string', true],
            'id' => ['string', false],
            'value' => ['string', false],
        ]],
        'Steps' => ['block', []],
        'Step' => ['block', []],
        'Files' => ['block', []],
        'Folder' => ['block', [
            'name' => ['string', true],
            'defaultOpen' => ['bool', false],
            'disabled' => ['bool', false],
        ]],
        'File' => ['void', [
            'name' => ['string', true],
            'icon' => ['icon', false],
        ]],
        'TypeTable' => ['block', []],
        'TypeProp' => ['block', [
            'name' => ['string', true],
            'type' => ['string', true],
            'default' => ['string', false],
            'typeDescription' => ['string', false],
            'typeDescriptionLink' => ['string', false],
            'required' => ['bool', false],
            'deprecated' => ['bool', false],
        ]],
        'Banner' => ['block', [
            'id' => ['string', false],
            'variant' => ['enum', false, ['normal', 'rainbow']],
            'height' => ['string', false],
            'changeLayout' => ['bool', false],
        ]],
        'InlineTOC' => ['void', [
            'label' => ['string', false],
        ]],
        'ImageZoom' => ['void', [
            'src' => ['string', true],
            'alt' => ['string', false],
            'width' => ['int', false],
            'height' => ['int', false],
        ]],
        'DynamicCodeBlock' => ['raw', [
            'lang' => ['string', true],
        ]],
    ];

    /** Container components: allowed children. */
    private const CHILDREN = [
        'Cards' => ['Card'],
        'Tabs' => ['Tab'],
        'Accordions' => ['Accordion'],
        'Steps' => ['Step'],
        'Files' => ['Folder', 'File'],
        'Folder' => ['Folder', 'File'],
        'TypeTable' => ['TypeProp'],
    ];

    /** Child components: allowed parents. */
    private const PARENTS = [
        'Tab' => ['Tabs'],
        'Accordion' => ['Accordions'],
        'Step' => ['Steps'],
        'Folder' => ['Files', 'Folder'],
        'File' => ['Files', 'Folder'],
        'TypeProp' => ['TypeTable'],
    ];

    /** Pholio notation per attribute type, for the hints on MDX expressions. */
    private const EXPRESSION_HINTS = [
        'list' => '%s="A|B|C" (separator |, a | inside a value as \\|)',
        'int' => '%s="1"',
        'bool' => '%s without a value for true or %s="false"',
        'icon' => '%s="book-open" (lucide name)',
        'enum' => '%s="…"',
        'string' => '%s="…"',
    ];

    private const ASCII_PUNCTUATION = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    /** Start of a GFM footnote definition "[^label]:" (micromark-extension-gfm-footnote). */
    private const FOOTNOTE_DEFINITION = '/^\[\^((?:[^\[\]\\\\\s]|\\\\\S)+)\]:/';

    /** Character reference as in micromark character-reference (named up to 31, decimal 7, hex 6). */
    private const CHARACTER_REFERENCE = '&(?:#[0-9]{1,7}|#[xX][0-9A-Fa-f]{1,6}|[A-Za-z0-9]{1,31});';

    /** @var array<string,int> footnote identifier => line of the definition (document-wide) */
    private static array $footnotes = [];

    /** @var array<string,string> footnote identifier => label as written (for messages) */
    private static array $footnoteLabels = [];

    /** @var array<string,true> used footnote identifiers */
    private static array $footnotesUsed = [];

    /** Depth of nested link/image texts (GFM does not link literals there). */
    private static int $linkDepth = 0;

    /**
     * Parse a source. $file is used for error messages only. $frontmatterAliases maps
     * alternative frontmatter keys onto the allowed ones (e.g. ["date" => "updated"]).
     *
     * @param array<string,string> $frontmatterAliases
     */
    public static function parse(string $source, string $file = '', array $frontmatterAliases = []): Document
    {
        foreach ($frontmatterAliases as $alias => $target) {
            if (!in_array($target, self::FRONTMATTER_KEYS, true) || in_array($alias, self::FRONTMATTER_KEYS, true)) {
                throw new ConfigException(
                    'content.frontmatter_aliases: "' . $alias . '" => "' . $target . '" must map a key that is not allowed itself onto one of: '
                    . implode(', ', self::FRONTMATTER_KEYS)
                );
            }
        }

        $source = str_replace(["\r\n", "\r"], "\n", $source);
        if (str_starts_with($source, "\u{FEFF}")) {
            $source = substr($source, 3);
        }

        $raw = explode("\n", $source);
        $lines = [];
        foreach ($raw as $index => $text) {
            $lines[] = ['text' => self::expandTabs($text), 'no' => $index + 1, 'raw' => $text, 'col' => 0];
        }

        $frontmatter = self::parseFrontmatter($lines, $file, $frontmatterAliases);

        self::$footnoteLabels = [];
        self::$footnotes = self::scanFootnoteDefinitions($lines, $file);
        self::$footnotesUsed = [];
        self::$linkDepth = 0;

        try {
            $blocks = self::parseBlocks($lines, $file);

            foreach (self::$footnotes as $identifier => $no) {
                if (!isset(self::$footnotesUsed[$identifier])) {
                    throw new MarkdownException(
                        $file,
                        $no,
                        'Footnote [^' . (self::$footnoteLabels[$identifier] ?? $identifier) . '] is never referenced; remark would drop it silently.'
                    );
                }
            }
        } finally {
            self::$footnotes = [];
            self::$footnoteLabels = [];
            self::$footnotesUsed = [];
            self::$linkDepth = 0;
        }

        return new Document($frontmatter, $blocks, $file);
    }

    /** Plain text of an inline list (for headings and the search index). */
    public static function plainText(array $inlines): string
    {
        $out = '';
        foreach ($inlines as $node) {
            switch ((string) $node['type']) {
                case 'text':
                case 'code':
                    $out .= (string) $node['value'];
                    break;
                case 'break':
                    $out .= ' ';
                    break;
                case 'image':
                    $out .= (string) $node['alt'];
                    break;
                case 'strong':
                case 'emphasis':
                case 'delete':
                case 'link':
                    /** @var list<array<string,mixed>> $children */
                    $children = $node['inlines'];
                    $out .= self::plainText($children);
                    break;
            }
        }

        return $out;
    }

    private static function expandTabs(string $text): string
    {
        if (!str_contains($text, "\t")) {
            return $text;
        }

        $out = '';
        $column = 0;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($char === "\t") {
                $width = 4 - ($column % 4);
                $out .= str_repeat(' ', $width);
                $column += $width;
                continue;
            }
            $out .= $char;
            $column++;
        }

        return $out;
    }

    /**
     * Line from a column on: text has tabs expanded, raw keeps tabs (for code content).
     * A partially cut tab turns into spaces, as in micromark.
     *
     * @param array{text:string,no:int,raw:string,col:int} $line
     * @return array{text:string,no:int,raw:string,col:int}
     */
    private static function cut(array $line, int $columns): array
    {
        if ($columns <= 0) {
            return $line;
        }

        $raw = $line['raw'];
        $col = $line['col'];
        $removed = 0;
        $i = 0;
        $length = strlen($raw);

        while ($removed < $columns && $i < $length) {
            $width = $raw[$i] === "\t" ? 4 - ($col % 4) : 1;
            if ($removed + $width > $columns) {
                $rest = $removed + $width - $columns;
                $newCol = $col + ($columns - $removed);

                return [
                    'text' => substr($line['text'], $columns),
                    'no' => $line['no'],
                    'raw' => str_repeat(' ', $rest) . substr($raw, $i + 1),
                    'col' => $newCol,
                ];
            }
            $removed += $width;
            $col += $width;
            $i++;
        }

        return ['text' => substr($line['text'], $columns), 'no' => $line['no'], 'raw' => substr($raw, $i), 'col' => $col];
    }

    /** @return array{text:string,no:int,raw:string,col:int} */
    private static function plainLine(string $text, int $no): array
    {
        return ['text' => $text, 'no' => $no, 'raw' => $text, 'col' => 0];
    }

    private static function indentOf(string $text): int
    {
        return strlen($text) - strlen(ltrim($text));
    }

    // ---------------------------------------------------------------- Frontmatter

    /**
     * @param list<array{text:string,no:int,raw:string,col:int}> $lines
     * @param array<string,string> $aliases
     * @return array<string,string>
     */
    private static function parseFrontmatter(array &$lines, string $file, array $aliases = []): array
    {
        if ($lines === [] || rtrim($lines[0]['text']) !== '---') {
            return [];
        }

        $end = -1;
        $count = count($lines);
        for ($i = 1; $i < $count; $i++) {
            if (rtrim($lines[$i]['text']) === '---') {
                $end = $i;
                break;
            }
        }

        if ($end < 0) {
            throw new MarkdownException($file, 1, 'Frontmatter is never closed with "---".');
        }

        $data = [];
        for ($i = 1; $i < $end; $i++) {
            $text = rtrim($lines[$i]['text']);
            $no = $lines[$i]['no'];
            if (trim($text) === '') {
                continue;
            }

            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*):[ \t]*(.*)$/', $text, $m) !== 1) {
                throw new MarkdownException($file, $no, 'Frontmatter line is not "key: value": ' . trim($text));
            }

            $key = $aliases[$m[1]] ?? $m[1];
            if (!in_array($key, self::FRONTMATTER_KEYS, true)) {
                throw new MarkdownException(
                    $file,
                    $no,
                    'Unknown frontmatter key "' . $key . '"; allowed: ' . implode(', ', self::FRONTMATTER_KEYS) . '.'
                );
            }
            if (array_key_exists($key, $data)) {
                throw new MarkdownException($file, $no, 'Frontmatter key "' . $key . '" appears twice.');
            }

            if ($key === 'keywords') {
                [$data[$key], $i] = self::keywordsValue($lines, $i, $end, $m[2], $file, $no);
                continue;
            }
            $data[$key] = self::frontmatterValue($m[2], $file, $no);
            if ($key === 'noindex' && !in_array($data[$key], ['true', 'false'], true)) {
                throw new MarkdownException($file, $no, 'noindex: expected true or false, got "' . $data[$key] . '".');
            }
        }

        if (!isset($data['title'])) {
            throw new MarkdownException($file, 1, 'Frontmatter without "title".');
        }

        array_splice($lines, 0, $end + 1);

        return $data;
    }

    /**
     * `keywords` as a scalar ("a, b"), a flow list ([a, "b"]) or a block list ("- a" lines
     * below the key). Lists are joined to "a, b", so all three index the same.
     *
     * @param list<array{text:string,no:int,raw:string,col:int}> $lines
     * @return array{0:string,1:int} the value and the index of its last line
     */
    private static function keywordsValue(array $lines, int $i, int $end, string $value, string $file, int $no): array
    {
        $value = trim($value);
        if ($value === '') {
            $items = [];
            while ($i + 1 < $end && preg_match('/^[ \t]*-(?:[ \t]+(.*))?$/', rtrim($lines[$i + 1]['text']), $item) === 1) {
                $i++;
                $items[] = self::keywordItem($item[1] ?? '', $file, $lines[$i]['no']);
            }

            return [implode(', ', $items), $i];
        }
        if ($value[0] !== '[') {
            return [self::frontmatterValue($value, $file, $no), $i];
        }
        if (!str_ends_with($value, ']')) {
            throw new MarkdownException($file, $no, 'keywords: a list is written as [a, b] on one line or as "- a" lines below the key.');
        }

        $inner = trim(substr($value, 1, -1));
        $items = [];
        if ($inner !== '') {
            // Split at commas outside quotes.
            $current = '';
            $quote = null;
            for ($k = 0, $n = strlen($inner); $k < $n; $k++) {
                $char = $inner[$k];
                if ($quote !== null) {
                    $current .= $char;
                    if ($char === '\\' && $k + 1 < $n) {
                        $current .= $inner[++$k];
                    } elseif ($char === $quote) {
                        $quote = null;
                    }
                } elseif ($char === ',') {
                    $items[] = self::keywordItem($current, $file, $no);
                    $current = '';
                } else {
                    if ($char === '"' || $char === "'") {
                        $quote = $char;
                    }
                    $current .= $char;
                }
            }
            if ($quote !== null) {
                throw new MarkdownException($file, $no, 'keywords: a quoted list item is never closed with ' . $quote . '.');
            }
            $items[] = self::keywordItem($current, $file, $no);
        }

        return [implode(', ', $items), $i];
    }

    /** One keywords list item: a plain or quoted string, not empty, not a nested list or object. */
    private static function keywordItem(string $raw, string $file, int $no): string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw[0] === '[' || $raw[0] === '{') {
            throw new MarkdownException(
                $file,
                $no,
                'keywords: list items must be non-empty strings, as in keywords: [download, "dark mode"] or keywords: "download, dark mode".'
            );
        }
        $item = trim(self::frontmatterValue($raw, $file, $no));
        if ($item === '') {
            throw new MarkdownException($file, $no, 'keywords: list items must be non-empty strings.');
        }

        return $item;
    }

    private static function frontmatterValue(string $value, string $file, int $no): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $first = $value[0];
        if ($first === '"' || $first === "'") {
            if (strlen($value) < 2 || $value[strlen($value) - 1] !== $first) {
                throw new MarkdownException($file, $no, 'Frontmatter value is not cleanly enclosed in ' . $first . '.');
            }
            $inner = substr($value, 1, -1);

            return str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $inner);
        }

        if ($first === '[' || $first === '{' || $first === '|' || $first === '>' || $first === '&' || $first === '*') {
            throw new MarkdownException($file, $no, 'Only simple frontmatter values are allowed, no YAML construct "' . $first . '".');
        }

        return $value;
    }

    // ---------------------------------------------------------------- Footnote pre-scan

    /**
     * micromark knows every definition before inline text is resolved, so they are
     * collected up front (outside code blocks, also after ">" and in indentation).
     *
     * @param list<array{text:string,no:int,raw:string,col:int}> $lines
     * @return array<string,int>
     */
    private static function scanFootnoteDefinitions(array $lines, string $file): array
    {
        $found = [];
        $fence = null;
        foreach ($lines as $line) {
            $text = $line['text'];
            if ($fence !== null) {
                $fence = self::fenceStep($fence, ltrim(preg_replace('/^(\s*>)+/', '', $text) ?? $text));
                continue;
            }
            $stripped = ltrim(preg_replace('/^(\s*>\s?)+/', '', $text) ?? $text);
            $stripped = ltrim(preg_replace('/^([-*+]|\d{1,9}[.)])\s+/', '', $stripped) ?? $stripped);
            if (self::fenceOpen($stripped) !== null) {
                $fence = self::fenceOpen($stripped);
                continue;
            }
            if (preg_match(self::FOOTNOTE_DEFINITION, $stripped, $m) === 1) {
                if (strlen($m[1]) > 999) {
                    throw new MarkdownException($file, $line['no'], 'Footnote label is longer than 999 characters.');
                }
                $identifier = self::normalizeIdentifier($m[1]);
                if (isset($found[$identifier])) {
                    throw new MarkdownException(
                        $file,
                        $line['no'],
                        'Footnote [^' . $m[1] . '] is defined twice (first definition on line ' . $found[$identifier] . ').'
                    );
                }
                $found[$identifier] = $line['no'];
                self::$footnoteLabels[$identifier] = $m[1];
            }
        }

        return $found;
    }

    /** micromark-util-normalize-identifier. */
    private static function normalizeIdentifier(string $value): string
    {
        $value = preg_replace('/[\t\n\r ]+/', ' ', $value) ?? $value;
        $value = trim($value, ' ');

        return mb_strtoupper(mb_strtolower($value, 'UTF-8'), 'UTF-8');
    }

    // ---------------------------------------------------------------- Blocks

    /**
     * @param list<array{text:string,no:int,raw:string,col:int}> $lines
     * @return list<array<string,mixed>>
     */
    private static function parseBlocks(array $lines, string $file, ?string $parent = null): array
    {
        $blocks = [];
        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            $text = $lines[$i]['text'];
            $no = $lines[$i]['no'];

            if (trim($text) === '') {
                $i++;
                continue;
            }

            $trimmed = ltrim($text);

            // Pholio has no ESM
            if (preg_match('/^(import|export)\s/', $trimmed, $m) === 1) {
                throw new MarkdownException(
                    $file,
                    $no,
                    '"' . $m[1] . '" lines are not allowed: Pholio has no MDX imports, every component is registered. Remove the line.'
                );
            }

            // Heading
            if (preg_match('/^(#{1,6})(\s+|$)/', $trimmed, $m) === 1) {
                $level = strlen($m[1]);
                if ($level < 2 || $level > 4) {
                    throw new MarkdownException(
                        $file,
                        $no,
                        'Heading level ' . $level . ' is not allowed; only ## to #### (the title comes from the frontmatter).'
                    );
                }
                $content = trim(substr($trimmed, $level));
                $content = preg_replace('/(?<!\\\\)\s+#+\s*$/', '', $content) ?? $content;
                $blocks[] = [
                    'type' => 'heading',
                    'level' => $level,
                    'inlines' => self::parseInlines(trim($content), $file, $no),
                ];
                $i++;
                continue;
            }

            // Thematic break
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})\s*$/', $trimmed) === 1) {
                $blocks[] = ['type' => 'thematic_break'];
                $i++;
                continue;
            }

            // Code block
            if (self::fenceOpen($text) !== null) {
                $blocks[] = self::parseFence($lines, $i, $file);
                continue;
            }

            // Component tag
            if (preg_match('/^<\/?[A-Za-z]/', $trimmed) === 1) {
                $blocks[] = self::parseComponent($lines, $i, $file, $parent);
                continue;
            }

            // Blockquote
            if (str_starts_with($trimmed, '>')) {
                $blocks[] = self::parseBlockquote($lines, $i, $file);
                continue;
            }

            // Footnote definition
            if (preg_match(self::FOOTNOTE_DEFINITION, $trimmed) === 1) {
                $blocks[] = self::parseFootnoteDefinition($lines, $i, $file);
                continue;
            }

            // List
            if (self::listMarker($trimmed) !== null) {
                $blocks[] = self::parseList($lines, $i, $file);
                continue;
            }

            // Table
            if (str_contains($trimmed, '|') && $i + 1 < $count && self::isDelimiterRow($lines[$i + 1]['text'])) {
                $blocks[] = self::parseTable($lines, $i, $file);
                continue;
            }

            $blocks[] = self::parseParagraph($lines, $i, $file);
        }

        return self::groupCodeTabs($blocks, $parent);
    }

    /** @return array{ordered:bool,start:int,width:int,delim:string}|null */
    private static function listMarker(string $trimmed): ?array
    {
        if (preg_match('/^([-*+])(\s+)\S/', $trimmed, $m) === 1) {
            return ['ordered' => false, 'start' => 1, 'width' => 1 + strlen($m[2]), 'delim' => $m[1]];
        }
        if (preg_match('/^(\d{1,9})([.)])(\s+)\S/', $trimmed, $m) === 1) {
            return [
                'ordered' => true,
                'start' => (int) $m[1],
                'width' => strlen($m[1]) + 1 + strlen($m[3]),
                'delim' => $m[2],
            ];
        }

        return null;
    }

    /** Does the line start a block that interrupts a paragraph? */
    private static function interruptsParagraph(string $text): bool
    {
        $trimmed = ltrim($text);
        if ($trimmed === '') {
            return true;
        }
        if (preg_match('/^#{1,6}(\s|$)/', $trimmed) === 1) {
            return true;
        }
        if (preg_match('/^(-{3,}|\*{3,}|_{3,})\s*$/', $trimmed) === 1) {
            return true;
        }
        if (self::fenceOpen($trimmed) !== null) {
            return true;
        }
        if (preg_match('/^<\/?[A-Za-z]/', $trimmed) === 1) {
            return true;
        }
        if (str_starts_with($trimmed, '>')) {
            return true;
        }
        if (preg_match(self::FOOTNOTE_DEFINITION, $trimmed) === 1) {
            return true;
        }
        $marker = self::listMarker($trimmed);
        if ($marker !== null && (!$marker['ordered'] || $marker['start'] === 1)) {
            return true;
        }

        return false;
    }

    /**
     * @param list<array{text:string,no:int,raw:string,col:int}> $lines
     * @return array<string,mixed>
     */
    private static function parseParagraph(array $lines, int &$i, string $file): array
    {
        $count = count($lines);
        $no = $lines[$i]['no'];
        $parts = [];

        while ($i < $count) {
            $text = $lines[$i]['text'];
            if (trim($text) === '') {
                break;
            }
            if ($parts !== [] && self::interruptsParagraph($text)) {
                break;
            }
            if ($parts !== [] && str_contains($text, '|') && $i + 1 < $count && self::isDelimiterRow($lines[$i + 1]['text'])) {
                break;
            }
            if ($parts !== []) {
                $setext = rtrim(ltrim($text));
                if (preg_match('/^(=+|-+)$/', $setext) === 1) {
                    throw new MarkdownException($file, $lines[$i]['no'], 'Setext headings are not allowed; use ## instead.');
                }
            }

            // Hard line break: two spaces or a backslash at the end of the line
            $hard = preg_match('/ {2,}$/', $text) === 1 || preg_match('/(?<!\\\\)\\\\$/', rtrim($text)) === 1;
            $hasFollower = $i + 1 < $count
                && trim($lines[$i + 1]['text']) !== ''
                && !self::interruptsParagraph($lines[$i + 1]['text']);

            $parts[] = ['text' => trim($text), 'hard' => $hard && $hasFollower];
            $i++;
        }

        $buffer = '';
        foreach ($parts as $index => $part) {
            $content = $part['text'];
            if ($part['hard']) {
                $content = preg_replace('/( {2,}|\\\\)$/', '', $content) ?? $content;
            }
            $buffer .= rtrim($content);
            if ($index < count($parts) - 1) {
                $buffer .= $part['hard'] ? "\u{0000}\n" : "\n";
            }
        }

        return ['type' => 'paragraph', 'inlines' => self::parseInlines($buffer, $file, $no)];
    }

    /**
     * @param list<array{text:string,no:int,raw:string,col:int}> $lines
     * @return array<string,mixed>
     */
    private static function parseBlockquote(array $lines, int &$i, string $file): array
    {
        $count = count($lines);
        $inner = [];
        $fence = null;

        while ($i < $count) {
            $text = $lines[$i]['text'];
            $trimmed = ltrim($text);
            if (str_starts_with($trimmed, '>')) {
                $columns = self::indentOf($text) + 1;
                if (str_starts_with(substr($trimmed, 1), ' ')) {
                    $columns++;
                }
                $line = self::cut($lines[$i], $columns);
                $inner[] = $line;
                $fence = self::fenceStep($fence, $line['text']);
                $i++;
                continue;
            }
            if ($fence !== null || trim($text) === '' || self::interruptsParagraph($text)) {
                break;
            }
            // Lazy continuation
            $inner[] = self::cut($lines[$i], self::indentOf($text));
            $i++;
        }

        return ['type' => 'blockquote', 'blocks' => self::parseBlocks($inner, $file)];
    }

    /**
     * @param list<array{text:string,no:int,raw:string,col:int}> $lines
     * @return array<string,mixed>
     */
    private static function parseList(array $lines, int &$i, string $file): array
    {
        $count = count($lines);
        $first = self::listMarker(ltrim($lines[$i]['text']));
        if ($first === null) {
            throw new MarkdownException($file, $lines[$i]['no'], 'Internal error: list start expected.');
        }

        $ordered = $first['ordered'];
        $start = $first['start'];
        $items = [];
        $tight = true;
        $sawBlankInsideItem = false;

        while ($i < $count) {
            $text = $lines[$i]['text'];
            $indent = self::indentOf($text);
            $trimmed = ltrim($text);
            $marker = self::listMarker($trimmed);

            if ($marker === null || $marker['ordered'] !== $ordered) {
                break;
            }

            $contentIndent = $indent + $marker['width'];
            $firstLine = self::cut($lines[$i], $contentIndent);

            // GFM task item: "[ ]", "[x]" or "[X]" at the start of the first paragraph,
            // followed by whitespace and content (micromark-extension-gfm-task-list-item). mdast then
            // removes exactly one character (the space) from the text.
            $checked = null;
            if (preg_match('/^\[([ xX])\] +\S/', $firstLine['text'], $tm) === 1) {
                $checked = $tm[1] !== ' ';
                $firstLine = self::cut($firstLine, 4);
            }

            $itemLines = [$firstLine];
            $fence = self::fenceStep(null, $firstLine['text']);
            $i++;
            $pendingBlanks = 0;

            while ($i < $count) {
                $next = $lines[$i]['text'];
                if (trim($next) === '') {
                    if ($fence !== null) {
                        $itemLines[] = self::cut($lines[$i], $contentIndent);
                        $i++;
                        continue;
                    }
                    $pendingBlanks++;
                    $i++;
                    continue;
                }
                $nextIndent = self::indentOf($next);
                $nextTrimmed = ltrim($next);

                if ($nextIndent >= $contentIndent) {
                    if ($pendingBlanks > 0) {
                        for ($b = 0; $b < $pendingBlanks; $b++) {
                            $itemLines[] = self::plainLine('', $lines[$i]['no']);
                        }
                        $sawBlankInsideItem = true;
                        $pendingBlanks = 0;
                    }
                    $line = self::cut($lines[$i], $contentIndent);
                    $itemLines[] = $line;
                    $fence = self::fenceStep($fence, $line['text']);
                    $i++;
                    continue;
                }

                if ($fence === null && $pendingBlanks === 0 && self::listMarker($nextTrimmed) === null && !self::interruptsParagraph($next)) {
                    // Lazy continuation of the paragraph in the list item
                    $itemLines[] = self::cut($lines[$i], $nextIndent);
                    $i++;
                    continue;
                }

                break;
            }

            $item = ['blocks' => self::parseBlocks($itemLines, $file)];
            if ($checked !== null) {
                $item['checked'] = $checked;
            }
            $items[] = $item;

            if ($pendingBlanks > 0) {
                if ($i < $count) {
                    $followTrimmed = ltrim($lines[$i]['text']);
                    $followMarker = self::listMarker($followTrimmed);
                    if ($followMarker !== null && $followMarker['ordered'] === $ordered) {
                        $tight = false;
                        continue;
                    }
                }
                break;
            }
        }

        if ($sawBlankInsideItem) {
            $tight = false;
        }

        return [
            'type' => 'list',
            'ordered' => $ordered,
            'start' => $ordered ? $start : 1,
            'tight' => $tight,
            'items' => $items,
        ];
    }

    /**
     * GFM footnote definition: continuation lines are blank lines, lines indented by four columns
     * and lazy paragraph continuations (tokenizeDefinitionContinuation).
     *
     * @param list<array{text:string,no:int,raw:string,col:int}> $lines
     * @return array<string,mixed>
     */
    private static function parseFootnoteDefinition(array $lines, int &$i, string $file): array
    {
        $count = count($lines);
        $text = $lines[$i]['text'];
        $trimmed = ltrim($text);
        preg_match(self::FOOTNOTE_DEFINITION, $trimmed, $m);
        $label = $m[1];

        $firstLine = self::cut($lines[$i], self::indentOf($text) + strlen($m[0]));
        $firstLine = self::cut($firstLine, self::indentOf($firstLine['text']));
        $inner = [$firstLine];
        $fence = self::fenceStep(null, $firstLine['text']);
        $lastBlank = trim($firstLine['text']) === '';
        $i++;
        $pending = 0;

        while ($i < $count) {
            $next = $lines[$i]['text'];
            if (trim($next) === '') {
                if ($fence !== null) {
                    $inner[] = self::cut($lines[$i], 4);
                } else {
                    $pending++;
                }
                $i++;
                continue;
            }
            $nextIndent = self::indentOf($next);
            if ($nextIndent >= 4) {
                for ($b = 0; $b < $pending; $b++) {
                    $inner[] = self::plainLine('', $lines[$i]['no']);
                }
                $pending = 0;
                $line = self::cut($lines[$i], 4);
                $inner[] = $line;
                $fence = self::fenceStep($fence, $line['text']);
                $lastBlank = false;
                $i++;
                continue;
            }
            if ($fence === null && $pending === 0 && !$lastBlank && !self::interruptsParagraph($next)) {
                $inner[] = self::cut($lines[$i], $nextIndent);
                $i++;
                continue;
            }
            break;
        }

        return [
            'type' => 'footnote_definition',
            'label' => $label,
            'identifier' => self::normalizeIdentifier($label),
            'blocks' => self::parseBlocks($inner, $file),
        ];
    }

    private static function isDelimiterRow(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '' || !str_contains($trimmed, '-')) {
            return false;
        }
        $cells = self::splitRow($trimmed);
        if ($cells === []) {
            return false;
        }
        foreach ($cells as $cell) {
            if (preg_match('/^:?-+:?$/', trim($cell)) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function splitRow(string $text): array
    {
        $text = trim($text);
        if (str_starts_with($text, '|')) {
            $text = substr($text, 1);
        }
        if (preg_match('/(?<!\\\\)\|$/', $text) === 1) {
            $text = substr($text, 0, -1);
        }

        $cells = [];
        $buffer = '';
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($char === '\\' && $i + 1 < $length && $text[$i + 1] === '|') {
                $buffer .= '\\|';
                $i++;
                continue;
            }
            if ($char === '|') {
                $cells[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        $cells[] = $buffer;

        return $cells;
    }

    /**
     * @param list<array{text:string,no:int,raw:string,col:int}> $lines
     * @return array<string,mixed>
     */
    private static function parseTable(array $lines, int &$i, string $file): array
    {
        $count = count($lines);
        $headerNo = $lines[$i]['no'];
        $header = self::splitRow($lines[$i]['text']);
        $delims = self::splitRow($lines[$i + 1]['text']);

        if (count($header) !== count($delims)) {
            throw new MarkdownException(
                $file,
                $headerNo,
                'Table header has ' . count($header) . ' columns, the delimiter row ' . count($delims) . '.'
            );
        }

        $align = [];
        foreach ($delims as $delim) {
            $delim = trim($delim);
            $left = str_starts_with($delim, ':');
            $right = str_ends_with($delim, ':');
            $align[] = $left && $right ? 'center' : ($left ? 'left' : ($right ? 'right' : null));
        }

        $columns = count($header);
        $head = [];
        foreach ($header as $cell) {
            $head[] = self::parseInlines(trim($cell), $file, $headerNo);
        }

        $i += 2;
        $rows = [];
        while ($i < $count) {
            $text = $lines[$i]['text'];
            if (trim($text) === '' || !str_contains($text, '|')) {
                break;
            }
            $trimmed = ltrim($text);
            if (preg_match('/^#{1,6}\s/', $trimmed) === 1 || preg_match('/^<\/?[A-Za-z]/', $trimmed) === 1) {
                break;
            }

            $cells = self::splitRow($text);
            $row = [];
            for ($c = 0; $c < $columns; $c++) {
                $row[] = self::parseInlines(trim($cells[$c] ?? ''), $file, $lines[$i]['no']);
            }
            $rows[] = $row;
            $i++;
        }

        return ['type' => 'table', 'align' => $align, 'head' => $head, 'rows' => $rows];
    }

    // ---------------------------------------------------------------- Code blocks

    /**
     * Opening fence as in micromark code-fenced: at least three ` or ~, any indentation
     * (MDX turns off indented code); with ` the info string must not contain a `.
     *
     * @return array{indent:int,marker:string,size:int,info:string}|null
     */
    private static function fenceOpen(string $text): ?array
    {
        if (preg_match('/^( *)(`{3,}|~{3,})(.*)$/', $text, $m) !== 1) {
            return null;
        }
        if ($m[2][0] === '`' && str_contains($m[3], '`')) {
            return null;
        }

        return ['indent' => strlen($m[1]), 'marker' => $m[2][0], 'size' => strlen($m[2]), 'info' => $m[3]];
    }

    /** Does this line close the fence? Same character, at least as long, only whitespace after it. */
    private static function fenceCloses(array $fence, string $text): bool
    {
        if (preg_match('/^\s*(`+|~+)\s*$/', $text, $m) !== 1) {
            return false;
        }

        return $m[1][0] === $fence['marker'] && strlen($m[1]) >= $fence['size'];
    }

    /**
     * Advance the fence state over one line (for collectors that only pass lines on).
     *
     * @param array{indent:int,marker:string,size:int,info:string}|null $fence
     * @return array{indent:int,marker:string,size:int,info:string}|null
     */
    private static function fenceStep(?array $fence, string $text): ?array
    {
        if ($fence === null) {
            return self::fenceOpen($text);
        }

        return self::fenceCloses($fence, $text) ? null : $fence;
    }

    /**
     * @param list<array{text:string,no:int,raw:string,col:int}> $lines
     * @return array<string,mixed>
     */
    private static function parseFence(array $lines, int &$i, string $file): array
    {
        $no = $lines[$i]['no'];
        $fence = self::fenceOpen($lines[$i]['text']);
        if ($fence === null) {
            throw new MarkdownException($file, $no, 'Internal error: code fence expected.');
        }

        $count = count($lines);
        $i++;
        $content = [];
        $closed = false;

        while ($i < $count) {
            $text = $lines[$i]['text'];
            if (self::fenceCloses($fence, $text)) {
                $closed = true;
                $i++;
                break;
            }
            $strip = min($fence['indent'], strlen($text) - strlen(ltrim($text, ' ')));
            $content[] = self::cut($lines[$i], $strip)['raw'];
            $i++;
        }

        if (!$closed) {
            throw new MarkdownException(
                $file,
                $no,
                'Code block is never closed with ' . str_repeat($fence['marker'], $fence['size']) . ' (in the same container).'
            );
        }

        // Info string: lang up to the first space, meta from the next non-space character
        $info = ltrim($fence['info'], ' ');
        $lang = null;
        $meta = null;
        if ($info !== '') {
            $space = strpos($info, ' ');
            if ($space === false) {
                $lang = $info;
            } else {
                $lang = substr($info, 0, $space);
                $rest = ltrim(substr($info, $space), ' ');
                $meta = $rest === '' ? null : $rest;
            }
        }

        return [
            'type' => 'code_block',
            'lang' => $lang === null ? null : self::decodeString($lang),
            'meta' => self::codeMeta($meta === null ? null : self::decodeString($meta)),
            'value' => implode("\n", $content),
        ];
    }

    /**
     * Meta string as in the reference build: remark-code-tab (tab, tab-group), then rehype-code parseMetaString
     * (title, tab, noCopy, lineNumbers; the rest as __raw).
     *
     * @return array<string,mixed>
     */
    private static function codeMeta(?string $meta): array
    {
        $out = [];
        $meta ??= '';

        if ($meta !== '') {
            $tab = self::codeAttributes($meta, ['tab', 'tab-group']);
            if (is_string($tab['attributes']['tab'] ?? null)) {
                $meta = $tab['rest'];
                $out['tab'] = $tab['attributes']['tab'];
                if (is_string($tab['attributes']['tab-group'] ?? null)) {
                    $out['tabGroup'] = $tab['attributes']['tab-group'];
                }
            }
        }

        $parsed = self::codeAttributes($meta, ['title', 'tab', 'noCopy', 'lineNumbers']);
        foreach ($parsed['attributes'] as $key => $value) {
            if ($key === 'noCopy') {
                $out['noCopy'] = true;
                continue;
            }
            if ($key === 'lineNumbers') {
                $out['lineNumbers'] = is_int($value) ? $value : true;
                continue;
            }
            if ($key === 'title' && $value !== null) {
                $out['title'] = (string) $value;
            }
        }

        return ['raw' => $parsed['rest']] + $out;
    }

    /**
     * Reference core codeblock-utils parseCodeBlockAttributes: AttributeRegex with replaceAll.
     *
     * @param list<string> $allowed
     * @return array{rest:string,attributes:array<string,string|int|null>}
     */
    private static function codeAttributes(string $meta, array $allowed): array
    {
        $attributes = [];
        $rest = preg_replace_callback(
            '/(?<=^|[\s\x{00A0}\x{FEFF}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}])([a-zA-Z0-9_-]+)(?:=(?:"([^"]*)"|\'([^\']*)\'|(\d+)))?/u',
            static function (array $m) use ($allowed, &$attributes): string {
                if (!in_array($m[1], $allowed, true)) {
                    return $m[0];
                }
                if ($m[4] !== null) {
                    $attributes[$m[1]] = (int) $m[4];
                } else {
                    $attributes[$m[1]] = $m[2] ?? $m[3];
                }

                return '';
            },
            $meta,
            -1,
            $replaced,
            PREG_UNMATCHED_AS_NULL
        );

        return ['rest' => $rest ?? $meta, 'attributes' => $attributes];
    }

    /**
     * Reference remark-code-tab: consecutive code blocks with tab="…" are grouped.
     *
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>>
     */
    private static function groupCodeTabs(array $blocks, ?string $parent): array
    {
        $out = [];
        $run = [];

        $close = static function () use (&$out, &$run, $parent): void {
            if ($run === []) {
                return;
            }

            /** @var list<array{value:string,blocks:list<array<string,mixed>>}> $groups */
            $groups = [];
            foreach ($run as $code) {
                $value = (string) $code['meta']['tab'];
                $found = false;
                foreach ($groups as $index => $group) {
                    if ($group['value'] === $value) {
                        $groups[$index]['blocks'][] = $code;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $groups[] = ['value' => $value, 'blocks' => [$code]];
                }
            }

            if ($parent === 'Tabs') {
                foreach ($groups as $group) {
                    $out[] = ['type' => 'component', 'name' => 'Tab', 'attrs' => ['value' => $group['value']], 'blocks' => $group['blocks']];
                }
            } else {
                $out[] = [
                    'type' => 'code_tabs',
                    'defaultValue' => $groups[0]['value'],
                    'groupId' => $groups[0]['blocks'][0]['meta']['tabGroup'] ?? null,
                    'items' => $groups,
                ];
            }
            $run = [];
        };

        foreach ($blocks as $block) {
            if ($block['type'] === 'code_block' && isset($block['meta']['tab'])) {
                $run[] = $block;
                continue;
            }
            $close();
            $out[] = $block;
        }
        $close();

        return $out;
    }

    // ---------------------------------------------------------------- Components

    /**
     * @param list<array{text:string,no:int,raw:string,col:int}> $lines
     * @return array<string,mixed>
     */
    private static function parseComponent(array $lines, int &$i, string $file, ?string $parent): array
    {
        $no = $lines[$i]['no'];
        $trimmed = ltrim($lines[$i]['text']);

        if (preg_match('/^<\/([A-Za-z][A-Za-z0-9]*)\s*>/', $trimmed, $m) === 1) {
            throw new MarkdownException($file, $no, 'Closing tag </' . $m[1] . '> without a matching opening tag.');
        }

        if (preg_match('/^<([A-Za-z][A-Za-z0-9]*)/', $trimmed, $m) !== 1) {
            throw new MarkdownException($file, $no, 'Unreadable tag: ' . trim($trimmed));
        }

        $name = $m[1];
        if (!isset(self::COMPONENTS[$name])) {
            throw new MarkdownException(
                $file,
                $no,
                'Unknown component <' . $name . '>; allowed: ' . implode(', ', array_keys(self::COMPONENTS)) . '.'
            );
        }
        $kind = self::COMPONENTS[$name][0];

        if (isset(self::PARENTS[$name]) && !in_array($parent, self::PARENTS[$name], true)) {
            throw new MarkdownException(
                $file,
                $no,
                '<' . $name . '> may only appear directly inside <' . implode('> or <', self::PARENTS[$name]) . '>.'
            );
        }

        // Detect an MDX expression on the first line, before a ">" inside {…} seems to close the tag.
        self::rejectExpression($trimmed, $name, $file, $no);

        // Collect the complete tag (attributes may span several lines).
        $tag = $trimmed;
        $tagEnd = $i;
        while (!self::tagIsComplete($tag)) {
            $tagEnd++;
            if ($tagEnd >= count($lines)) {
                throw new MarkdownException($file, $no, 'Tag <' . $name . '> is never closed.');
            }
            $tag .= "\n" . ltrim($lines[$tagEnd]['text']);
            self::rejectExpression($tag, $name, $file, $no);
        }

        $selfClosing = (bool) preg_match('/\/>\s*$/', self::tagHead($tag));
        $head = self::tagHead($tag);
        $attrs = self::parseAttributes($head, $name, $file, $no);

        if ($kind === 'void') {
            if (!$selfClosing) {
                throw new MarkdownException($file, $no, '<' . $name . '> must be self-closing: <' . $name . ' … />.');
            }
            $rest = trim(substr($tag, strlen($head)));
            if ($rest !== '') {
                throw new MarkdownException($file, $no, 'Text follows <' . $name . ' … /> on the same line: ' . $rest);
            }
            $i = $tagEnd + 1;

            return ['type' => 'component_void', 'name' => $name, 'attrs' => $attrs];
        }

        if ($kind === 'raw') {
            if ($selfClosing) {
                throw new MarkdownException(
                    $file,
                    $no,
                    '<' . $name . '> needs the code as content: <' . $name . ' lang="ts"> … </' . $name . '>.'
                );
            }

            return self::parseRawComponent($lines, $i, $tagEnd, $tag, $head, $name, $attrs, $file);
        }

        if ($selfClosing) {
            $i = $tagEnd + 1;
            $node = ['type' => 'component', 'name' => $name, 'attrs' => $attrs, 'blocks' => []];
            self::validateComponent($node, $file, $no);

            return $node;
        }

        // Collect the content up to the matching closing tag.
        $inner = [];
        $depth = 1;
        $rest = substr($tag, strlen($head));
        $cursor = $tagEnd + 1;
        $closing = '</' . $name . '>';

        $closeOnSameLine = false;
        if (trim($rest) !== '') {
            $pos = strpos($rest, $closing);
            if ($pos !== false) {
                $inner[] = self::plainLine(trim(substr($rest, 0, $pos)), $no);
                $closeOnSameLine = true;
                $depth = 0;
            } else {
                $inner[] = self::plainLine(trim($rest), $no);
            }
        }

        if (!$closeOnSameLine) {
            $count = count($lines);
            $fence = null;
            while ($cursor < $count) {
                $lineText = $lines[$cursor]['text'];
                $lineTrimmed = trim($lineText);

                if ($fence === null) {
                    if ($lineTrimmed === $closing) {
                        $depth--;
                        if ($depth === 0) {
                            $cursor++;
                            break;
                        }
                    }
                    if (preg_match('/^<' . $name . '(\s|>)/', $lineTrimmed) === 1
                        && preg_match('/\/>\s*$/', $lineTrimmed) !== 1
                        && !str_contains($lineTrimmed, $closing)) {
                        $depth++;
                    }
                }
                $fence = self::fenceStep($fence, $lineText);

                $inner[] = $lines[$cursor];
                $cursor++;
            }

            if ($depth !== 0) {
                throw new MarkdownException($file, $no, '<' . $name . '> is never closed with </' . $name . '>.');
            }
        }

        $blocks = self::parseBlocks($inner, $file, $name);
        $i = $closeOnSameLine ? $tagEnd + 1 : $cursor;

        $node = ['type' => 'component', 'name' => $name, 'attrs' => $attrs, 'blocks' => $blocks];
        if ($closeOnSameLine) {
            $node['inline'] = true;
        }
        self::validateComponent($node, $file, $no);

        return $node;
    }

    /**
     * Literal content (DynamicCodeBlock): lines between the tag and </Name>, indentation removed up to
     * the indentation of the opening tag, as for a code fence.
     *
     * @param list<array{text:string,no:int,raw:string,col:int}> $lines
     * @param array<string,mixed> $attrs
     * @return array<string,mixed>
     */
    private static function parseRawComponent(
        array $lines,
        int &$i,
        int $tagEnd,
        string $tag,
        string $head,
        string $name,
        array $attrs,
        string $file
    ): array {
        $no = $lines[$i]['no'];
        $indent = self::indentOf($lines[$i]['text']);
        $closing = '</' . $name . '>';
        $rest = substr($tag, strlen($head));

        if (trim($rest) !== '') {
            $pos = strpos($rest, $closing);
            if ($pos === false || trim(substr($rest, $pos + strlen($closing))) !== '') {
                throw new MarkdownException(
                    $file,
                    $no,
                    'The code of <' . $name . '> starts on the line after the tag, or the tag and </' . $name . '> are on one line.'
                );
            }
            $i = $tagEnd + 1;

            return ['type' => 'component_raw', 'name' => $name, 'attrs' => $attrs, 'value' => substr($rest, 0, $pos)];
        }

        $count = count($lines);
        $cursor = $tagEnd + 1;
        $content = [];
        $closed = false;
        while ($cursor < $count) {
            $text = $lines[$cursor]['text'];
            if (trim($text) === $closing) {
                $closed = true;
                $cursor++;
                break;
            }
            $strip = min($indent, strlen($text) - strlen(ltrim($text, ' ')));
            $content[] = self::cut($lines[$cursor], $strip)['raw'];
            $cursor++;
        }

        if (!$closed) {
            throw new MarkdownException($file, $no, '<' . $name . '> is never closed with </' . $name . '>.');
        }

        $i = $cursor;

        return ['type' => 'component_raw', 'name' => $name, 'attrs' => $attrs, 'value' => implode("\n", $content)];
    }

    /**
     * Check the children and cross references of a container component.
     *
     * @param array<string,mixed> $node
     */
    private static function validateComponent(array $node, string $file, int $no): void
    {
        $name = (string) $node['name'];
        /** @var list<array<string,mixed>> $blocks */
        $blocks = $node['blocks'];
        /** @var array<string,mixed> $attrs */
        $attrs = $node['attrs'];

        if (isset(self::CHILDREN[$name])) {
            foreach ($blocks as $block) {
                $ok = in_array($block['type'], ['component', 'component_void'], true)
                    && in_array($block['name'], self::CHILDREN[$name], true);
                if (!$ok) {
                    if ($name === 'Cards') {
                        throw new MarkdownException($file, $no, '<Cards> may only contain <Card … />.');
                    }
                    throw new MarkdownException(
                        $file,
                        $no,
                        '<' . $name . '> may only contain <' . implode('>, <', self::CHILDREN[$name]) . '>.'
                    );
                }
            }
        }

        if ($name === 'Tabs') {
            /** @var list<string>|null $items */
            $items = $attrs['items'] ?? null;
            foreach ($blocks as $index => $tab) {
                $value = $tab['attrs']['value'] ?? null;
                if ($items !== null && $value !== null && !in_array($value, $items, true)) {
                    throw new MarkdownException(
                        $file,
                        $no,
                        '<Tab value="' . $value . '"> is not in items="' . implode('|', $items) . '" of <Tabs>.'
                    );
                }
                if ($value === null && ($items === null || !isset($items[$index]))) {
                    throw new MarkdownException(
                        $file,
                        $no,
                        '<Tab> number ' . ($index + 1) . ' has no value and <Tabs> has no matching items entry.'
                    );
                }
            }
            if (isset($attrs['defaultIndex'])) {
                $total = $items !== null ? count($items) : count($blocks);
                if ((int) $attrs['defaultIndex'] >= $total) {
                    throw new MarkdownException(
                        $file,
                        $no,
                        'defaultIndex="' . $attrs['defaultIndex'] . '" on <Tabs> is outside the ' . $total . ' tabs.'
                    );
                }
            }
        }

        if ($name === 'Accordions' && ($attrs['type'] ?? 'single') === 'single' && count($attrs['defaultValue'] ?? []) > 1) {
            throw new MarkdownException($file, $no, '<Accordions type="single"> allows only one defaultValue.');
        }

        if ($name === 'TypeTable') {
            $seen = [];
            foreach ($blocks as $prop) {
                $propName = (string) $prop['attrs']['name'];
                if (isset($seen[$propName])) {
                    throw new MarkdownException($file, $no, '<TypeProp name="' . $propName . '"> appears twice in <TypeTable>.');
                }
                $seen[$propName] = true;
            }
        }
    }

    /** The tag head up to and including "/>" or ">". */
    private static function tagHead(string $tag): string
    {
        $length = strlen($tag);
        $inQuote = '';
        for ($i = 0; $i < $length; $i++) {
            $char = $tag[$i];
            if ($inQuote !== '') {
                if ($char === $inQuote) {
                    $inQuote = '';
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $inQuote = $char;
                continue;
            }
            if ($char === '>') {
                return substr($tag, 0, $i + 1);
            }
        }

        return $tag;
    }

    private static function tagIsComplete(string $tag): bool
    {
        $head = self::tagHead($tag);

        return str_ends_with(rtrim($head), '>');
    }

    /** Reject an MDX expression as an attribute value ({…}) with a hint to the Pholio notation. */
    private static function rejectExpression(string $tag, string $name, string $file, int $no): void
    {
        $scan = preg_replace('/"[^"]*"/', '""', $tag) ?? $tag;
        if (preg_match('/\s([A-Za-z][A-Za-z0-9-]*)\s*=\s*\{/', $scan, $m) !== 1) {
            if (preg_match('/^<[A-Za-z][A-Za-z0-9]*[^>]*\s\{/', $scan) === 1) {
                throw new MarkdownException(
                    $file,
                    $no,
                    'MDX expression {…} in <' . $name . '> is not allowed; attributes are name="value" or boolean without a value.'
                );
            }

            return;
        }

        $attribute = $m[1];
        $spec = self::COMPONENTS[$name][1][$attribute] ?? null;

        if ($name === 'TypeTable' && $attribute === 'type') {
            $hint = 'Pholio writes the properties as children: <TypeTable> <TypeProp name="…" type="…" default="…" required>Description</TypeProp> </TypeTable>.';
        } elseif ($name === 'InlineTOC' && $attribute === 'items') {
            $hint = 'Pholio writes <InlineTOC />; the entries come from the headings of the page.';
        } elseif ($name === 'DynamicCodeBlock' && $attribute === 'code') {
            $hint = 'Pholio writes the code as content: <DynamicCodeBlock lang="ts"> … </DynamicCodeBlock>.';
        } elseif ($spec !== null) {
            $hint = 'Pholio writes ' . str_replace('%s', $attribute, self::EXPRESSION_HINTS[$spec[0]]) . '.';
        } else {
            $hint = 'Attribute values are always strings in double quotes or boolean without a value.';
        }

        throw new MarkdownException(
            $file,
            $no,
            'MDX expression ' . $attribute . '={…} on <' . $name . '> is not allowed (Pholio has no expressions). ' . $hint
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function parseAttributes(string $head, string $name, string $file, int $no): array
    {
        $allowed = self::COMPONENTS[$name][1];

        $body = preg_replace('/^<[A-Za-z][A-Za-z0-9]*/', '', $head) ?? '';
        $body = preg_replace('/\/?>\s*$/', '', $body) ?? '';
        $body = trim($body);

        $attrs = [];
        $offset = 0;
        $length = strlen($body);

        while ($offset < $length) {
            $tail = substr($body, $offset);
            if (preg_match('/^\s+/', $tail, $m) === 1) {
                $offset += strlen($m[0]);
                continue;
            }

            $bare = false;
            $rawValue = '';
            if (preg_match('/^([A-Za-z][A-Za-z0-9-]*)\s*=\s*"([^"]*)"/', $tail, $m) === 1) {
                $key = $m[1];
                $rawValue = $m[2];
            } elseif (preg_match('/^([A-Za-z][A-Za-z0-9-]*)(?=\s|$)/', $tail, $m) === 1) {
                $key = $m[1];
                $bare = true;
            } else {
                throw new MarkdownException(
                    $file,
                    $no,
                    'Attribute in <' . $name . '> is neither name="value" (double quotes) nor a boolean attribute: ' . trim($tail)
                );
            }

            if (!array_key_exists($key, $allowed)) {
                throw new MarkdownException(
                    $file,
                    $no,
                    'Unknown attribute "' . $key . '" on <' . $name . '>; allowed: '
                    . ($allowed === [] ? '(none)' : implode(', ', array_keys($allowed))) . '.'
                );
            }
            if (array_key_exists($key, $attrs)) {
                throw new MarkdownException($file, $no, 'Attribute "' . $key . '" on <' . $name . '> appears twice.');
            }

            $attrs[$key] = self::attributeValue($allowed[$key], $key, $rawValue, $bare, $name, $file, $no);
            $offset += strlen($m[0]);
        }

        foreach ($allowed as $key => $spec) {
            if ($spec[1] && !isset($attrs[$key])) {
                throw new MarkdownException($file, $no, '<' . $name . '> needs the attribute "' . $key . '".');
            }
        }

        return $attrs;
    }

    /**
     * Check and convert an attribute value by type.
     *
     * @param array{0:string,1:bool,2?:list<string>} $spec
     * @return string|bool|int|list<string>
     */
    private static function attributeValue(array $spec, string $key, string $raw, bool $bare, string $name, string $file, int $no): string|bool|int|array
    {
        $type = $spec[0];

        if ($bare) {
            if ($type !== 'bool') {
                throw new MarkdownException(
                    $file,
                    $no,
                    'Attribute "' . $key . '" on <' . $name . '> needs a value in double quotes: ' . $key . '="…".'
                );
            }

            return true;
        }

        switch ($type) {
            case 'bool':
                if ($raw !== 'true' && $raw !== 'false') {
                    throw new MarkdownException(
                        $file,
                        $no,
                        'Boolean attribute "' . $key . '" on <' . $name . '> is written without a value, "true" or "false", not "' . $raw . '".'
                    );
                }

                return $raw === 'true';

            case 'int':
                if (preg_match('/^[0-9]{1,9}$/', $raw) !== 1) {
                    throw new MarkdownException(
                        $file,
                        $no,
                        'Attribute "' . $key . '" on <' . $name . '> expects a non-negative integer, got "' . $raw . '".'
                    );
                }

                return (int) $raw;

            case 'list':
                return self::listValue($raw, $key, $name, $file, $no);

            case 'icon':
                $value = self::decodeEntities($raw);
                $known = Icons::names();
                if (!in_array($value, $known, true)) {
                    $suggestions = self::closestNames($value, $known, 5);
                    throw new MarkdownException(
                        $file,
                        $no,
                        'Unknown lucide icon "' . $value . '" in ' . $key . ' on <' . $name . '>'
                        . ($suggestions === [] ? '' : '; did you mean: ' . implode(', ', $suggestions))
                        . '. Names: https://lucide.dev/icons.'
                    );
                }

                return $value;

            case 'enum':
                $value = self::decodeEntities($raw);
                $values = $spec[2] ?? [];
                if (!in_array($value, $values, true)) {
                    if ($name === 'Callout' && $key === 'type') {
                        throw new MarkdownException(
                            $file,
                            $no,
                            'Unknown callout type "' . $value . '"; allowed: ' . implode(', ', $values) . '.'
                        );
                    }
                    throw new MarkdownException(
                        $file,
                        $no,
                        'Unknown value "' . $value . '" for ' . $key . ' on <' . $name . '>; allowed: ' . implode(', ', $values) . '.'
                    );
                }

                return $value;
        }

        return self::decodeEntities($raw);
    }

    /**
     * The at most $limit closest known names by Levenshtein distance, alphabetical on a tie.
     * Independent of the size of the name list.
     *
     * @param list<string> $known
     * @return list<string>
     */
    private static function closestNames(string $value, array $known, int $limit): array
    {
        $needle = strtolower($value);
        $scored = [];
        foreach ($known as $candidate) {
            $scored[] = [levenshtein($needle, $candidate), $candidate];
        }
        usort(
            $scored,
            static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: strcmp($a[1], $b[1])
        );

        return array_map(static fn (array $entry): string => $entry[1], array_slice($scored, 0, $limit));
    }

    /**
     * List value "A|B|C": separator |, "\|" stands for |, "\\" for \.
     *
     * @return list<string>
     */
    private static function listValue(string $raw, string $key, string $name, string $file, int $no): array
    {
        $items = [];
        $buffer = '';
        $length = strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];
            if ($char === '\\' && $i + 1 < $length && ($raw[$i + 1] === '|' || $raw[$i + 1] === '\\')) {
                $buffer .= $raw[$i + 1];
                $i++;
                continue;
            }
            if ($char === '|') {
                $items[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        $items[] = $buffer;

        foreach ($items as $index => $item) {
            if (trim($item) === '') {
                throw new MarkdownException(
                    $file,
                    $no,
                    'Attribute "' . $key . '" on <' . $name . '> has an empty entry (number ' . ($index + 1) . '); format: '
                    . $key . '="A|B|C", a | inside a value as \\|.'
                );
            }
            $items[$index] = self::decodeEntities($item);
        }

        return $items;
    }

    // ---------------------------------------------------------------- Inlines

    /**
     * @return list<array<string,mixed>>
     */
    public static function parseInlines(string $text, string $file, int $line): array
    {
        if ($text === '') {
            return [];
        }

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            throw new MarkdownException($file, $line, 'Text is not valid UTF-8.');
        }

        $nodes = self::tokenize($chars, $file, $line);
        $nodes = self::processEmphasis($nodes, 0);

        return self::mergeText(self::stripDelimiters($nodes));
    }

    /**
     * @param list<string> $chars
     * @return list<array<string,mixed>>
     */
    private static function tokenize(array $chars, string $file, int $line): array
    {
        $nodes = [];
        $count = count($chars);
        $i = 0;
        $buffer = '';

        $flush = static function () use (&$buffer, &$nodes): void {
            if ($buffer !== '') {
                $nodes[] = ['type' => 'text', 'value' => $buffer];
                $buffer = '';
            }
        };

        while ($i < $count) {
            $char = $chars[$i];

            // Hard break (marker from the paragraph parser) or soft break
            if ($char === "\u{0000}") {
                $flush();
                $nodes[] = ['type' => 'break'];
                $i++;
                if ($i < $count && $chars[$i] === "\n") {
                    $i++;
                }
                continue;
            }

            // Backslash escape
            if ($char === '\\' && $i + 1 < $count) {
                $next = $chars[$i + 1];
                if (strlen($next) === 1 && str_contains(self::ASCII_PUNCTUATION, $next)) {
                    $flush();
                    $nodes[] = ['type' => 'text', 'value' => $next, 'literal' => true, 'escaped' => true];
                    $i += 2;
                    continue;
                }
            }

            // Inline code
            if ($char === '`') {
                $run = 0;
                while ($i + $run < $count && $chars[$i + $run] === '`') {
                    $run++;
                }
                $close = self::findBacktickRun($chars, $i + $run, $run);
                if ($close === null) {
                    $buffer .= str_repeat('`', $run);
                    $i += $run;
                    continue;
                }
                $flush();
                $content = implode('', array_slice($chars, $i + $run, $close - ($i + $run)));
                $content = str_replace("\n", ' ', $content);
                if ($content !== '' && str_starts_with($content, ' ') && str_ends_with($content, ' ') && trim($content) !== '') {
                    $content = substr($content, 1, -1);
                }
                $nodes[] = ['type' => 'code', 'value' => $content];
                $i = $close + $run;
                continue;
            }

            // Image
            if ($char === '!' && $i + 1 < $count && $chars[$i + 1] === '[') {
                $parsed = self::parseLinkLike($chars, $i, true, $file, $line);
                if ($parsed !== null) {
                    $flush();
                    $nodes[] = $parsed['node'];
                    $i = $parsed['next'];
                    continue;
                }
                $buffer .= '!';
                $i++;
                continue;
            }

            // GFM footnote reference [^label]
            if ($char === '[' && $i + 1 < $count && $chars[$i + 1] === '^') {
                $reference = self::footnoteCall($chars, $i, $file, $line);
                if ($reference !== null) {
                    $flush();
                    $nodes[] = $reference['node'];
                    $i = $reference['next'];
                    continue;
                }
            }

            // Link
            if ($char === '[') {
                $parsed = self::parseLinkLike($chars, $i, false, $file, $line);
                if ($parsed !== null) {
                    $flush();
                    $nodes[] = $parsed['node'];
                    $i = $parsed['next'];
                    continue;
                }
                throw new MarkdownException(
                    $file,
                    $line,
                    'Square bracket without a link destination; reference links are not allowed: '
                    . implode('', array_slice($chars, $i, 30))
                );
            }

            if ($char === ']') {
                throw new MarkdownException($file, $line, 'Closing square bracket without an opening one.');
            }

            // Raw HTML or autolink
            if ($char === '<') {
                $ahead = implode('', array_slice($chars, $i, 40));
                if (preg_match('/^<\/?[A-Za-z]/', $ahead) === 1 || preg_match('/^<[A-Za-z][A-Za-z0-9+.\-]*:/', $ahead) === 1 || preg_match('/^<!--/', $ahead) === 1) {
                    throw new MarkdownException(
                        $file,
                        $line,
                        'Raw HTML, autolinks and comments are not allowed in running text: ' . $ahead
                    );
                }
                $buffer .= '<';
                $i++;
                continue;
            }

            // remark-gfm links GFM autolink literals; the grammar does not allow them.
            if (self::$linkDepth === 0 && ($char === 'h' || $char === 'H' || $char === 'w' || $char === 'W')) {
                $previous = $i > 0 ? $chars[$i - 1] : "\n";
                if (self::isUnicodeWhitespace($previous) || in_array($previous, ['(', '*', '_', '~'], true)) {
                    $ahead = implode('', array_slice($chars, $i, 10));
                    if (preg_match('/^(?:https?:\/\/|www\.)[^\s<]/i', $ahead) === 1) {
                        throw new MarkdownException(
                            $file,
                            $line,
                            'Bare URL in text (GFM autolink literal) is not allowed; write it as [text](' . rtrim($ahead) . '…).'
                        );
                    }
                }
            }
            if (self::$linkDepth === 0 && $char === '@' && $i > 0 && preg_match('/^[A-Za-z0-9._+-]$/', $chars[$i - 1]) === 1) {
                $ahead = implode('', array_slice($chars, $i, 64));
                if (preg_match('/^@[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.[A-Za-z0-9]/', $ahead) === 1) {
                    throw new MarkdownException(
                        $file,
                        $line,
                        'Bare email address in text (GFM autolink literal) is not allowed; write it as [text](mailto:…).'
                    );
                }
            }

            // Strikethrough: runs of one or two tildes (micromark-extension-gfm-strikethrough, singleTilde)
            if ($char === '~') {
                $run = 0;
                while ($i + $run < $count && $chars[$i + $run] === '~') {
                    $run++;
                }
                if ($run > 2) {
                    $buffer .= str_repeat('~', $run);
                    $i += $run;
                    continue;
                }
                $before = self::characterGroup($i > 0 ? $chars[$i - 1] : null);
                $after = self::characterGroup($i + $run < $count ? $chars[$i + $run] : null);
                $flush();
                $nodes[] = [
                    'type' => 'delim',
                    'char' => '~',
                    'n' => $run,
                    'open' => $after === 0 || ($after === 2 && $before !== 0),
                    'close' => $before === 0 || ($before === 2 && $after !== 0),
                ];
                $i += $run;
                continue;
            }

            // Character reference
            if ($char === '&') {
                $ahead = implode('', array_slice($chars, $i, 34));
                if (preg_match('/^' . self::CHARACTER_REFERENCE . '/', $ahead, $m) === 1) {
                    $decoded = self::decodeReference($m[0]);
                    if ($decoded !== null) {
                        $flush();
                        $nodes[] = ['type' => 'text', 'value' => $decoded, 'literal' => true];
                        $i += mb_strlen($m[0], 'UTF-8');
                        continue;
                    }
                }
                $buffer .= '&';
                $i++;
                continue;
            }

            // Emphasis delimiter
            if ($char === '*' || $char === '_') {
                $run = 0;
                while ($i + $run < $count && $chars[$i + $run] === $char) {
                    $run++;
                }
                $before = $i > 0 ? $chars[$i - 1] : "\n";
                $after = $i + $run < $count ? $chars[$i + $run] : "\n";
                [$canOpen, $canClose] = self::flanking($char, $before, $after);
                $flush();
                $nodes[] = [
                    'type' => 'delim',
                    'char' => $char,
                    'n' => $run,
                    'open' => $canOpen,
                    'close' => $canClose,
                ];
                $i += $run;
                continue;
            }

            if ($char === "\n") {
                $buffer .= "\n";
                $i++;
                continue;
            }

            $buffer .= $char;
            $i++;
        }

        $flush();

        return $nodes;
    }

    /**
     * Footnote reference as in tokenizeGfmFootnoteCall: no whitespace, no "[", escapes \[ \\ \],
     * at most 999 characters, and the identifier must be defined.
     *
     * @param list<string> $chars
     * @return array{node:array<string,mixed>,next:int}|null
     */
    private static function footnoteCall(array $chars, int $start, string $file, int $line): ?array
    {
        $count = count($chars);
        $j = $start + 2;
        $label = '';
        while ($j < $count) {
            $char = $chars[$j];
            if ($char === ']') {
                break;
            }
            if ($char === '[' || self::isUnicodeWhitespace($char) || strlen($label) > 999) {
                return null;
            }
            if ($char === '\\' && $j + 1 < $count && in_array($chars[$j + 1], ['[', '\\', ']'], true)) {
                $label .= $char . $chars[$j + 1];
                $j += 2;
                continue;
            }
            $label .= $char;
            $j++;
        }
        if ($j >= $count || $label === '') {
            return null;
        }

        $identifier = self::normalizeIdentifier($label);
        if (!isset(self::$footnotes[$identifier])) {
            throw new MarkdownException(
                $file,
                $line,
                'Footnote reference [^' . $label . '] without a definition; add "[^' . $label . ']: text" at the end of the page.'
            );
        }
        self::$footnotesUsed[$identifier] = true;

        return [
            'node' => ['type' => 'footnote_reference', 'label' => $label, 'identifier' => $identifier],
            'next' => $j + 1,
        ];
    }

    /**
     * @param list<string> $chars
     */
    private static function findBacktickRun(array $chars, int $from, int $length): ?int
    {
        $count = count($chars);
        $i = $from;
        while ($i < $count) {
            if ($chars[$i] !== '`') {
                $i++;
                continue;
            }
            $run = 0;
            while ($i + $run < $count && $chars[$i + $run] === '`') {
                $run++;
            }
            if ($run === $length) {
                return $i;
            }
            $i += $run;
        }

        return null;
    }

    /**
     * @param list<string> $chars
     * @return array{node:array<string,mixed>,next:int}|null
     */
    private static function parseLinkLike(array $chars, int $start, bool $isImage, string $file, int $line): ?array
    {
        $count = count($chars);
        $open = $start + ($isImage ? 2 : 1);
        $depth = 1;
        $i = $open;

        while ($i < $count) {
            $char = $chars[$i];
            if ($char === '\\') {
                $i += 2;
                continue;
            }
            if ($char === '`') {
                $run = 0;
                while ($i + $run < $count && $chars[$i + $run] === '`') {
                    $run++;
                }
                $close = self::findBacktickRun($chars, $i + $run, $run);
                $i = $close === null ? $i + $run : $close + $run;
                continue;
            }
            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $i++;
        }

        if ($depth !== 0 || $i >= $count) {
            return null;
        }

        $labelEnd = $i;
        if ($labelEnd + 1 >= $count || $chars[$labelEnd + 1] !== '(') {
            return null;
        }

        $destination = self::readDestination($chars, $labelEnd + 2, $file, $line);
        if ($destination === null) {
            return null;
        }

        $label = implode('', array_slice($chars, $open, $labelEnd - $open));

        self::$linkDepth++;
        try {
            $inner = self::parseInlines($label, $file, $line);
        } finally {
            self::$linkDepth--;
        }

        if ($isImage) {
            $node = [
                'type' => 'image',
                'src' => $destination['href'],
                'alt' => self::plainText($inner),
            ];
            if ($destination['title'] !== null) {
                $node['title'] = $destination['title'];
            }

            return ['node' => $node, 'next' => $destination['next']];
        }

        foreach ($inner as $child) {
            if ($child['type'] === 'link') {
                throw new MarkdownException($file, $line, 'Links must not be nested.');
            }
        }

        $node = ['type' => 'link', 'href' => $destination['href'], 'inlines' => $inner];
        if ($destination['title'] !== null) {
            $node['title'] = $destination['title'];
        }

        return ['node' => $node, 'next' => $destination['next']];
    }

    /**
     * @param list<string> $chars
     * @return array{href:string,title:?string,next:int}|null
     */
    private static function readDestination(array $chars, int $from, string $file, int $line): ?array
    {
        $count = count($chars);
        $i = $from;
        while ($i < $count && ($chars[$i] === ' ' || $chars[$i] === "\n")) {
            $i++;
        }

        $href = '';
        if ($i < $count && $chars[$i] === '<') {
            $i++;
            while ($i < $count && $chars[$i] !== '>') {
                if ($chars[$i] === "\n") {
                    return null;
                }
                $href .= $chars[$i];
                $i++;
            }
            if ($i >= $count) {
                return null;
            }
            $i++;
        } else {
            $depth = 0;
            while ($i < $count) {
                $char = $chars[$i];
                if ($char === '\\' && $i + 1 < $count && self::isAsciiPunctuation($chars[$i + 1])) {
                    $href .= $chars[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($char === ' ' || $char === "\n") {
                    break;
                }
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                }
                $href .= $char;
                $i++;
            }
        }

        while ($i < $count && ($chars[$i] === ' ' || $chars[$i] === "\n")) {
            $i++;
        }

        $title = null;
        if ($i < $count && ($chars[$i] === '"' || $chars[$i] === "'")) {
            $quote = $chars[$i];
            $i++;
            $title = '';
            while ($i < $count && $chars[$i] !== $quote) {
                if ($chars[$i] === '\\' && $i + 1 < $count && self::isAsciiPunctuation($chars[$i + 1])) {
                    $title .= $chars[$i + 1];
                    $i += 2;
                    continue;
                }
                $title .= $chars[$i];
                $i++;
            }
            if ($i >= $count) {
                return null;
            }
            $i++;
            while ($i < $count && ($chars[$i] === ' ' || $chars[$i] === "\n")) {
                $i++;
            }
        }

        if ($i >= $count || $chars[$i] !== ')') {
            return null;
        }

        return [
            'href' => self::decodeEntities($href),
            'title' => $title === null ? null : self::decodeEntities($title),
            'next' => $i + 1,
        ];
    }

    /** @return array{0:bool,1:bool} */
    private static function flanking(string $char, string $before, string $after): array
    {
        $beforeSpace = self::isUnicodeWhitespace($before);
        $afterSpace = self::isUnicodeWhitespace($after);
        $beforePunct = self::isUnicodePunctuation($before);
        $afterPunct = self::isUnicodePunctuation($after);

        $leftFlanking = !$afterSpace && (!$afterPunct || $beforeSpace || $beforePunct);
        $rightFlanking = !$beforeSpace && (!$beforePunct || $afterSpace || $afterPunct);

        if ($char === '*') {
            return [$leftFlanking, $rightFlanking];
        }

        return [
            $leftFlanking && (!$rightFlanking || $beforePunct),
            $rightFlanking && (!$leftFlanking || $afterPunct),
        ];
    }

    /** micromark-util-classify-character: 1 whitespace/edge, 2 punctuation, 0 otherwise. */
    private static function characterGroup(?string $char): int
    {
        if ($char === null || self::isUnicodeWhitespace($char)) {
            return 1;
        }

        return self::isUnicodePunctuation($char) ? 2 : 0;
    }

    private static function isUnicodeWhitespace(string $char): bool
    {
        return preg_match('/^[\s\x{00A0}]$/u', $char) === 1;
    }

    private static function isUnicodePunctuation(string $char): bool
    {
        return preg_match('/^[\p{P}\p{S}]$/u', $char) === 1;
    }

    private static function isAsciiPunctuation(string $char): bool
    {
        return strlen($char) === 1 && str_contains(self::ASCII_PUNCTUATION, $char);
    }

    /**
     * CommonMark "process emphasis": delimiter stack with the rule of three. Tildes (GFM strikethrough)
     * run in the same stack but pair only with openers of the same length and without the rule of three.
     *
     * @param list<array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    private static function processEmphasis(array $nodes, int $stackBottom): array
    {
        $openersBottom = [];

        $closerIndex = $stackBottom;
        while (true) {
            // find the next closing delimiter
            while ($closerIndex < count($nodes)
                && !($nodes[$closerIndex]['type'] === 'delim' && $nodes[$closerIndex]['close'] && $nodes[$closerIndex]['n'] > 0)) {
                $closerIndex++;
            }
            if ($closerIndex >= count($nodes)) {
                break;
            }

            $closer = $nodes[$closerIndex];
            $char = (string) $closer['char'];
            $key = $char . '|' . ($char === '~' ? $closer['n'] : $closer['n'] % 3) . '|' . ($closer['open'] ? '1' : '0');
            $bottom = $openersBottom[$key] ?? $stackBottom - 1;

            $openerIndex = $closerIndex - 1;
            $found = false;
            while ($openerIndex > $bottom && $openerIndex >= $stackBottom) {
                $node = $nodes[$openerIndex];
                if ($node['type'] === 'delim' && $node['char'] === $char && $node['open'] && $node['n'] > 0) {
                    if ($char === '~') {
                        if ($node['n'] === $closer['n']) {
                            $found = true;
                            break;
                        }
                    } else {
                        // rule of three
                        $oddMatch = ($closer['open'] || $node['close'])
                            && ($closer['n'] + $node['n']) % 3 === 0
                            && !($closer['n'] % 3 === 0 && $node['n'] % 3 === 0);
                        if (!$oddMatch) {
                            $found = true;
                            break;
                        }
                    }
                }
                $openerIndex--;
            }

            if (!$found) {
                $openersBottom[$key] = $closerIndex - 1;
                if (!$closer['open']) {
                    // CommonMark: the delimiter leaves the stack, its text stays.
                    $nodes[$closerIndex]['close'] = false;
                }
                $closerIndex++;
                continue;
            }

            if ($char === '~') {
                $use = (int) $closer['n'];
                $wrapperType = 'delete';
            } else {
                $use = ($nodes[$openerIndex]['n'] >= 2 && $nodes[$closerIndex]['n'] >= 2) ? 2 : 1;
                $wrapperType = $use === 2 ? 'strong' : 'emphasis';
            }
            $inner = array_slice($nodes, $openerIndex + 1, $closerIndex - $openerIndex - 1);
            $inner = self::stripDelimiters($inner);

            $wrapper = [
                'type' => $wrapperType,
                'inlines' => self::mergeText($inner),
            ];

            $nodes[$openerIndex]['n'] -= $use;
            $nodes[$closerIndex]['n'] -= $use;

            $replacement = [$wrapper];
            array_splice($nodes, $openerIndex + 1, $closerIndex - $openerIndex - 1, $replacement);
            $closerIndex = $openerIndex + 2;

            if ($nodes[$openerIndex]['n'] === 0) {
                array_splice($nodes, $openerIndex, 1);
                $closerIndex--;
            }
            if ($nodes[$closerIndex]['n'] === 0) {
                array_splice($nodes, $closerIndex, 1);
            }
        }

        return $nodes;
    }

    /**
     * Delimiters that are left over become text again.
     *
     * @param list<array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    private static function stripDelimiters(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if ($node['type'] !== 'delim') {
                $out[] = $node;
                continue;
            }
            $n = (int) $node['n'];
            if ($n > 0) {
                $out[] = ['type' => 'text', 'value' => str_repeat((string) $node['char'], $n), 'literal' => true];
            }
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    private static function mergeText(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if ($node['type'] === 'text') {
                unset($node['literal'], $node['escaped']);
                $last = count($out) - 1;
                if ($last >= 0 && $out[$last]['type'] === 'text') {
                    $out[$last]['value'] .= $node['value'];
                    continue;
                }
            }
            $out[] = $node;
        }

        return array_values(array_filter($out, static fn (array $node): bool => $node['type'] !== 'text' || $node['value'] !== ''));
    }

    /** Decode character references in attribute values, link destinations and titles. */
    private static function decodeEntities(string $text): string
    {
        if (!str_contains($text, '&')) {
            return $text;
        }

        return preg_replace_callback(
            '/' . self::CHARACTER_REFERENCE . '/',
            static fn (array $m): string => self::decodeReference($m[0]) ?? $m[0],
            $text
        ) ?? $text;
    }

    /** Decode escapes and character references (micromark content type "string", e.g. info strings). */
    private static function decodeString(string $text): string
    {
        if (!str_contains($text, '&') && !str_contains($text, '\\')) {
            return $text;
        }

        return preg_replace_callback(
            '/\\\\([!-\/:-@\[-`{-~])|' . self::CHARACTER_REFERENCE . '/',
            static fn (array $m): string => isset($m[1]) && $m[1] !== '' ? $m[1] : (self::decodeReference($m[0]) ?? $m[0]),
            $text
        ) ?? $text;
    }

    /**
     * Decode one character reference "&…;"; null when it is not a known named reference.
     * Numeric values as in micromark-util-decode-numeric-character-reference.
     */
    private static function decodeReference(string $reference): ?string
    {
        if (preg_match('/^&#([xX]?)([0-9A-Fa-f]+);$/', $reference, $m) === 1) {
            $code = $m[1] !== '' ? hexdec($m[2]) : (int) $m[2];
            $code = (int) $code;
            if ($code < 9 || $code === 11 || ($code > 13 && $code < 32)
                || ($code > 126 && $code < 160)
                || ($code > 55295 && $code < 57344)
                || ($code > 64975 && $code < 65008)
                || ($code & 65535) === 65535 || ($code & 65535) === 65534
                || $code > 1114111) {
                return "\u{FFFD}";
            }

            return mb_chr($code, 'UTF-8');
        }

        $decoded = html_entity_decode($reference, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $decoded === $reference ? null : $decoded;
    }
}
