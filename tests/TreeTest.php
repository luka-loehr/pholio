<?php

declare(strict_types=1);

// Page tree: the demo tree and its navigation state, meta.json features (separators,
// links, rest, extract and exclude), drafts, page extensions and the content errors.

require __DIR__ . '/run.php';
require_once dirname(__DIR__) . '/src/lib/Tree.php';

use Pholio\ContentException;
use Pholio\Tree;

/** Write a content directory from path => contents into a fresh temp dir. */
function tree_fixture(array $files): string
{
    $root = sys_get_temp_dir() . '/pholio-tree-test-' . bin2hex(random_bytes(6));
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

function tree_page(string $title, string $extra = ''): string
{
    return "---\ntitle: {$title}\n{$extra}---\n\nText\n";
}

$demo = new Tree(dirname(__DIR__) . '/examples/demo/content', '/', false, ['md']);

test('demo tree: two root folders with pages and separators in meta.json order', function () use ($demo): void {
    assert_same([
        ['folder' => 'Guide', 'root' => true, 'index' => null, 'children' => [
            ['page' => '/guide'], ['page' => '/guide/installation'], ['page' => '/guide/writing-pages'],
            ['sep' => 'Components'],
            ['page' => '/guide/callouts-and-cards'], ['page' => '/guide/tabs-and-accordions'], ['page' => '/guide/steps-and-files'],
        ]],
        ['folder' => 'Reference', 'root' => true, 'index' => null, 'children' => [
            ['page' => '/reference'], ['page' => '/reference/code-blocks'], ['page' => '/reference/api-types'],
            ['sep' => 'Markdown'],
            ['page' => '/reference/markdown-extras'],
        ]],
    ], $demo->toArray());
    assert_same(10, count($demo->pages()));
});

test('demo tabs: one tab per root folder, projected onto the same page where it exists', function () use ($demo): void {
    $tabs = $demo->tabsFor('/guide/installation');
    assert_same([['title' => 'Guide', 'url' => '/guide/installation'], ['title' => 'Reference', 'url' => '/reference']], array_map(static fn(array $tab): array => ['title' => $tab['title'], 'url' => $tab['url']], $tabs['tabs']));
    assert_same(0, $tabs['selected']);
    assert_same(1, $demo->tabsFor('/reference/api-types')['selected']);
    assert_same(['/guide', '/reference'], array_column($demo->layoutTabs(), 'url'));
});

test('demo prev/next stays inside the root folder', function () use ($demo): void {
    $items = $demo->footerItems('/guide/installation');
    assert_same(['/guide', '/guide/writing-pages'], [$items['previous']['url'], $items['next']['url']]);
    assert_same('Writing pages', $items['next']['name']);
    assert_same(['previous'], array_keys($demo->footerItems('/guide/steps-and-files')));
    assert_same(['next'], array_keys($demo->footerItems('/reference')));
});

test('demo breadcrumb of a page directly in a root folder is empty', function () use ($demo): void {
    assert_same([], $demo->breadcrumb('/guide/installation'));
});

test('demo sidebar shows only the current root folder with the active page', function () use ($demo): void {
    $sidebar = $demo->sidebarState('/reference/code-blocks/');
    assert_same(['page', 'page', 'page', 'separator', 'page'], array_column($sidebar, 'type'));
    assert_same([false, true, false, null, false], array_map(static fn(array $entry): ?bool => $entry['active'] ?? null, $sidebar));
});

$nested = tree_fixture([
    'index.md' => tree_page('Home'),
    'meta.json' => ['pages' => ['a', 'b', 'index']],
    'a/meta.json' => ['title' => 'Area A', 'root' => true, 'pages' => ['index', 'first', '---[star]Group---', 'sub', '[Site](/site)', 'external:[Upstream](https://example.org)', '...rest']],
    'a/index.md' => tree_page('A index'),
    'a/first.md' => tree_page('First', "description: The first page\n"),
    'a/_draft.md' => tree_page('Draft'),
    'a/sub/index.md' => tree_page('Sub'),
    'a/sub/deep.md' => tree_page('Deep'),
    'a/rest/one.md' => tree_page('One'),
    'a/rest/two.md' => tree_page('Two'),
    'a/rest/hidden.md' => tree_page('Hidden'),
    'a/rest/meta.json' => ['pages' => ['...', '!hidden']],
    'b/meta.json' => ['title' => 'Area B', 'root' => true],
    'b/index.md' => tree_page('B index'),
    'b/first.md' => tree_page('First in B'),
]);

test('meta.json: separators with icons, links, external links, extracted folders and exclusions', function () use ($nested): void {
    $tree = new Tree($nested, '/docs');
    $a = $tree->toArray()[0];
    assert_same('Area A', $a['folder']);
    assert_same(
        [['page' => '/docs/a'], ['page' => '/docs/a/first'], ['sep' => 'Group'], ['folder' => 'Sub', 'root' => false, 'index' => '/docs/a/sub', 'children' => [['page' => '/docs/a/sub/deep']]], ['page' => '/site'], ['page' => 'https://example.org'], ['page' => '/docs/a/rest/one'], ['page' => '/docs/a/rest/two']],
        $a['children'],
    );
    $separator = $tree->root()->children[0]->children[2];
    assert_same(['separator', 'star'], [$separator->type, $separator->icon]);
    $external = $tree->root()->children[0]->children[5];
    assert_same([true, null], [$external->external, $tree->root()->children[0]->children[4]->external]);
    assert_same('/docs', $tree->toArray()[2]['page']);
});

test('drafts are left out unless requested', function () use ($nested): void {
    assert_true(!in_array('/docs/a/_draft', array_column((new Tree($nested, '/docs'))->pages(), 'url'), true));
    assert_true(in_array('/docs/a/_draft', array_column((new Tree($nested, '/docs', true))->pages(), 'url'), true));
});

test('breadcrumb, prev/next skipping external links, and tab projection', function () use ($nested): void {
    $tree = new Tree($nested, '/docs');
    assert_same([['name' => 'Sub', 'url' => '/docs/a/sub']], $tree->breadcrumb('/docs/a/sub/deep'));
    $items = $tree->footerItems('/docs/a/first');
    assert_same(['/docs/a', 'The first page', '/docs/a/sub'], [$items['previous']['url'], $tree->footerItems('/docs/a')['next']['description'], $items['next']['url']]);
    assert_same(['/docs/a/sub/deep', '/site'], [$tree->footerItems('/docs/a/sub')['next']['url'], $tree->footerItems('/docs/a/sub/deep')['next']['url']]);
    $tabs = $tree->tabsFor('/docs/a/first');
    assert_same(['/docs/a/first', '/docs/b/first'], array_column($tabs['tabs'], 'url'));
    assert_same('Area A', $tree->rootFor('/docs/a/sub/deep')->name);
});

test('sidebar: folders on the path are open', function () use ($nested): void {
    $sidebar = (new Tree($nested, '/docs'))->sidebarState('/docs/a/sub/deep');
    $folder = $sidebar[3];
    assert_same(['folder', true, true, '/docs/a/sub'], [$folder['type'], $folder['open'], $folder['active'], $folder['index']]);
    assert_same(true, $folder['children'][0]['active']);
});

test('.mdx pages are rejected by default and accepted when enabled', function (): void {
    $root = tree_fixture(['index.md' => tree_page('Home'), 'guide.mdx' => tree_page('Guide')]);
    $e = assert_throws(ContentException::class, fn() => new Tree($root), 'page extension ".mdx" is not enabled');
    assert_same($root . '/guide.mdx', $e->sourceFile);
    assert_same(['/guide', '/'], array_column((new Tree($root, '/', false, ['md', 'MDX']))->pages(), 'url'), 'pages are sorted by file path');
});

test('other file types in the content directory are ignored', function (): void {
    $root = tree_fixture(['index.md' => tree_page('Home'), 'image.svg' => '<svg/>', '.hidden.md' => tree_page('Hidden')]);
    assert_same(['/'], array_column((new Tree($root))->pages(), 'url'));
});

test('content errors: duplicate slugs, invalid meta.json, folder group as a file name', function (): void {
    $duplicate = tree_fixture(['a.md' => tree_page('A'), '(group)/a.md' => tree_page('A again')]);
    assert_throws(ContentException::class, fn() => new Tree($duplicate), 'duplicate slug: a');

    $meta = tree_fixture(['index.md' => tree_page('Home'), 'meta.json' => '"pages"']);
    $e = assert_throws(ContentException::class, fn() => new Tree($meta), 'expected a JSON object');
    assert_same($meta . '/meta.json', $e->sourceFile);

    $group = tree_fixture(['(group).md' => tree_page('Group')]);
    assert_throws(ContentException::class, fn() => new Tree($group), 'folder group');
});

test('index files get an index slug when their folder slug is taken', function (): void {
    $root = tree_fixture(['a.md' => tree_page('A'), 'a/index.md' => tree_page('A index')]);
    assert_same(['/a', '/a/index'], array_column((new Tree($root))->pages(), 'url'));
});
