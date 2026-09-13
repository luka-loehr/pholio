<?php

declare(strict_types=1);

// Markdown parser: fixed edge cases of the grammar (emphasis, escapes, links, tables,
// lists, components, paragraphs), frontmatter with `updated` and key aliases, and the
// fail-loud cases with file and line.

require __DIR__ . '/run.php';
require_once dirname(__DIR__) . '/src/lib/Markdown.php';

use Pholio\ConfigException;
use Pholio\ContentException;
use Pholio\Markdown;
use Pholio\MarkdownException;

/** @return array<string,mixed> */
function md_first(string $source): array
{
    return Markdown::parse($source, 'unit')->blocks[0];
}

/** Reduced node shape for readable JSON comparisons. */
function md_shape(array $node): array
{
    $out = ['type' => $node['type']];
    foreach (['value', 'href', 'src', 'alt', 'level', 'ordered', 'tight', 'start', 'name', 'align'] as $key) {
        if (array_key_exists($key, $node)) {
            $out[$key] = $node[$key];
        }
    }
    if (isset($node['attrs'])) {
        $out['attrs'] = $node['attrs'];
    }
    foreach (['inlines', 'blocks'] as $key) {
        if (isset($node[$key])) {
            $out[$key] = array_map('md_shape', $node[$key]);
        }
    }
    if (isset($node['items'])) {
        $out['items'] = array_map(static fn(array $item): array => ['blocks' => array_map('md_shape', $item['blocks'])], $node['items']);
    }

    return $out;
}

function md_json(array $node): string
{
    return json_encode(md_shape($node), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

test('nested emphasis **a *b* c**', function (): void {
    assert_same(
        '{"type":"paragraph","inlines":[{"type":"strong","inlines":[{"type":"text","value":"a "},{"type":"emphasis","inlines":[{"type":"text","value":"b"}]},{"type":"text","value":" c"}]}]}',
        md_json(md_first('**a *b* c**')),
    );
});

test('nested emphasis *a **b** c*', function (): void {
    assert_same(
        '{"type":"paragraph","inlines":[{"type":"emphasis","inlines":[{"type":"text","value":"a "},{"type":"strong","inlines":[{"type":"text","value":"b"}]},{"type":"text","value":" c"}]}]}',
        md_json(md_first('*a **b** c*')),
    );
});

test('intraword underscores are not emphasis', function (): void {
    assert_same('{"type":"paragraph","inlines":[{"type":"text","value":"snake_case_word stays text"}]}', md_json(md_first('snake_case_word stays text')));
});

test('underscores at word boundaries are emphasis', function (): void {
    assert_same(
        '{"type":"paragraph","inlines":[{"type":"emphasis","inlines":[{"type":"text","value":"italic"}]},{"type":"text","value":" at the start"}]}',
        md_json(md_first('_italic_ at the start')),
    );
});

test('several emphasis runs on one line', function (): void {
    assert_same(
        '{"type":"paragraph","inlines":[{"type":"text","value":"a "},{"type":"emphasis","inlines":[{"type":"text","value":"b"}]},{"type":"text","value":" c "},{"type":"emphasis","inlines":[{"type":"text","value":"d"}]}]}',
        md_json(md_first('a *b* c *d*')),
    );
});

test('backslash escapes and inline code suppress emphasis', function (): void {
    assert_same(
        '{"type":"paragraph","inlines":[{"type":"text","value":"*not italic* and "},{"type":"code","value":"**not bold**"}]}',
        md_json(md_first('\\*not italic\\* and `**not bold**`')),
    );
});

test('double backticks enclose a single backtick', function (): void {
    assert_same(
        '{"type":"paragraph","inlines":[{"type":"text","value":"A "},{"type":"code","value":"code with ` backtick"},{"type":"text","value":" here"}]}',
        md_json(md_first('A `` code with ` backtick `` here')),
    );
});

test('links carry destination and formatted text, the title is dropped from the shape', function (): void {
    assert_same(
        '{"type":"paragraph","inlines":[{"type":"text","value":"See "},{"type":"link","href":"/docs/a/b","inlines":[{"type":"strong","inlines":[{"type":"text","value":"Title"}]}]},{"type":"text","value":"."}]}',
        md_json(md_first('See [**Title**](/docs/a/b "Hint").')),
    );
    assert_same('Hint', md_first('See [x](/a "Hint").')['inlines'][1]['title']);
});

test('a standalone image stays a paragraph with an image inline', function (): void {
    assert_same('{"type":"paragraph","inlines":[{"type":"image","src":"/path/image.svg","alt":"Alt text"}]}', md_json(md_first('![Alt text](/path/image.svg)')));
});

test('table alignment and column count', function (): void {
    $table = md_first("| a | b | c | d |\n| :-- | ---: | :-: | --- |\n| 1 | 2 | 3 | 4 |");
    assert_same('table', $table['type']);
    assert_same(['left', 'right', 'center', null], $table['align']);
    assert_same([4, 1, 4], [count($table['head']), count($table['rows']), count($table['rows'][0])]);
});

test('an escaped pipe does not split a table cell', function (): void {
    $table = md_first("| a | b |\n| --- | --- |\n| x \\| y | `p\\|q` |");
    assert_same('x | y', Markdown::plainText($table['rows'][0][0]));
});

test('lists: tight, loose, start number, nesting', function (): void {
    $tight = md_first("- one\n- two\n- three");
    assert_same([false, true, 3], [$tight['ordered'], $tight['tight'], count($tight['items'])]);

    $loose = md_first("- one\n\n- two");
    assert_same([false, 2], [$loose['tight'], count($loose['items'])]);

    $ordered = md_first("3. three\n4. four");
    assert_same([true, 3], [$ordered['ordered'], $ordered['start']]);

    $nested = md_first("- outer\n  - inner\n- outer two");
    assert_same(2, count($nested['items']));
    assert_same('list', $nested['items'][0]['blocks'][1]['type'] ?? null);
});

test('components with attributes and parsed content', function (): void {
    $callout = md_first("<Callout title=\"Scope\" type=\"warn\">\nNote text.\n</Callout>");
    assert_same(['component', 'Callout', ['title' => 'Scope', 'type' => 'warn']], [$callout['type'], $callout['name'], $callout['attrs']]);
    assert_same('paragraph', $callout['blocks'][0]['type']);

    $shot = md_first('<Screenshot src="/a.webp" dark="/a-dark.webp" alt="Image" />');
    assert_same(['component_void', '/a-dark.webp'], [$shot['type'], $shot['attrs']['dark']]);

    $cards = md_first("<Cards>\n  <Card title=\"A\" href=\"./a\" />\n  <Card title=\"B\" href=\"./b\" />\n</Cards>");
    assert_same([2, 'B'], [count($cards['blocks']), $cards['blocks'][1]['attrs']['title']]);
});

test('indented continuation lines stay in the paragraph', function (): void {
    $paragraph = md_first("Header line\n            indented continuation");
    assert_same("Header line\nindented continuation", Markdown::plainText($paragraph['inlines']));
});

test('two spaces at the end of a line are a hard break', function (): void {
    assert_same('break', md_first("first line  \nsecond line")['inlines'][1]['type']);
});

test('frontmatter: allowed keys including updated', function (): void {
    $document = Markdown::parse("---\ntitle: \"A: quoted\"\ndescription: 'B'\nupdated: 2026-09-01\nfull: true\n---\n\nText", 'unit');
    assert_same(['title' => 'A: quoted', 'description' => 'B', 'updated' => '2026-09-01', 'full' => 'true'], $document->frontmatter);
    assert_same('paragraph', $document->blocks[0]['type']);
});

test('frontmatter aliases map onto allowed keys in source order', function (): void {
    $document = Markdown::parse("---\ntitle: A\ndate: 2026-09-01\nicon: book\n---\n", 'unit', ['date' => 'updated']);
    assert_same(['title' => 'A', 'updated' => '2026-09-01', 'icon' => 'book'], $document->frontmatter);
});

test('frontmatter alias and target key together are a duplicate', function (): void {
    assert_throws(MarkdownException::class, fn() => Markdown::parse("---\ntitle: A\ndate: x\nupdated: y\n---\n", 'unit', ['date' => 'updated']), 'Frontmatter key "updated" appears twice');
});

test('frontmatter alias without configuration is an unknown key', function (): void {
    assert_throws(MarkdownException::class, fn() => Markdown::parse("---\ntitle: A\ndate: x\n---\n", 'unit'), 'Unknown frontmatter key "date"');
});

test('invalid frontmatter aliases are a configuration error', function (): void {
    assert_throws(ConfigException::class, fn() => Markdown::parse('Text', 'unit', ['date' => 'published']), 'content.frontmatter_aliases');
    assert_throws(ConfigException::class, fn() => Markdown::parse('Text', 'unit', ['title' => 'updated']), 'content.frontmatter_aliases');
});

$failLoud = [
    'unknown tag' => "<Mermaid chart=\"a\">\nx\n</Mermaid>",
    'unknown attribute' => '<Card title="A" color="red" />',
    'missing required attribute' => '<Card href="./a" />',
    'unknown callout type' => "<Callout type=\"panic\">\nx\n</Callout>",
    'heading level 1' => '# Title',
    'heading level 5' => '##### Too deep',
    'unclosed code fence' => "```php\n\$a = 1;",
    'raw HTML' => 'Text with <br> inside',
    'autolink' => 'See <https://example.org> there',
    'bare URL' => 'See https://example.org there',
    'reference link' => 'See [Title][ref] there',
    'footnote without definition' => 'Text with a footnote[^1] inside',
    'setext heading' => "Title\n=====",
    'unknown frontmatter key' => "---\ntitle: A\nauthor: B\n---\n\nText",
    'frontmatter without title' => "---\ndescription: A\n---\n\nText",
    'void component not self-closing' => '<Screenshot src="/a.webp">',
    'unclosed component' => "<Callout title=\"A\">\nText without an end",
    'foreign element in Cards' => "<Cards>\nPlain text\n</Cards>",
];
foreach ($failLoud as $label => $source) {
    test('fail loud: ' . $label, function () use ($source): void {
        assert_throws(MarkdownException::class, fn() => Markdown::parse($source, 'unit'));
    });
}

test('errors are content errors with file and line', function (): void {
    $e = assert_throws(ContentException::class, fn() => Markdown::parse("Paragraph\n\n<Tabs x=\"1\" />", 'page.md'));
    assert_true($e instanceof MarkdownException);
    assert_same(['page.md', 3], [$e->sourceFile, $e->sourceLine]);
    assert_same('page.md:3: ' . $e->getMessage(), $e->describe());
    assert_same(3, $e->exitCode());
});

test('an unnamed source is reported as (unnamed)', function (): void {
    $e = assert_throws(MarkdownException::class, fn() => Markdown::parse('# Title'));
    assert_true(str_starts_with($e->describe(), '(unnamed):1: '), $e->describe());
});
