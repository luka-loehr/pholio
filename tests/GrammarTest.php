<?php

declare(strict_types=1);

// Full content grammar: CommonMark/GFM cases (values determined against remark
// and remark-gfm), component attribute values, the fail-loud
// rules with their hints, the feature histogram over examples/demo/content (the demo
// must use every construct) and the AST snapshot tests/fixtures/demo-ast.json.
//
// Regenerate the snapshot only when the demo content or the AST changes on purpose:
//   php tools/dump-ast.php --snapshot examples/demo/content tests/fixtures/demo-ast.json
// and review the diff.

require __DIR__ . '/run.php';
require_once dirname(__DIR__) . '/src/lib/Markdown.php';

use Pholio\Markdown;
use Pholio\MarkdownException;

/** @return list<array<string,mixed>> */
function grammar_blocks(string $source): array
{
    return Markdown::parse($source, 'unit')->blocks;
}

function grammar_json(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** Location and message of the error, or null when parsing succeeds. */
function grammar_error(string $source): ?string
{
    try {
        Markdown::parse($source, 'unit');
    } catch (MarkdownException $e) {
        return $e->describe();
    }

    return null;
}

/**
 * @param list<array<string,mixed>> $blocks
 * @param array<string,int> $histogram
 */
function grammar_count_blocks(array $blocks, array &$histogram): void
{
    $count = static function (string $label) use (&$histogram): void {
        $histogram[$label] = ($histogram[$label] ?? 0) + 1;
    };
    foreach ($blocks as $block) {
        $type = (string) $block['type'];
        $count(isset($block['name']) ? $type . ' <' . $block['name'] . '>' : $type);
        if ($type === 'heading') {
            $count('heading h' . $block['level']);
        }
        if ($type === 'code_block') {
            foreach (['title', 'lineNumbers', 'tab', 'noCopy', 'tabGroup'] as $key) {
                if (isset($block['meta'][$key])) {
                    $count('code_block meta ' . $key);
                }
            }
            if (trim((string) $block['meta']['raw']) !== '') {
                $count('code_block meta raw');
            }
        }
        if (in_array($type, ['component', 'component_void', 'component_raw'], true)) {
            foreach ($block['attrs'] as $key => $value) {
                $kind = is_bool($value) ? 'bool' : (is_int($value) ? 'int' : (is_array($value) ? 'list' : 'string'));
                $count('attr ' . $block['name'] . '.' . $key . ' (' . $kind . ')');
            }
        }
        if ($type === 'list') {
            $count($block['ordered'] ? 'list ordered' : 'list bullet');
            foreach ($block['items'] as $item) {
                $count('list item');
                if (array_key_exists('checked', $item)) {
                    $count('list item task');
                }
                grammar_count_blocks($item['blocks'], $histogram);
            }
            continue;
        }
        if ($type === 'code_tabs') {
            foreach ($block['items'] as $item) {
                grammar_count_blocks($item['blocks'], $histogram);
            }
            continue;
        }
        if (isset($block['blocks'])) {
            grammar_count_blocks($block['blocks'], $histogram);
        }
        if ($type === 'table') {
            foreach ([$block['head'], ...$block['rows']] as $row) {
                foreach ($row as $cell) {
                    grammar_count_inlines($cell, $histogram);
                }
            }
        }
        if (isset($block['inlines'])) {
            grammar_count_inlines($block['inlines'], $histogram);
        }
    }
}

/**
 * @param list<array<string,mixed>> $inlines
 * @param array<string,int> $histogram
 */
function grammar_count_inlines(array $inlines, array &$histogram): void
{
    foreach ($inlines as $node) {
        $label = 'inline ' . $node['type'];
        $histogram[$label] = ($histogram[$label] ?? 0) + 1;
        if (isset($node['inlines'])) {
            grammar_count_inlines($node['inlines'], $histogram);
        }
    }
}

// ------------------------------------------------------------------ Code blocks

test('info string: lang, title and the inert rest as meta.raw', function (): void {
    assert_same(
        '{"type":"code_block","lang":"ts","meta":{"raw":" {1,3-4}","title":"highlight.ts"},"value":"const one = 1;"}',
        grammar_json(grammar_blocks("```ts title=\"highlight.ts\" {1,3-4}\nconst one = 1;\n```")[0]),
    );
});

test('tilde fence: lineNumbers start, boolean noCopy, backticks in content', function (): void {
    assert_same(
        '{"type":"code_block","lang":"js","meta":{"raw":"  {1}","lineNumbers":3,"noCopy":true},"value":"code with ``` inside"}',
        grammar_json(grammar_blocks("~~~js lineNumbers=3 noCopy {1}\ncode with ``` inside\n~~~")[0]),
    );
});

test('info string: escapes and character references are decoded, trailing space stays in meta.raw', function (): void {
    $info = grammar_blocks("``` ts\\_x  title=\"a&amp;b\"   \nv\n```")[0];
    assert_same(['ts_x', 'a&b', '   '], [$info['lang'], $info['meta']['title'], $info['meta']['raw']]);
});

test('code block details', function (): void {
    assert_same(['raw' => '', 'lineNumbers' => true], grammar_blocks("```php lineNumbers\n<?php\n```")[0]['meta']);
    assert_same("\tfunc()", grammar_blocks("```go\n\tfunc()\n```")[0]['value']);
    assert_same("```ts\nx\n```", grammar_blocks("````md\n```ts\nx\n```\n````")[0]['value']);
    assert_same(null, grammar_blocks("```\n  indented\n```")[0]['lang']);
    assert_same('paragraph', grammar_blocks("```ts`x\ny```")[0]['type'], 'a backtick in the info string is no fence');
});

test('fence in a list item keeps blank lines and removes the item indentation', function (): void {
    $list = grammar_blocks("- Item\n\n  ```php title=\"x.php\"\n  \$a = 1;\n\n  \$b = 2;\n  ```\n- two")[0];
    assert_same(2, count($list['items']));
    assert_same('{"type":"code_block","lang":"php","meta":{"raw":"","title":"x.php"},"value":"$a = 1;\n\n$b = 2;"}', grammar_json($list['items'][0]['blocks'][1]));
    assert_same(false, $list['tight']);
});

test('indented fence in a component removes only the fence indentation', function (): void {
    $tabs = grammar_blocks("<Tabs items=\"A\">\n  <Tab value=\"A\">\n    ```ts\n    x\n      y\n    ```\n  </Tab>\n</Tabs>")[0];
    assert_same("x\n  y", $tabs['blocks'][0]['blocks'][0]['value'] ?? null);
});

test('a closing tag inside a code fence does not end the component', function (): void {
    $callout = grammar_blocks("<Callout>\n```html\n</Callout>\n```\n</Callout>")[0];
    assert_same(['Callout', '</Callout>'], [$callout['name'], $callout['blocks'][0]['value'] ?? null]);
});

// ------------------------------------------------------------------ Code tabs

test('code tabs: consecutive tab blocks are grouped and equal names merged', function (): void {
    $blocks = grammar_blocks("```json tab=\"A\"\n1\n```\n\n```yaml tab=\"B\" tab-group=\"g\"\n2\n```\n\n```yaml tab=\"A\"\n3\n```\n\nText\n\n```ts tab=\"X\"\nsolo\n```");
    assert_same(3, count($blocks));
    assert_same(['code_tabs', 'A', null], [$blocks[0]['type'], $blocks[0]['defaultValue'], $blocks[0]['groupId']]);
    assert_same(['A', 'B'], array_column($blocks[0]['items'], 'value'));
    assert_same(['1', '3'], array_column($blocks[0]['items'][0]['blocks'], 'value'));
    assert_same(['raw' => ' ', 'tab' => 'B', 'tabGroup' => 'g'], $blocks[0]['items'][1]['blocks'][0]['meta']);
    assert_same(['paragraph', 'code_tabs', 1], [$blocks[1]['type'], $blocks[2]['type'], count($blocks[2]['items'])]);
});

test('code tabs: tab-group of the first tab becomes groupId; a bare tab does not group', function (): void {
    assert_same('language', grammar_blocks("```js tab=\"A\" tab-group=\"language\"\na\n```\n```js tab=\"B\"\nb\n```")[0]['groupId']);
    assert_same('code_block', grammar_blocks("```js tab\na\n```")[0]['type']);
});

test('code tabs inside <Tabs> become <Tab value> children', function (): void {
    $tab = grammar_blocks("<Tabs>\n```js tab=\"one\"\na\n```\n</Tabs>")[0]['blocks'][0];
    assert_same(['component', 'Tab', ['value' => 'one']], [$tab['type'], $tab['name'], $tab['attrs']]);
});

// ------------------------------------------------------------------ GFM inlines and blocks

test('strikethrough with ~~ and ~; three tildes and escaped tildes stay text', function (): void {
    assert_same(
        '[{"type":"text","value":"a "},{"type":"delete","inlines":[{"type":"text","value":"b"}]},{"type":"text","value":" c "},{"type":"delete","inlines":[{"type":"text","value":"d"}]},{"type":"text","value":" ~~~e~~~ ~~f~~"}]',
        grammar_json(grammar_blocks("a ~~b~~ c ~d~ ~~~e~~~ \\~~f~~")[0]['inlines']),
    );
    assert_same(
        '[{"type":"delete","inlines":[{"type":"text","value":"a~ b"}]},{"type":"text","value":" "},{"type":"strong","inlines":[{"type":"delete","inlines":[{"type":"text","value":"c"}]}]}]',
        grammar_json(grammar_blocks("~~a~ b~~ **~~c~~**")[0]['inlines']),
    );
});

test('task list items', function (): void {
    $list = grammar_blocks("- [x] Reference frozen\n- [ ] Generator built\n- [X] upper case\n- plain")[0];
    assert_same([true, false, true], array_map(static fn(array $item): ?bool => $item['checked'] ?? null, array_slice($list['items'], 0, 3)));
    assert_true(!array_key_exists('checked', $list['items'][3]));
    assert_same('Reference frozen', Markdown::plainText($list['items'][0]['blocks'][0]['inlines']));
    assert_true(grammar_error('- Text [x] in the middle') !== null, '[x] in the middle stays a forbidden bracket');
    assert_true(grammar_error('- [x]') !== null, '[x] without content is no task');
});

test('footnotes: case-insensitive identifiers, indented and lazy continuation', function (): void {
    assert_same(
        '[{"type":"paragraph","inlines":[{"type":"text","value":"Text"},{"type":"footnote_reference","label":"a","identifier":"A"},{"type":"text","value":" and"},{"type":"footnote_reference","label":"B","identifier":"B"},{"type":"text","value":"."}]},{"type":"footnote_definition","label":"a","identifier":"A","blocks":[{"type":"paragraph","inlines":[{"type":"text","value":"One\nsecond line lazy"}]},{"type":"paragraph","inlines":[{"type":"text","value":"Paragraph two"}]}]},{"type":"footnote_definition","label":"b","identifier":"B","blocks":[{"type":"paragraph","inlines":[{"type":"text","value":"Two"}]}]}]',
        grammar_json(grammar_blocks("Text[^a] and[^B].\n\n[^a]: One\n    second line lazy\n\n    Paragraph two\n[^b]: Two")),
    );
});

test('headings() appends "Footnotes" only when there are footnotes', function (): void {
    assert_same([['level' => 2, 'text' => 'Title'], ['level' => 2, 'text' => 'Footnotes']], Markdown::parse("## Title\n\nText[^1]\n\n[^1]: Note", 'unit')->headings());
    assert_same([['level' => 2, 'text' => 'Title']], Markdown::parse("## Title\n\nText", 'unit')->headings());
});

test('hard breaks with two spaces and with a backslash, none at the paragraph end', function (): void {
    assert_same(
        '[{"type":"text","value":"first"},{"type":"break"},{"type":"text","value":"second"},{"type":"break"},{"type":"text","value":"third"}]',
        grammar_json(grammar_blocks("first  \nsecond\\\nthird")[0]['inlines']),
    );
    assert_same('[{"type":"text","value":"at the end"}]', grammar_json(grammar_blocks('at the end  ')[0]['inlines']));
});

test('character references as in micromark', function (): void {
    assert_same("\u{FFFD} A \u{E4} &bogus; \u{FFFD} \u{FFFD}", grammar_blocks('&#0; &#x41; &auml; &bogus; &#128; &#1114112;')[0]['inlines'][0]['value']);
});

test('a backslash escapes only ASCII punctuation in link destination and title', function (): void {
    $inlines = grammar_blocks("[a](/x\\_y \"t\\\"\") [b](/p\\q)")[0]['inlines'];
    assert_same(['/x_y', 't"', '/p\\q'], [$inlines[0]['href'], $inlines[0]['title'], $inlines[2]['href']]);
});

test('unmatched delimiters stay text; URLs inside link text are allowed', function (): void {
    assert_same('[{"type":"text","value":"a* b and **c"}]', grammar_json(grammar_blocks('a* b and **c')[0]['inlines']));
    assert_same('See https://example.org', Markdown::plainText(grammar_blocks('[See https://example.org](https://example.org)')[0]['inlines']));
});

// ------------------------------------------------------------------ Component values

test('attribute values: lists with \\| and \\\\, bare booleans, integers', function (): void {
    $tabs = grammar_blocks("<Tabs groupId=\"os\" persist updateAnchor items=\"Windows|mac\\|OS|Li\\\\nux\" defaultIndex=\"1\" label=\"Systems\">\n<Tab value=\"Windows\">a</Tab>\n<Tab value=\"mac|OS\">b</Tab>\n<Tab>c</Tab>\n</Tabs>")[0];
    assert_same(['groupId' => 'os', 'persist' => true, 'updateAnchor' => true, 'items' => ['Windows', 'mac|OS', 'Li\\nux'], 'defaultIndex' => 1, 'label' => 'Systems'], $tabs['attrs']);
    assert_same(3, count($tabs['blocks']));
});

test('<Accordions type="multiple" defaultValue="a|b"> gives a list', function (): void {
    $accordions = grammar_blocks("<Accordions type=\"multiple\" defaultValue=\"a|b\">\n<Accordion title=\"A\" id=\"a\" value=\"a\">\nx\n</Accordion>\n</Accordions>")[0];
    assert_same(['type' => 'multiple', 'defaultValue' => ['a', 'b']], $accordions['attrs']);
    assert_same('a', $accordions['blocks'][0]['attrs']['value']);
});

test('<Files>: nested folders, file icon, empty self-closing folder', function (): void {
    $files = grammar_blocks("<Files>\n  <Folder name=\"a\" defaultOpen disabled=\"false\">\n    <File name=\"b.php\" icon=\"file-text\" />\n  </Folder>\n  <Folder name=\"empty\" />\n</Files>")[0];
    assert_same(['name' => 'a', 'defaultOpen' => true, 'disabled' => false], $files['blocks'][0]['attrs']);
    assert_same(['name' => 'b.php', 'icon' => 'file-text'], $files['blocks'][0]['blocks'][0]['attrs']);
    assert_same([], $files['blocks'][1]['blocks']);
});

test('<Callout icon> takes a lucide name', function (): void {
    assert_same(['type' => 'idea', 'title' => 'T', 'icon' => 'lightbulb'], grammar_blocks("<Callout type=\"idea\" title=\"T\" icon=\"lightbulb\">\nx\n</Callout>")[0]['attrs']);
});

test('<DynamicCodeBlock>: literal content, indentation up to the tag removed, one-line form, no Markdown', function (): void {
    $steps = grammar_blocks("<Steps>\n  <Step>\n    <DynamicCodeBlock lang=\"ts\">\n      const a = 1;\n        indented\n    </DynamicCodeBlock>\n  </Step>\n</Steps>")[0];
    assert_same("  const a = 1;\n    indented", $steps['blocks'][0]['blocks'][0]['value'] ?? null);
    assert_same('ls -la', grammar_blocks('<DynamicCodeBlock lang="sh">ls -la</DynamicCodeBlock>')[0]['value'] ?? null);
    assert_same(
        '{"type":"component_raw","name":"DynamicCodeBlock","attrs":{"lang":"md"},"value":"**not bold** [not](link)"}',
        grammar_json(grammar_blocks("<DynamicCodeBlock lang=\"md\">\n**not bold** [not](link)\n</DynamicCodeBlock>")[0]),
    );
});

test('a one-line child component nests correctly', function (): void {
    assert_same('Callout', grammar_blocks("<Accordions>\n<Accordion title=\"a\">\n<Callout>inside</Callout>\n</Accordion>\n</Accordions>")[0]['blocks'][0]['blocks'][0]['name']);
});

test('one-line components carry inline => true, multi-line ones do not', function (): void {
    $blocks = grammar_blocks("<Callout type=\"info\">A note of type `info`.</Callout>\n\n<Callout type=\"info\">\nMulti-line.\n</Callout>\n\n<Tabs items=\"A|B\">\n  <Tab value=\"A\">Short</Tab>\n  <Tab value=\"B\">\n    Long\n  </Tab>\n</Tabs>");
    assert_same(true, $blocks[0]['inline'] ?? null);
    assert_true(!array_key_exists('inline', $blocks[1]));
    assert_same(true, $blocks[2]['blocks'][0]['inline'] ?? null);
    assert_true(!array_key_exists('inline', $blocks[2]['blocks'][1]));
    assert_true(!array_key_exists('inline', $blocks[2]));
    assert_same('paragraph', $blocks[0]['blocks'][0]['type']);
});

// ------------------------------------------------------------------ Fail loud

$unknownAttribute = [
    'Callout' => "<Callout color=\"x\">\nt\n</Callout>",
    'Cards' => "<Cards color=\"x\">\n</Cards>",
    'Card' => '<Card title="a" color="x" />',
    'Screenshot' => '<Screenshot src="/a.webp" color="x" />',
    'Tabs' => "<Tabs color=\"x\">\n</Tabs>",
    'Tab' => "<Tabs>\n<Tab value=\"a\" color=\"x\">\nt\n</Tab>\n</Tabs>",
    'Accordions' => "<Accordions color=\"x\">\n</Accordions>",
    'Accordion' => "<Accordions>\n<Accordion title=\"a\" color=\"x\">\nt\n</Accordion>\n</Accordions>",
    'Steps' => "<Steps color=\"x\">\n</Steps>",
    'Step' => "<Steps>\n<Step color=\"x\">\nt\n</Step>\n</Steps>",
    'Files' => "<Files color=\"x\">\n</Files>",
    'Folder' => "<Files>\n<Folder name=\"a\" color=\"x\" />\n</Files>",
    'File' => "<Files>\n<File name=\"a\" color=\"x\" />\n</Files>",
    'TypeTable' => "<TypeTable color=\"x\">\n</TypeTable>",
    'TypeProp' => "<TypeTable>\n<TypeProp name=\"a\" type=\"string\" color=\"x\" />\n</TypeTable>",
    'Banner' => "<Banner color=\"x\">\nt\n</Banner>",
    'InlineTOC' => '<InlineTOC color="x" />',
    'ImageZoom' => '<ImageZoom src="/a.webp" color="x" />',
    'DynamicCodeBlock' => "<DynamicCodeBlock lang=\"ts\" color=\"x\">\nx\n</DynamicCodeBlock>",
];
foreach ($unknownAttribute as $component => $source) {
    test('fail loud: unknown attribute on <' . $component . '>', function () use ($component, $source): void {
        $message = (string) grammar_error($source);
        assert_true(str_starts_with($message, 'unit:'), $message);
        assert_contains('Unknown attribute "color" on <' . $component . '>', $message);
    });
}

$cases = [
    'empty items entry' => ["<Tabs items=\"A||B\">\n</Tabs>", 'empty entry'],
    'items ends with a separator' => ["<Tabs items=\"A|\">\n</Tabs>", 'empty entry'],
    'tab value not in items' => ["<Tabs items=\"A|B\">\n<Tab value=\"C\">x</Tab>\n</Tabs>", 'is not in items'],
    'tab without value and items' => ["<Tabs>\n<Tab>x</Tab>\n</Tabs>", 'has no value'],
    'defaultIndex out of range' => ["<Tabs items=\"A|B\" defaultIndex=\"2\">\n</Tabs>", 'is outside the 2 tabs'],
    'defaultIndex not a number' => ["<Tabs items=\"A|B\" defaultIndex=\"one\">\n</Tabs>", 'non-negative integer'],
    'unknown icon on Card' => ['<Card title="a" icon="no-such-icon" />', 'Unknown lucide icon "no-such-icon"'],
    'unknown icon on Callout' => ["<Callout icon=\"BookOpen\">\nx\n</Callout>", 'Unknown lucide icon "BookOpen"'],
    'unknown icon on File' => ["<Files>\n<File name=\"a\" icon=\"file-php\" />\n</Files>", 'Unknown lucide icon "file-php"'],
    'JSX expression items' => ["<Tabs items={['Windows', 'macOS']}>\n</Tabs>", 'items="A|B|C"'],
    'JSX expression icon' => ['<Card icon={<BookOpen />} title="a" />', 'icon="book-open"'],
    'JSX expression number' => ['<ImageZoom src="/a.webp" width={256} />', 'width="1"'],
    'JSX expression boolean' => ["<Banner changeLayout={false}>\nx\n</Banner>", 'changeLayout="false"'],
    'JSX expression TypeTable' => ["<TypeTable\n  type={{\n    a: { type: 'string' },\n  }}\n/>", '<TypeProp'],
    'JSX expression InlineTOC' => ['<InlineTOC items={toc} />', '<InlineTOC />'],
    'JSX expression DynamicCodeBlock' => ['<DynamicCodeBlock lang="ts" code={`x`} />', 'code as content'],
    'import line' => ["import { Tabs } from 'some-ui/components/tabs';\n\nText", 'no MDX imports'],
    'export line' => ['export const meta = {};', 'no MDX imports'],
    'boolean with a wrong value' => ["<Banner changeLayout=\"no\">\nx\n</Banner>", '"true" or "false"'],
    'string without a value' => ["<Accordions>\n<Accordion title>\nx\n</Accordion>\n</Accordions>", 'needs a value'],
    'single quotes' => ["<Tabs groupId='os'>\n</Tabs>", 'double quotes'],
    'accordions type' => ["<Accordions type=\"all\">\n</Accordions>", 'single, multiple'],
    'single accordions with two defaultValue entries' => ["<Accordions type=\"single\" defaultValue=\"a|b\">\n</Accordions>", 'only one defaultValue'],
    'banner variant' => ["<Banner variant=\"colourful\">\nx\n</Banner>", 'normal, rainbow'],
    'required TypeProp.type' => ["<TypeTable>\n<TypeProp name=\"a\" />\n</TypeTable>", 'needs the attribute "type"'],
    'duplicate TypeProp' => ["<TypeTable>\n<TypeProp name=\"a\" type=\"x\" />\n<TypeProp name=\"a\" type=\"y\" />\n</TypeTable>", 'appears twice'],
    'Tab outside Tabs' => ["<Tab value=\"a\">\nx\n</Tab>", 'directly inside <Tabs>'],
    'Step outside Steps' => ["<Step>\nx\n</Step>", 'directly inside <Steps>'],
    'File outside Files' => ['<File name="a" />', 'directly inside <Files> or <Folder>'],
    'text in Steps' => ["<Steps>\nText\n</Steps>", 'may only contain <Step>'],
    'text in TypeTable' => ["<TypeTable>\nText\n</TypeTable>", 'may only contain <TypeProp>'],
    'InlineTOC not self-closing' => ["<InlineTOC>\nx\n</InlineTOC>", 'self-closing'],
    'DynamicCodeBlock without content' => ['<DynamicCodeBlock lang="ts" />', 'code as content'],
    'DynamicCodeBlock never closed' => ["<DynamicCodeBlock lang=\"ts\">\nx", 'never closed with </DynamicCodeBlock>'],
    'footnote without definition' => ['Text[^missing]', 'without a definition'],
    'footnote never referenced' => ["Text\n\n[^free]: never", '[^free] is never referenced'],
    'footnote defined twice' => ["Text[^a]\n\n[^a]: one\n\n[^A]: two", 'defined twice'],
    'bare URL' => ['see https://example.org', 'autolink literal'],
    'bare www address' => ['see www.example.org', 'autolink literal'],
    'bare email address' => ['write to info@example.org', 'mailto'],
    'unclosed fence' => ["```ts\nconst a = 1;", 'never closed with ```'],
    'fence ended by a list item' => ["- Item\n  ```ts\nx\n- two", 'never closed with ```'],
];
foreach ($cases as $label => [$source, $needle]) {
    test('fail loud: ' . $label, function () use ($source, $needle): void {
        $message = grammar_error($source);
        assert_true($message !== null, 'no error thrown');
        assert_contains($needle, (string) $message);
    });
}

test('unknown icon: location, at most five closest names and the lucide link', function (): void {
    $message = (string) grammar_error("Text\n\n<Card title=\"a\" icon=\"bok-open\" />");
    assert_true(str_starts_with($message, 'unit:3: Unknown lucide icon "bok-open"'), $message);
    assert_same(1, preg_match('/did you mean: ([^.]+)\./', $message, $match));
    $suggested = array_map('trim', explode(',', $match[1]));
    assert_true(count($suggested) <= 5 && $suggested[0] === 'book-open', $message);
    assert_contains('https://lucide.dev/icons', $message);
    assert_true(strlen($message) < 400);
});

test('errors of the component rules name file and line', function (): void {
    assert_true(str_starts_with((string) grammar_error("Paragraph\n\n<Tabs items={['a']}>\n</Tabs>"), 'unit:3:'));
});

// ------------------------------------------------------------------ Demo corpus

$demo = dirname(__DIR__) . '/examples/demo/content';

test('the demo uses every construct of the grammar', function () use ($demo): void {
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($demo, FilesystemIterator::SKIP_DOTS)) as $entry) {
        if ($entry->getExtension() === 'md') {
            $files[] = $entry->getPathname();
        }
    }
    sort($files);
    assert_true($files !== [], 'no pages under ' . $demo);

    $histogram = [];
    foreach ($files as $file) {
        grammar_count_blocks(Markdown::parse((string) file_get_contents($file), $file)->blocks, $histogram);
    }
    $required = [
        'heading h2', 'heading h3', 'heading h4', 'paragraph', 'blockquote', 'thematic_break', 'table',
        'list bullet', 'list ordered', 'list item task',
        'code_block', 'code_block meta title', 'code_block meta lineNumbers', 'code_block meta tab', 'code_block meta raw',
        'code_tabs', 'footnote_definition',
        'inline strong', 'inline emphasis', 'inline delete', 'inline code', 'inline link', 'inline image',
        'inline break', 'inline footnote_reference',
        'component <Callout>', 'component <Cards>', 'component_void <Card>', 'component_void <Screenshot>',
        'component <Tabs>', 'component <Tab>', 'component <Accordions>', 'component <Accordion>',
        'component <Steps>', 'component <Step>', 'component <Files>', 'component <Folder>', 'component_void <File>',
        'component <TypeTable>', 'component <TypeProp>', 'component <Banner>', 'component_void <InlineTOC>',
        'component_void <ImageZoom>', 'component_raw <DynamicCodeBlock>',
        'attr Card.icon (string)', 'attr Tabs.items (list)', 'attr Tabs.persist (bool)', 'attr Tabs.updateAnchor (bool)',
        'attr Tabs.defaultIndex (int)', 'attr Tabs.groupId (string)', 'attr Accordions.type (string)', 'attr Accordion.id (string)',
        'attr Folder.defaultOpen (bool)', 'attr TypeProp.required (bool)', 'attr TypeProp.deprecated (bool)',
        'attr TypeProp.default (string)', 'attr TypeProp.typeDescription (string)', 'attr Banner.changeLayout (bool)',
        'attr Banner.variant (string)', 'attr ImageZoom.width (int)', 'attr DynamicCodeBlock.lang (string)',
    ];
    $missing = array_values(array_filter($required, static fn(string $label): bool => !isset($histogram[$label])));
    assert_same([], $missing, 'constructs missing from examples/demo/content (add demo content, not exceptions)');
});

test('the demo AST equals tests/fixtures/demo-ast.json', function () use ($demo): void {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/tools/dump-ast.php')
        . ' --check ' . escapeshellarg($demo) . ' ' . escapeshellarg(__DIR__ . '/fixtures/demo-ast.json') . ' 2>&1';
    exec($command, $output, $exitCode);
    assert_same(0, $exitCode, implode("\n", $output));
});
