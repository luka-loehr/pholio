<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/Markdown.php';
require_once __DIR__ . '/Slug.php';
require_once __DIR__ . '/Tree.php';

/**
 * Build-time search index for `theme/js/search.js`.
 *
 * The browser gets what it needs to rank without tokenising anything on load:
 * page titles, breadcrumbs and section headings for display, and an inverted index
 * from normalised words to the places they occur. A place ("slot") is either a
 * page (title, frontmatter heading, breadcrumbs, URL) or one of its sections
 * (heading and the text below it), so a hit leads straight to its heading. The
 * body text itself is not shipped.
 *
 * JSON shape (version 2):
 *   {
 *     "v":         2,
 *     "base":      "/docs",
 *     "tokenizer": "english" | "german",
 *     "crumbs":    [ [<breadcrumb>, …], … ]              shared breadcrumb lists
 *     "pages":     [ [<url>, <title>, <heading>|null, <crumbs>|-1,
 *                     <anchor>|null, <heading>|null, …], … ]
 *     "words":     "<front-coded word list>"
 *     "postings":  [ <posting string>, … ]               one per word
 *   }
 *
 * Page entries: `heading` is the frontmatter `heading` when it differs from the
 * title (the page shows it as its h1); `crumbs` indexes "crumbs", -1 means none.
 * Then one anchor/heading pair per section. Only the first section can have a
 * null anchor: the description and the text before the first heading.
 *
 * Slots are numbered through all pages in order: a page's own slot, then one
 * slot per section. Page 0 with two sections owns slots 0–2, page 1 starts at 3.
 *
 * "words" lists the words in byte order, separated by spaces; each entry is the
 * length of the prefix shared with the previous word (one base-36 digit, at most
 * 35) followed by the rest of the word.
 *
 * A posting string lists the slots containing the word in ascending order. Each
 * entry is a varint (the slot minus the previous slot minus one, starting from
 * -1) followed by one flags character. Varint digits are ALPHABET positions:
 * 32–63 carry five bits and continue, 0–31 end the number (least significant
 * digits first). Flags of a page slot: 1 title, 2 path (breadcrumb or URL
 * segment). Flags of a section slot: 4 heading, and bits 3–5 the number of
 * text blocks in the section containing the word, capped at 7.
 *
 * Words come from `words()`: lowercase, diacritics and ligatures folded to ASCII
 * (FOLDING), combining marks U+0300–U+036F dropped, for `german` also ae/oe/ue
 * read as a/o/u, then split at everything but a–z and 0–9. Hyphen and underscore
 * chains add their joined forms ("chat-export" → chat, export, chatexport).
 * `search.js` implements the same functions; `verify/search-parity.mjs` checks that
 * both agree and rebuilds the postings from `documents()`.
 */
final class SearchIndex
{
    public const VERSION = 2;

    /** Normalisation profiles `theme/js/search.js` implements. */
    public const TOKENIZERS = ['english', 'german'];

    public const FIELD_TITLE = 1;
    public const FIELD_PATH = 2;
    public const FIELD_HEADING = 4;
    public const MAX_TEXT_COUNT = 7;

    /** Digits of the posting strings, JSON-safe. */
    public const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

    /**
     * Folding applied after lowercasing: the first character of each entry becomes
     * the rest. `search.js` carries the identical string.
     */
    public const FOLDING = 'àa áa âa ãa äa åa æae çc èe ée êe ëe ìi íi îi ïi ðd ñn òo óo ôo õo öo øo ùu úu ûu üu ýy þth ÿy ßss '
        . 'āa ăa ąa ćc ĉc ċc čc ďd đd ēe ĕe ėe ęe ěe ĝg ğg ġg ģg ĥh ħh ĩi īi ĭi įi ıi ĳij ĵj ķk ĸk ĺl ļl ľl ŀl łl '
        . 'ńn ņn ňn ŉn ŋn ōo ŏo őo œoe ŕr ŗr řr śs ŝs şs šs ţt ťt ŧt ũu ūu ŭu ůu űu ųu ŵw ŷy źz żz žz ſs';

    /** @var array<string,string>|null */
    private static ?array $foldMap = null;

    /**
     * @param callable(array{url:string,slugs:list<string>,file:string,data:array<string,mixed>}):Document $loadDocument
     * @param list<string>|null $fileOrder Order of the source files (relative to the
     *        content directory); it fixes the page ids, which break ties between
     *        equal scores. Without it, the tree's order applies.
     * @param bool $includeDrafts Pages whose file name starts with `_` (drafts,
     *        sample pages) are only indexed with `--dev`.
     * @param string $tokenizer Normalisation profile recorded in the index, one of TOKENIZERS.
     * @return array{v:int, base:string, tokenizer:string, crumbs:list<list<string>>, pages:list<list<mixed>>, words:string, postings:list<string>}
     */
    public static function build(
        Tree $tree,
        callable $loadDocument,
        string $baseUrl,
        ?array $fileOrder = null,
        bool $includeDrafts = false,
        string $tokenizer = 'english'
    ): array {
        self::assertTokenizer($tokenizer);

        $crumbLists = [];
        $crumbIds = [];
        $pages = [];
        /** @var array<string,string> $postings word => posting string */
        $postings = [];
        /** @var array<string,int> $lastSlot word => last slot written */
        $lastSlot = [];
        $slot = 0;

        foreach (self::documents($tree, $loadDocument, $baseUrl, $fileOrder, $includeDrafts) as $document) {
            $crumbId = -1;
            if ($document['crumbs'] !== null) {
                $key = json_encode($document['crumbs'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if (!isset($crumbIds[$key])) {
                    $crumbIds[$key] = count($crumbLists);
                    $crumbLists[] = $document['crumbs'];
                }
                $crumbId = $crumbIds[$key];
            }

            $entry = [$document['url'], $document['title'], $document['heading'], $crumbId];
            foreach ($document['sections'] as $section) {
                $entry[] = $section[0];
                $entry[] = $section[1];
            }
            $pages[] = $entry;

            foreach (self::slotFlags($document, $baseUrl, $tokenizer) as $offset => $flags) {
                foreach ($flags as $word => $value) {
                    $word = (string) $word;
                    $id = $slot + $offset;
                    $postings[$word] = ($postings[$word] ?? '') . self::varint($id - ($lastSlot[$word] ?? -1) - 1) . self::ALPHABET[$value];
                    $lastSlot[$word] = $id;
                }
            }
            $slot += 1 + count($document['sections']);
        }

        uksort($postings, static fn($a, $b): int => strcmp((string) $a, (string) $b));

        return [
            'v' => self::VERSION,
            'base' => $baseUrl,
            'tokenizer' => $tokenizer,
            'crumbs' => $crumbLists,
            'pages' => $pages,
            'words' => self::frontCode(array_map('strval', array_keys($postings))),
            'postings' => array_values($postings),
        ];
    }

    /**
     * The indexed pages with their plain text, in index order. `build` derives the
     * index from them; `verify/tools/build-search-index.php --texts` writes them so
     * the verify tools can rebuild the postings in JavaScript.
     *
     * @param callable(array{url:string,slugs:list<string>,file:string,data:array<string,mixed>}):Document $loadDocument
     * @param list<string>|null $fileOrder
     * @return list<array{url:string, title:string, heading:?string, crumbs:?list<string>, sections:list<list<?string>>}>
     */
    public static function documents(
        Tree $tree,
        callable $loadDocument,
        string $baseUrl,
        ?array $fileOrder = null,
        bool $includeDrafts = false
    ): array {
        $documents = [];
        foreach (self::orderPages($tree->pages(), $fileOrder) as $page) {
            if (!$includeDrafts && str_starts_with(basename((string) $page['file']), '_')) {
                continue;
            }
            $url = (string) $page['url'];
            if (!str_starts_with($url, $baseUrl)) {
                throw new \RuntimeException('Page URL does not start with the base URL: ' . $url);
            }

            $data = $page['data'];
            $title = self::clean((string) ($data['title'] ?? ''));
            $heading = isset($data['heading']) ? self::clean((string) $data['heading']) : '';
            $description = isset($data['description']) ? (string) $data['description'] : null;

            $documents[] = [
                'url' => $url,
                'title' => $title,
                'heading' => $heading !== '' && $heading !== $title ? $heading : null,
                // A page in a tree without any names has no breadcrumbs either.
                'crumbs' => self::breadcrumbs($tree, $url) ?: null,
                'sections' => self::sections($loadDocument($page), $description),
            ];
        }

        return $documents;
    }

    /**
     * Word flags per slot of one document: the page slot first, then one per section.
     *
     * @param array{url:string, title:string, heading:?string, crumbs:?list<string>, sections:list<list<?string>>} $document
     * @return list<array<string,int>>
     */
    public static function slotFlags(array $document, string $baseUrl, string $tokenizer): array
    {
        $mark = static function (array &$flags, string $text, int $flag) use ($tokenizer): void {
            foreach (self::words($text, $tokenizer) as $word) {
                $flags[$word] = ($flags[$word] ?? 0) | $flag;
            }
        };

        $page = [];
        $mark($page, $document['title'], self::FIELD_TITLE);
        if ($document['heading'] !== null) {
            $mark($page, $document['heading'], self::FIELD_TITLE);
        }
        foreach ($document['crumbs'] ?? [] as $crumb) {
            $mark($page, $crumb, self::FIELD_PATH);
        }
        foreach (explode('/', substr($document['url'], strlen($baseUrl))) as $segment) {
            $mark($page, $segment, self::FIELD_PATH);
        }

        $slots = [$page];
        foreach ($document['sections'] as $section) {
            $flags = [];
            if ($section[1] !== null) {
                $mark($flags, (string) $section[1], self::FIELD_HEADING);
            }
            $counts = [];
            foreach (array_slice($section, 2) as $text) {
                foreach (array_unique(self::words((string) $text, $tokenizer)) as $word) {
                    $counts[$word] = ($counts[$word] ?? 0) + 1;
                }
            }
            foreach ($counts as $word => $count) {
                $flags[$word] = ($flags[$word] ?? 0) | (min($count, self::MAX_TEXT_COUNT) << 3);
            }
            $slots[] = $flags;
        }

        return $slots;
    }

    private static function assertTokenizer(string $tokenizer): void
    {
        if (!in_array($tokenizer, self::TOKENIZERS, true)) {
            throw new \InvalidArgumentException(
                'Unknown search tokenizer "' . $tokenizer . '", expected one of: ' . implode(', ', self::TOKENIZERS)
            );
        }
    }

    // ------------------------------------------------------------ Normalising

    /** Lowercase, folded and, for `german`, with ae/oe/ue read as a/o/u. */
    public static function normalize(string $text, string $tokenizer): string
    {
        if (self::$foldMap === null) {
            self::$foldMap = [];
            foreach (explode(' ', self::FOLDING) as $entry) {
                $char = mb_substr($entry, 0, 1, 'UTF-8');
                self::$foldMap[$char] = substr($entry, strlen($char));
            }
        }
        $text = strtr(mb_strtolower($text, 'UTF-8'), self::$foldMap);
        $text = (string) preg_replace('/[\x{0300}-\x{036F}]+/u', '', $text);
        if ($tokenizer === 'german') {
            $text = (string) preg_replace('/([aou])e/', '$1', $text);
        }

        return $text;
    }

    /**
     * Words of a text in order, with duplicates, followed by the joined forms of
     * hyphen and underscore chains (the whole chain and, for three or more parts,
     * every adjacent pair).
     *
     * @return list<string>
     */
    public static function words(string $text, string $tokenizer): array
    {
        $normal = self::normalize($text, $tokenizer);
        $words = preg_split('/[^a-z0-9]+/', $normal, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (preg_match_all('/[a-z0-9]+(?:[-_][a-z0-9]+)+/', $normal, $chains) > 0) {
            foreach ($chains[0] as $chain) {
                $parts = preg_split('/[-_]/', $chain) ?: [];
                $words[] = implode('', $parts);
                if (count($parts) > 2) {
                    for ($i = 0; $i + 1 < count($parts); $i++) {
                        $words[] = $parts[$i] . $parts[$i + 1];
                    }
                }
            }
        }

        return $words;
    }

    // --------------------------------------------------------------- Encoding

    private static function varint(int $value): string
    {
        $out = '';
        while ($value >= 32) {
            $out .= self::ALPHABET[32 + ($value & 31)];
            $value >>= 5;
        }

        return $out . self::ALPHABET[$value];
    }

    /** @param list<string> $words sorted, unique, without spaces */
    private static function frontCode(array $words): string
    {
        $entries = [];
        $previous = '';
        foreach ($words as $word) {
            $shared = 0;
            $max = min(strlen($word), strlen($previous), 35);
            while ($shared < $max && $word[$shared] === $previous[$shared]) {
                $shared++;
            }
            $entries[] = base_convert((string) $shared, 10, 36) . substr($word, $shared);
            $previous = $word;
        }

        return implode(' ', $entries);
    }

    /**
     * The word list of an index, for tests and tools.
     *
     * @return list<string>
     */
    public static function decodeWords(string $coded): array
    {
        $words = [];
        $previous = '';
        foreach (explode(' ', $coded) as $entry) {
            if ($entry === '') {
                continue;
            }
            $previous = substr($previous, 0, (int) base_convert($entry[0], 36, 10)) . substr($entry, 1);
            $words[] = $previous;
        }

        return $words;
    }

    /**
     * A posting string as [slot => flags], for tests and tools.
     *
     * @return array<int,int>
     */
    public static function decodePostings(string $posting): array
    {
        $out = [];
        $slot = -1;
        $value = 0;
        $shift = 0;
        $expectFlags = false;
        for ($i = 0; $i < strlen($posting); $i++) {
            $digit = strpos(self::ALPHABET, $posting[$i]);
            if ($digit === false) {
                throw new \InvalidArgumentException('Invalid posting character: ' . $posting[$i]);
            }
            if ($expectFlags) {
                $out[$slot] = $digit;
                $expectFlags = false;
                continue;
            }
            if ($digit >= 32) {
                $value |= ($digit - 32) << $shift;
                $shift += 5;
                continue;
            }
            $slot += ($value | ($digit << $shift)) + 1;
            $value = 0;
            $shift = 0;
            $expectFlags = true;
        }

        return $out;
    }

    // --------------------------------------------------------------- Sections

    /**
     * Sections of a page in reading order: [anchor, heading, text, …]. The first
     * section holds the description (unless a text block repeats it) and the text
     * before the first heading; it is left out when empty. Heading anchors are the
     * slugs the table of contents uses.
     *
     * @return list<list<?string>>
     */
    public static function sections(Document $document, ?string $description = null): array
    {
        $state = ['sections' => [[null, null]], 'slugger' => new Slugger()];
        self::visitBlocks($document->blocks, $state);
        /** @var list<list<?string>> $sections */
        $sections = $state['sections'];

        $description = $description === null ? '' : self::clean($description);
        if ($description !== '') {
            $repeated = false;
            foreach ($sections as $section) {
                if (in_array($description, array_slice($section, 2), true)) {
                    $repeated = true;
                    break;
                }
            }
            if (!$repeated) {
                array_splice($sections[0], 2, 0, [$description]);
            }
        }
        if (count($sections[0]) === 2) {
            array_shift($sections);
        }

        return $sections;
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

    /**
     * @param array<string,mixed> $block
     * @param array<string,mixed> $state
     */
    private static function visitBlock(array $block, array &$state): void
    {
        switch ((string) $block['type']) {
            case 'heading':
                /** @var list<array<string,mixed>> $inlines */
                $inlines = $block['inlines'];
                $text = self::clean(Markdown::plainText($inlines));
                $state['sections'][] = [$state['slugger']->slug(Markdown::plainText($inlines)), $text === '' ? null : $text];
                return;

            case 'paragraph':
                self::addText(Markdown::plainText($block['inlines']), $state);
                return;

            case 'blockquote':
            case 'footnote_definition':
                self::visitBlocks($block['blocks'], $state);
                return;

            case 'list':
                foreach ($block['items'] as $item) {
                    self::visitBlocks($item['blocks'], $state);
                }
                return;

            case 'table':
                // One text per row, cells separated by a middle dot.
                foreach (array_merge([$block['head']], $block['rows']) as $row) {
                    $cells = [];
                    foreach ($row as $cell) {
                        $value = self::clean(Markdown::plainText($cell));
                        if ($value !== '') {
                            $cells[] = $value;
                        }
                    }
                    self::addText(implode(' · ', $cells), $state);
                }
                return;

            case 'component':
            case 'component_void':
                self::visitComponent($block, $state);
                return;

            default:
                // code_block, code_tabs, component_raw (code), image, thematic_break.
                return;
        }
    }

    /**
     * Components contribute their labelling attributes (title, name, value,
     * description) as one text, then their children. A TypeProp becomes one text:
     * name, type and description.
     *
     * @param array<string,mixed> $block
     * @param array<string,mixed> $state
     */
    private static function visitComponent(array $block, array &$state): void
    {
        /** @var array<string,mixed> $attrs */
        $attrs = $block['attrs'] ?? [];
        /** @var list<array<string,mixed>> $children */
        $children = $block['blocks'] ?? [];
        $attribute = static fn(string $key): string => isset($attrs[$key]) && is_string($attrs[$key]) ? self::clean($attrs[$key]) : '';

        if ($block['name'] === 'TypeProp') {
            $parts = [$attribute('name'), $attribute('type'), $attribute('typeDescription')];
            foreach ($children as $child) {
                if (($child['type'] ?? '') === 'paragraph') {
                    $parts[] = self::clean(Markdown::plainText($child['inlines']));
                }
            }
            self::addText(implode(' ', array_filter($parts, static fn(string $part): bool => $part !== '')), $state);
            return;
        }

        $label = array_filter(
            array_map($attribute, ['title', 'name', 'value', 'description']),
            static fn(string $part): bool => $part !== ''
        );
        self::addText(implode(' – ', $label), $state);
        if (isset($block['inlines']) && is_array($block['inlines'])) {
            self::addText(Markdown::plainText($block['inlines']), $state);
        }
        self::visitBlocks($children, $state);
    }

    /** @param array<string,mixed> $state */
    private static function addText(string $raw, array &$state): void
    {
        $text = self::clean($raw);
        if ($text === '') {
            return;
        }
        $state['sections'][count($state['sections']) - 1][] = $text;
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    // ------------------------------------------------------------------ Pages

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
     * Breadcrumbs: the tree root's name plus the names of all named nodes on the
     * path, without the page itself (folders and separators). Pages outside the
     * tree (the root index page) have none.
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
}
