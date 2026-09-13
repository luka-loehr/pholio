<?php

declare(strict_types=1);

// Search index: shape, slots and flags over the demo content, section anchors against the table
// of contents, component and description texts, normalisation, codecs, drafts, page order and the
// recorded tokenizer profile. With Node, also the PHP/JavaScript selftest and the consistency check
// over the demo (node verify/run.mjs runs the same commands).

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

/**
 * Slot => flags of one word, or [] when the index lacks it.
 *
 * @return array<int,int>
 */
function search_postings(array $index, string $word): array
{
    $at = array_search($word, SearchIndex::decodeWords($index['words']), true);

    return $at === false ? [] : SearchIndex::decodePostings($index['postings'][$at]);
}

/**
 * First slot of every page, by URL.
 *
 * @return array<string,int>
 */
function search_page_slots(array $index): array
{
    $slots = [];
    $slot = 0;
    foreach ($index['pages'] as $page) {
        $slots[$page[0]] = $slot;
        $slot += 1 + intdiv(count($page) - 4, 2);
    }

    return $slots;
}

$demoTree = new Tree(SEARCH_DEMO, '/', false, ['md']);
$demo = SearchIndex::build($demoTree, search_loader(SEARCH_DEMO), '/');
$demoDocuments = SearchIndex::documents($demoTree, search_loader(SEARCH_DEMO), '/');

test('demo index: header, one entry per tree page, words sorted and unique, one posting list per word', function () use ($demo, $demoTree): void {
    assert_same(['v', 'base', 'tokenizer', 'crumbs', 'pages', 'lengths', 'words', 'postings'], array_keys($demo));
    assert_same(count($demo['pages']), count($demo['lengths']));
    assert_same([2, '/', 'english'], [$demo['v'], $demo['base'], $demo['tokenizer']]);
    assert_same(array_column($demoTree->pages(), 'url'), array_column($demo['pages'], 0));
    $words = SearchIndex::decodeWords($demo['words']);
    $sorted = $words;
    sort($sorted, SORT_STRING);
    assert_same($sorted, $words, 'words in byte order');
    assert_same(count($words), count(array_unique($words)), 'words unique');
    assert_same(count($words), count($demo['postings']));
    foreach ($words as $word) {
        assert_true(preg_match('/^[a-z0-9]+$/', $word) === 1, 'word characters: ' . $word);
    }
});

test('demo index: postings stay inside the slots, page slots carry only title, path, description and keyword flags', function () use ($demo): void {
    $pageSlots = array_flip(search_page_slots($demo));
    $total = 0;
    foreach ($demo['pages'] as $page) {
        assert_true((count($page) - 4) % 2 === 0, 'anchor/heading pairs on ' . $page[0]);
        $total += 1 + intdiv(count($page) - 4, 2);
    }
    foreach ($demo['postings'] as $posting) {
        $previous = -1;
        foreach (SearchIndex::decodePostings($posting) as $slot => $flags) {
            assert_true($slot > $previous && $slot < $total, 'slot order and range');
            $previous = $slot;
            if (isset($pageSlots[$slot])) {
                assert_true($flags >= 1 && $flags <= 15, 'page slot flags ' . $flags);
            } else {
                assert_true(($flags & 3) === 0 && $flags >= 4, 'section slot flags ' . $flags);
            }
        }
    }
});

test('demo index: title, path, heading and text flags land on the right slots', function () use ($demo): void {
    $slots = search_page_slots($demo);
    $installation = $slots['/guide/installation'];
    assert_same(SearchIndex::FIELD_TITLE | SearchIndex::FIELD_PATH, search_postings($demo, 'installation')[$installation] ?? null, 'title and slug');
    assert_same(SearchIndex::FIELD_PATH, search_postings($demo, 'guide')[$installation] ?? null, 'breadcrumb and URL segment');

    // "Upgrading" is a heading of the installation page: the flag sits on one of its section slots.
    $upgrading = array_filter(search_postings($demo, 'upgrading'), static fn(int $flags): bool => ($flags & SearchIndex::FIELD_HEADING) !== 0);
    assert_same(1, count($upgrading));
    $slot = array_key_first($upgrading);
    assert_true($slot > $installation && $slot < $slots['/guide/steps-and-files'], 'heading slot belongs to the installation page');

    $texts = array_filter(search_postings($demo, 'lanternfly'), static fn(int $flags): bool => $flags >> 3 > 0);
    assert_true(count($texts) > 3, 'body text counts');
});

test('demo documents: section anchors are the table of contents anchors, descriptions are indexed once', function () use ($demoDocuments, $demoTree): void {
    $loader = search_loader(SEARCH_DEMO);
    foreach ($demoTree->pages() as $pageIndex => $page) {
        $document = $loader($page);
        $anchors = array_map(static fn(array $item): string => substr((string) $item['url'], 1), Toc::build($document->headings(), new Slugger()));
        $sections = $demoDocuments[$pageIndex]['sections'];
        $headingAnchors = array_values(array_filter(array_column($sections, 0), static fn(?string $anchor): bool => $anchor !== null));
        // The table of contents also lists the generated footnotes section; every other entry is a section, in order.
        $missing = array_values(array_diff($anchors, $headingAnchors));
        assert_same($headingAnchors, array_values(array_intersect($anchors, $headingAnchors)), 'sections of ' . $page['url'] . ' in TOC order');
        assert_true(count($missing) <= 1 && ($missing === [] || str_starts_with($missing[0], 'footnotes')), 'only the footnotes section is missing on ' . $page['url'] . ': ' . implode(', ', $missing));
        foreach (array_slice($sections, 1) as $section) {
            assert_true($section[0] !== null, 'only the first section may lack an anchor on ' . $page['url']);
        }

        $description = (string) ($page['data']['description'] ?? '');
        $texts = array_merge(...array_map(static fn(array $section): array => array_slice($section, 2), $sections));
        if ($description !== '') {
            assert_same(1, count(array_keys($texts, $description, true)), 'description indexed once on ' . $page['url']);
            assert_same([null, null, $description], array_slice($sections[0], 0, 3), 'description opens the first section on ' . $page['url']);
        }
    }
});

test('demo documents: components give their labels, type props one text, code is not indexed', function () use ($demoDocuments): void {
    $texts = [];
    foreach ($demoDocuments as $document) {
        foreach ($document['sections'] as $section) {
            foreach (array_slice($section, 2) as $text) {
                $texts[] = $text;
            }
        }
    }
    assert_true(in_array('Two trailing spaces end a line without starting a new paragraph.', $texts, true), 'hard break as a space');
    assert_true(array_filter($texts, static fn(string $t): bool => str_starts_with($t, 'path ') && str_contains($t, 'Folder of the archive')) !== [], 'TypeProp name, type and description');
    assert_true(array_filter($texts, static fn(string $t): bool => str_contains($t, 'brew install lanternfly')) === [], 'code block content is not indexed');
    foreach ($texts as $text) {
        assert_true(preg_match('/<[A-Z][A-Za-z]*(?:\s|\/?>)/', $text) !== 1, 'no component tags in texts: ' . $text);
        assert_same($text, trim((string) preg_replace('/\s+/u', ' ', $text)), 'collapsed whitespace');
    }
});

test('normalise: lowercase, folding, combining marks, german digraphs', function (): void {
    assert_same('grosse ubersicht strasse', SearchIndex::normalize('Größe Übersicht Straße', 'english'));
    assert_same('passworter', SearchIndex::normalize('Passwörter', 'german'));
    assert_same('passworter', SearchIndex::normalize('Passwoerter', 'german'));
    assert_same('passwoerter', SearchIndex::normalize('Passwoerter', 'english'));
    assert_same('cafe aeon oeuvre', SearchIndex::normalize("Cafe\u{301} Æon Œuvre", 'english'));
    assert_same('queue', SearchIndex::normalize('Queue', 'english'));
});

test('words: split at non-alphanumerics, joined forms of hyphen and underscore chains', function (): void {
    assert_same(['chat', 'export', 'chatexport'], SearchIndex::words('Chat-Export', 'english'));
    assert_same(['a', 'b', 'c', 'abc', 'ab', 'bc'], SearchIndex::words('a-b-c', 'english'));
    assert_same(['user', 'id', 'userid'], SearchIndex::words('user_id', 'english'));
    assert_same(['don', 't', 're', 'index', 'reindex'], SearchIndex::words("Don't re-index", 'english'));
    assert_same(['zwei', 'faktor', 'authentifizierung', 'zweifaktorauthentifizierung', 'zweifaktor', 'faktorauthentifizierung'], SearchIndex::words('Zwei-Faktor-Authentifizierung', 'german'));
    assert_same([], SearchIndex::words(' -- ', 'german'));
});

test('codecs: posting strings and the front-coded word list', function () use ($demo): void {
    assert_same([0 => 1, 1 => 12, 40 => 4, 1100 => 63], SearchIndex::decodePostings('ABAMmBEjhB_'));
    $words = SearchIndex::decodeWords($demo['words']);
    assert_true(count($words) > 100, 'demo vocabulary');
    assert_throws(\InvalidArgumentException::class, static fn() => SearchIndex::decodePostings('A!'), 'posting character');
});

test('tokenizer: german is recorded, unknown profiles are rejected', function () use ($demoTree): void {
    assert_same(['english', 'german'], SearchIndex::TOKENIZERS);
    $german = SearchIndex::build($demoTree, search_loader(SEARCH_DEMO), '/', null, false, 'german');
    assert_same('german', $german['tokenizer']);
    assert_throws(\InvalidArgumentException::class, static fn() => SearchIndex::build($demoTree, search_loader(SEARCH_DEMO), '/', null, false, 'french'), 'french');
});

test('tokenizer: the PHP profiles and folding are the ones search.js implements', function (): void {
    $js = (string) file_get_contents(dirname(__DIR__) . '/theme/js/search.js');
    assert_true(preg_match("/export const TOKENIZERS = Object\\.freeze\\(\\['english', 'german'\\]\\);/", $js) === 1, 'TOKENIZERS in search.js');
    assert_true(preg_match('/export const FOLDING =\s*\'([^\']*)\'\s*\+\s*\'([^\']*)\'\s*\+\s*\'([^\']*)\';/', $js, $match) === 1, 'FOLDING in search.js');
    assert_same(SearchIndex::FOLDING, $match[1] . $match[2] . $match[3]);
});

test('drafts: pages starting with "_" are indexed only when drafts are included', function (): void {
    $dir = search_fixture([
        'meta.json' => ['pages' => ['index', 'draft']],
        'index.md' => "---\ntitle: Home\n---\n\nWelcome.\n",
        '_draft.md' => "---\ntitle: Draft\n---\n\nNot yet.\n",
    ]);
    $without = SearchIndex::build(new Tree($dir, '/docs', false, ['md']), search_loader($dir), '/docs');
    assert_same(['/docs'], array_column($without['pages'], 0));
    $with = SearchIndex::build(new Tree($dir, '/docs', true, ['md']), search_loader($dir), '/docs', null, true);
    $urls = array_column($with['pages'], 0);
    sort($urls);
    assert_same(['/docs', '/docs/_draft'], $urls);
});

test('page order: the given file order decides the page ids; unknown files and foreign URLs throw', function () use ($demoTree): void {
    $files = array_column($demoTree->pages(), 'file');
    $reversed = SearchIndex::build($demoTree, search_loader(SEARCH_DEMO), '/', array_reverse($files));
    assert_same(array_reverse(array_column($demoTree->pages(), 'url')), array_column($reversed['pages'], 0));
    assert_throws(\RuntimeException::class, static fn() => SearchIndex::build($demoTree, search_loader(SEARCH_DEMO), '/', array_slice($files, 1)), 'page order');
    assert_throws(\RuntimeException::class, static fn() => SearchIndex::build($demoTree, search_loader(SEARCH_DEMO), '/docs'), 'base URL');
});

test('sections: description skipped when a text repeats it; frontmatter heading kept when it differs', function (): void {
    $dir = search_fixture([
        'meta.json' => ['pages' => ['index', 'export']],
        'index.md' => "---\ntitle: Home\ndescription: Same text.\n---\n\nSame text.\n\n## Part\n\nOther text.\n",
        'export.md' => "---\ntitle: Export\nheading: \"Chat: Export\"\nkeywords: download, Dark-Mode\n---\n\n## Formats\n\nMarkdown and PDF.\n",
    ]);
    $tree = new Tree($dir, '/', false, ['md']);
    // The tree lists index pages last; the file order puts Home first.
    $order = ['index.md', 'export.md'];
    $documents = SearchIndex::documents($tree, search_loader($dir), '/', $order);
    assert_same([[null, null, 'Same text.'], ['part', 'Part', 'Other text.']], $documents[0]['sections']);
    assert_same(null, $documents[0]['heading']);
    assert_same('Chat: Export', $documents[1]['heading']);
    assert_same(['Same text.', null], [$documents[0]['description'], $documents[0]['keywords']]);
    assert_same([null, 'download, Dark-Mode'], [$documents[1]['description'], $documents[1]['keywords']]);
    assert_same([['formats', 'Formats', 'Markdown and PDF.']], $documents[1]['sections']);

    $index = SearchIndex::build($tree, search_loader($dir), '/', $order);
    assert_same([['Docs']], $index['crumbs']);
    assert_same(['/', 'Home', null, 0, null, null, 'part', 'Part'], $index['pages'][0]);
    assert_same(['/export', 'Export', 'Chat: Export', 0, 'formats', 'Formats'], $index['pages'][1]);
    // Home owns slots 0–2, the export page 3 (page) and 4 (Formats).
    assert_same([3 => SearchIndex::FIELD_TITLE], search_postings($index, 'chat'));
    assert_same([3 => SearchIndex::FIELD_TITLE | SearchIndex::FIELD_PATH], search_postings($index, 'export'));
    assert_same([4 => 1 << 3], search_postings($index, 'pdf'));
    assert_same([2 => SearchIndex::FIELD_HEADING], search_postings($index, 'part'));
    // Description on the page slot and as the first text; keywords with their joined forms.
    assert_same([0 => SearchIndex::FIELD_DESCRIPTION, 1 => 1 << 3], search_postings($index, 'same'));
    assert_same([3 => SearchIndex::FIELD_KEYWORDS], search_postings($index, 'darkmode'));
    assert_same([2, 1], $index['lengths']);
});

/** Runs verify/search-check.mjs with Node; skips when Node is missing. */
function search_node(array $args): array
{
    exec('command -v node 2>/dev/null', $found, $status);
    if ($status !== 0) {
        skip('node: not on PATH');
    }
    $command = 'node ' . escapeshellarg(dirname(__DIR__) . '/verify/search-check.mjs') . ' '
        . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    exec($command, $output, $status);

    return [$status, implode("\n", $output)];
}

test('node: search.js and SearchIndex.php normalise, split and encode identically', function (): void {
    [$status, $output] = search_node(['--selftest']);
    assert_same(0, $status, $output);
    assert_true(preg_match('/(\d+)\/\1 checks passed/', $output) === 1, $output);
});

test('node: search.js rebuilds the demo index from the page texts', function (): void {
    [$status, $output] = search_node([
        '--consistency', '--content', dirname(__DIR__) . '/examples/demo/content', '--base-url', '/', '--tokenizer', 'english',
    ]);
    assert_same(0, $status, $output);
    assert_true(preg_match('/(\d+)\/\1 checks passed/', $output) === 1, $output);
});
