<?php

declare(strict_types=1);

// Search index: document shape and order over the demo content, heading anchors against the
// table of contents, breadcrumbs, drafts, page order and the recorded tokenizer profile.
// With Node and verify/node_modules, also the search.js splitter selftest and the zbsearch
// oracle over the demo queries (tier 2 runs the same commands).

require __DIR__ . '/run.php';
require_once dirname(__DIR__) . '/src/lib/SearchIndex.php';
require_once dirname(__DIR__) . '/src/lib/Toc.php';

use Pholio\Document;
use Pholio\Markdown;
use Pholio\SearchIndex;
use Pholio\Slugger;
use Pholio\Toc;
use Pholio\Tree;

const SEARCH_DEMO = __DIR__ . '/../examples/demo/content';

/** @return callable(array{url:string,slugs:list<string>,file:string,data:array<string,mixed>}):Document */
function search_loader(string $contentDir): callable
{
    return static function (array $page) use ($contentDir): Document {
        $path = $contentDir . '/' . $page['file'];

        return Markdown::parse((string) file_get_contents($path), $path);
    };
}

/** Write a content directory from path => contents into a fresh temp dir. */
function search_fixture(array $files): string
{
    $root = sys_get_temp_dir() . '/pholio-search-test-' . bin2hex(random_bytes(6));
    foreach ($files as $path => $contents) {
        $file = $root . '/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, is_array($contents) ? json_encode($contents) : $contents);
    }
    register_shutdown_function(static function () use ($root): void {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    });

    return $root;
}

$demoTree = new Tree(SEARCH_DEMO, '/', false, ['md']);
$demo = SearchIndex::build($demoTree, search_loader(SEARCH_DEMO), '/');

test('demo index: base, default tokenizer and one entry per tree page', function () use ($demo, $demoTree): void {
    assert_same(['base', 'tokenizer', 'pages', 'docs'], array_keys($demo));
    assert_same('/', $demo['base']);
    assert_same('english', $demo['tokenizer']);
    assert_same(array_column($demoTree->pages(), 'url'), array_column($demo['pages'], 'u'));
});

test('demo index: every page starts with its page document, ids are unique, no empty content', function () use ($demo): void {
    $ids = [];
    $previousPage = -1;
    foreach ($demo['docs'] as $position => [$pageIndex, $type, $number, $anchor, $content]) {
        if ($pageIndex !== $previousPage) {
            assert_same($previousPage + 1, $pageIndex, "pages appear in order at doc {$position}");
            assert_same([SearchIndex::TYPE_PAGE, null, null], [$type, $number, $anchor], "page document first at doc {$position}");
            assert_same($demo['pages'][$pageIndex]['t'], $content, 'page document content is the title');
            $previousPage = $pageIndex;
        }
        $url = $demo['pages'][$pageIndex]['u'];
        $id = $number === null ? $url : $url . '-' . $number;
        assert_true(!isset($ids[$id]), 'duplicate id ' . $id);
        $ids[$id] = true;
        assert_true($content !== '', 'empty content in ' . $id);
    }
    assert_same(count($demo['pages']) - 1, $previousPage);
});

test('demo index: numbers count up per page; the description, then headings, then text blocks', function () use ($demo): void {
    $expected = [];
    $rank = [];
    foreach ($demo['docs'] as [$pageIndex, $type, $number, $anchor]) {
        if ($type === SearchIndex::TYPE_PAGE) {
            $expected[$pageIndex] = 0;
            $rank[$pageIndex] = 0;
            continue;
        }
        assert_same($expected[$pageIndex]++, $number, 'running number on page ' . $pageIndex);
        // 1 = description (text before any heading document), 2 = heading, 3 = text after headings.
        $current = $type === SearchIndex::TYPE_HEADING ? 2 : ($rank[$pageIndex] < 2 && $number === 0 && $anchor === null ? 1 : 3);
        assert_true($current >= $rank[$pageIndex], 'document kinds in buildDocuments order on page ' . $pageIndex);
        $rank[$pageIndex] = $current;
    }
});

test('demo index: descriptions are indexed once, heading documents match the table of contents', function () use ($demo, $demoTree): void {
    $loader = search_loader(SEARCH_DEMO);
    foreach ($demoTree->pages() as $pageIndex => $page) {
        $document = $loader($page);
        $anchors = array_map(static fn(array $item): string => substr((string) $item['url'], 1), Toc::build($document->headings(), new Slugger()));
        $headings = [];
        $texts = [];
        foreach ($demo['docs'] as [$docPage, $type, , $anchor, $content]) {
            if ($docPage !== $pageIndex) {
                continue;
            }
            if ($type === SearchIndex::TYPE_HEADING) {
                $headings[] = $anchor;
            } elseif ($type === SearchIndex::TYPE_TEXT) {
                $texts[] = $content;
                assert_true($anchor === null || in_array($anchor, $anchors, true), "text anchor #{$anchor} is a heading of {$page['url']}");
            }
        }
        // The table of contents also lists the generated footnotes section, which
        // remark-structure never sees; every other entry has its heading document, in order.
        $missing = array_values(array_diff($anchors, $headings));
        assert_same($headings, array_values(array_intersect($anchors, $headings)), 'heading documents of ' . $page['url'] . ' in TOC order');
        assert_true(count($missing) <= 1 && ($missing === [] || str_starts_with($missing[0], 'footnotes')), 'only the footnotes section lacks a heading document on ' . $page['url'] . ': ' . implode(', ', $missing));
        $description = (string) ($page['data']['description'] ?? '');
        if ($description !== '') {
            assert_same(1, count(array_keys($texts, $description, true)), 'description indexed once on ' . $page['url']);
        }
    }
});

test('demo index: breadcrumbs are the tree root name plus the named folders and separators above each page', function () use ($demo, $demoTree): void {
    foreach ($demo['pages'] as $page) {
        $path = $demoTree->pathTo($page['u']);
        if ($path === []) {
            assert_same(null, $page['b'], 'no breadcrumbs outside the tree: ' . $page['u']);
            continue;
        }
        assert_true(is_array($page['b']), 'breadcrumbs list for ' . $page['u']);
    }
    $byUrl = array_column($demo['pages'], 'b', 'u');
    $rootName = $demoTree->root()->name;
    assert_same([$rootName, 'Guide'], $byUrl['/guide/installation']);
    assert_same([$rootName, 'Reference'], $byUrl['/reference/api-types']);
    assert_same([$rootName, 'Reference', 'Markdown'], $byUrl['/reference/markdown-extras']);
});

test('demo index: component tags without children are serialised, code blocks are not indexed', function () use ($demo): void {
    $contents = array_column($demo['docs'], 4);
    $typeTables = array_values(array_filter($contents, static fn(string $c): bool => str_starts_with($c, '<TypeTable')));
    assert_same(2, count($typeTables));
    assert_contains("\n  type=\"{\n  path: {\n    description: 'Folder of the archive", $typeTables[0]);
    assert_true(!in_array('brew install lanternfly', $contents, true), 'code block content is not indexed');
    assert_true(in_array("Two trailing spaces end a line\\\nwithout starting a new paragraph.", $contents, true), 'hard break serialised as backslash and newline');
});

test('tokenizer: german is recorded, unknown profiles are rejected', function () use ($demoTree): void {
    assert_same(['english', 'german'], SearchIndex::TOKENIZERS);
    $german = SearchIndex::build($demoTree, search_loader(SEARCH_DEMO), '/', null, false, 'german');
    assert_same('german', $german['tokenizer']);
    assert_throws(\InvalidArgumentException::class, static fn() => SearchIndex::build($demoTree, search_loader(SEARCH_DEMO), '/', null, false, 'french'), 'french');
});

test('tokenizer: the PHP profiles are the profiles search.js implements', function (): void {
    $js = (string) file_get_contents(dirname(__DIR__) . '/theme/js/search.js');
    assert_true(preg_match('/const SPLITTER_ALPHABETS = \{(.*?)\n\};/s', $js, $match) === 1, 'SPLITTER_ALPHABETS in search.js');
    preg_match_all('/^\s*([a-z]+):/m', $match[1], $names);
    assert_same(SearchIndex::TOKENIZERS, $names[1]);
});

test('drafts: pages starting with "_" are indexed only when drafts are included', function (): void {
    $dir = search_fixture([
        'meta.json' => ['pages' => ['index', 'draft']],
        'index.md' => "---\ntitle: Home\n---\n\nWelcome.\n",
        '_draft.md' => "---\ntitle: Draft\n---\n\nNot yet.\n",
    ]);
    $without = SearchIndex::build(new Tree($dir, '/docs', false, ['md']), search_loader($dir), '/docs');
    assert_same(['/docs'], array_column($without['pages'], 'u'));
    $with = SearchIndex::build(new Tree($dir, '/docs', true, ['md']), search_loader($dir), '/docs', null, true);
    $urls = array_column($with['pages'], 'u');
    sort($urls);
    assert_same(['/docs', '/docs/_draft'], $urls);
});

test('page order: the given file order decides insertion; unknown files and foreign URLs throw', function () use ($demoTree): void {
    $files = array_column($demoTree->pages(), 'file');
    $reversed = SearchIndex::build($demoTree, search_loader(SEARCH_DEMO), '/', array_reverse($files));
    assert_same(array_reverse(array_column($demoTree->pages(), 'url')), array_column($reversed['pages'], 'u'));
    assert_throws(\RuntimeException::class, static fn() => SearchIndex::build($demoTree, search_loader(SEARCH_DEMO), '/', array_slice($files, 1)), 'page order');
    assert_throws(\RuntimeException::class, static fn() => SearchIndex::build($demoTree, search_loader(SEARCH_DEMO), '/docs'), 'base URL');
});

test('description: skipped when a text block already has the same content', function (): void {
    $dir = search_fixture([
        'meta.json' => ['pages' => ['index']],
        'index.md' => "---\ntitle: Home\ndescription: Same text.\n---\n\nSame text.\n\n## Part\n\nOther text.\n",
    ]);
    $index = SearchIndex::build(new Tree($dir, '/', false, ['md']), search_loader($dir), '/');
    assert_same([
        [0, SearchIndex::TYPE_PAGE, null, null, 'Home'],
        [0, SearchIndex::TYPE_HEADING, 0, 'part', 'Part'],
        [0, SearchIndex::TYPE_TEXT, 1, null, 'Same text.'],
        [0, SearchIndex::TYPE_TEXT, 2, 'part', 'Other text.'],
    ], $index['docs']);
});

/** Runs a verify tool with Node; skips when Node or the verify dependencies are missing. */
function search_node(array $args): array
{
    $root = dirname(__DIR__);
    exec('command -v node 2>/dev/null', $found, $status);
    if ($status !== 0) {
        skip('node: not on PATH');
    }
    if (!is_dir($root . '/verify/node_modules/zbsearch') || !is_dir($root . '/verify/node_modules/fumadocs-core')) {
        skip('node: run npm ci in verify/ first');
    }
    $command = 'node ' . escapeshellarg($root . '/verify/search-parity.mjs') . ' '
        . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    exec($command, $output, $status);

    return [$status, implode("\n", $output)];
}

test('node: search.js splitters and folding equal zbsearch 4.0.0', function (): void {
    [$status, $output] = search_node(['--selftest']);
    assert_same(0, $status, $output);
    assert_contains('12/12 checks passed', $output);
});

test('node: search.js answers equal Fumadocs search over the demo queries', function (): void {
    $root = dirname(__DIR__);
    [$status, $output] = search_node([
        '--oracle', '--content', $root . '/examples/demo/content', '--base-url', '/',
        '--queries', $root . '/verify/fixtures/demo/queries.json', '--tokenizer', 'english',
    ]);
    assert_same(0, $status, $output);
    assert_true(preg_match('/(\d+)\/\1 queries identical/', $output) === 1, $output);
});
