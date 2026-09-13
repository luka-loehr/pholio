<?php

declare(strict_types=1);

// Rendering: the article body rewrites, the configurable shell pieces (head, nav
// title, hero, cards, last updated line, footnote labels), the renamed collapsible
// panel class, and a demo build that is byte-identical across two runs.

require __DIR__ . '/run.php';
require_once dirname(__DIR__) . '/src/Config.php';
require_once dirname(__DIR__) . '/src/Builder.php';
require_once dirname(__DIR__) . '/src/lib/Render.php';
foreach ([...glob(dirname(__DIR__) . '/src/components/*.php'), dirname(__DIR__) . '/src/templates/document.php'] as $file) {
    require_once $file;
}

use Pholio\Builder;
use Pholio\Config;
use Pholio\I18n;
use Pholio\Markdown;
use Pholio\Render;
use Pholio\RenderContext;
use Pholio\Tree;

const RENDER_DEMO = __DIR__ . '/../examples/demo';

/** A normalised config from a partial public config; paths resolve against the demo. */
function render_config(array $raw): array
{
    return Config::fromArray($raw + [
        'title' => 'Site',
        'content_dir' => 'content',
        'output_dir' => 'out',
    ], RENDER_DEMO);
}

function render_body(string $markdown, RenderContext $ctx): string
{
    return Render::body(Markdown::parse($markdown, 'test.md'), $ctx);
}

/** A fresh temp directory, removed when the test file ends. */
function render_temp_dir(): string
{
    $dir = sys_get_temp_dir() . '/pholio-render-test-' . bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    register_shutdown_function(static function () use ($dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    });

    return $dir;
}

/** @return array<string, string> relative path => sha1 of every file below $dir */
function render_hashes(string $dir): array
{
    $out = [];
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
        $out[substr($item->getPathname(), strlen($dir) + 1)] = sha1_file($item->getPathname());
    }
    ksort($out);

    return $out;
}

I18n::use('en');

// ------------------------------------------------------------------ body

test('prefix rewrite only at a path boundary, and the site root does not double the slash', function (): void {
    assert_same('/docs/a', RenderContext::replacePrefix('/ref/a', '/ref', '/docs'));
    assert_same('/docs#x', RenderContext::replacePrefix('/ref#x', '/ref', '/docs'));
    assert_same('/docs', RenderContext::replacePrefix('/ref', '/ref', '/docs'));
    assert_same('/reference/a', RenderContext::replacePrefix('/reference/a', '/ref', '/docs'));
    assert_same('/a', RenderContext::replacePrefix('/ref/a', '/ref', '/'));
    assert_same('/#x', RenderContext::replacePrefix('/ref#x', '/ref', '/'));
});

test('without prefixes links and images stay as written', function (): void {
    $html = render_body("[a](/ref/x) ![i](/img/y.png)\n", RenderContext::create(baseUrl: '/docs'));
    assert_contains('href="/ref/x"', $html);
    assert_contains('src="/img/y.png"', $html);
});

test('link prefix maps to the docs root, image prefix to the asset target', function (): void {
    $ctx = RenderContext::create(baseUrl: '/docs', assetPrefix: '/media', assetTarget: '/static', linkPrefix: '/ref');
    $html = render_body("[a](/ref/x) [b](https://example.com/ref/x) ![i](/media/y.png)\n", $ctx);
    assert_contains('href="/docs/x"', $html);
    assert_contains('href="https://example.com/ref/x" rel="noreferrer noopener" target="_blank"', $html);
    assert_contains('src="/static/y.png"', $html);
});

test('the heading copy label defaults to the translation of the current language', function (): void {
    assert_contains('aria-label="Copy Anchor Link"', render_body("## Title\n", RenderContext::create()));
    I18n::use('de');
    try {
        assert_contains('aria-label="' . I18n::t('Copy Anchor Link(heading anchor)(aria-label)') . '"', render_body("## Title\n", RenderContext::create()));
    } finally {
        I18n::use('en');
    }
});

test('footnote back links use the translated label', function (): void {
    $html = render_body("Text[^1]\n\n[^1]: Note\n", RenderContext::create());
    assert_contains('aria-label="Back to reference 1"', $html);
    I18n::use('en', ['Back to reference(footnote)(aria-label)' => 'Jump back']);
    try {
        assert_contains('aria-label="Jump back 1"', render_body("Text[^1]\n\n[^1]: Note\n", RenderContext::create()));
    } finally {
        I18n::use('en');
    }
});

// ------------------------------------------------------------------ rename

test('closed and open collapsible panels carry nd-collapsible-panel, and no source names the old class', function (): void {
    assert_contains('class="nd-collapsible-panel"', Pholio\nd_folder(['name' => 'src'], ''));
    assert_contains('class="nd-collapsible-panel"', Pholio\nd_folder(['name' => 'src', 'defaultOpen' => true], ''));
    assert_contains('class="nd-collapsible-panel"', Pholio\nd_inline_toc('Contents', []));
    assert_contains('class="nd-collapsible-panel"', Pholio\nd_type_prop(['name' => 'a', 'type' => 'string'], '', ['type' => 'Type', 'default' => 'Default']));
    assert_contains('class="nd-collapsible-panel"', Pholio\nd_toc_popover([['depth' => 2, 'title' => 'A', 'url' => '#a']], 'Page'));

    $old = 'nd-tocpop' . '-panel';
    $hits = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_contains((string) file_get_contents($file->getPathname()), $old)) {
            $hits[] = $file->getPathname();
        }
    }
    assert_same([], $hits);
});

// ------------------------------------------------------------------ page and shell

test('last updated line: translated label, two text nodes, omitted without a value', function (): void {
    $page = ['title' => 'T', 'description' => null, 'updated' => '2026-01-02', 'full' => false];
    assert_contains('<p class="nd-page-stand">Last updated: <!---->2026-01-02</p>', Pholio\nd_page($page, [], '', []));
    I18n::use('de');
    try {
        assert_contains('<p class="nd-page-stand">' . I18n::t('Last updated(page)') . '<!---->2026-01-02</p>', Pholio\nd_page($page, [], '', []));
    } finally {
        I18n::use('en');
    }
    assert_true(!str_contains(Pholio\nd_page(['updated' => null] + $page, [], '', []), 'nd-page-stand'));
});

test('document head: favicons, manifest and theme colour only when configured', function (): void {
    $head = [
        'lang' => 'en', 'fontClass' => '', 'title' => 'T', 'description' => null,
        'scrollArea' => false, 'assetBase' => '/assets', 'baseUrl' => '/', 'searchIndexUrl' => '/search-index.json',
        'icons' => [], 'manifest' => null, 'themeColor' => null,
    ];
    $bare = Pholio\nd_document($head, '');
    assert_contains('<html lang="en" class="light" style="color-scheme: light;">', $bare);
    assert_contains('<meta name="nd-search-index" content="/search-index.json"></head>', $bare);

    $full = Pholio\nd_document([
        'icons' => [
            ['rel' => 'icon', 'type' => 'image/png', 'sizes' => '32x32', 'href' => '/assets/favicon-32x32.png'],
            ['rel' => 'apple-touch-icon', 'type' => null, 'sizes' => '180x180', 'href' => '/assets/touch.png'],
        ],
        'manifest' => '/site.webmanifest',
        'themeColor' => '#123456',
    ] + $head, '');
    assert_contains('<html lang="en" class="light" style="color-scheme: light;">', $full);
    assert_contains(
        '<meta name="nd-search-index" content="/search-index.json">'
        . '<link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png">'
        . '<link rel="apple-touch-icon" sizes="180x180" href="/assets/touch.png">'
        . '<link rel="manifest" href="/site.webmanifest">'
        . '<meta name="theme-color" content="#123456"></head>',
        $full
    );
});

test('nav title prints the logo only when one is configured', function (): void {
    assert_true(!str_contains(Pholio\nd_nav_title(render_config([])), '<img'));
    assert_contains('<img src="/logo.svg" alt="" width="32" height="32" class="nd-nav-logo">', Pholio\nd_nav_title(render_config(['logo' => '/logo.svg', 'logo_size' => 32])));
});

test('nav links: exact matches the URL, prefix also the pages below', function (): void {
    $config = render_config(['nav' => [
        ['title' => 'Guide', 'href' => '/guide/', 'active' => 'prefix'],
        ['title' => 'Home', 'href' => '/'],
        ['title' => 'Out', 'href' => 'https://example.com', 'external' => true],
    ]]);
    $html = Pholio\nd_header($config, '/guide/install', [], 0);
    assert_contains('data-active="true" href="/guide/">Guide</a>', $html);
    assert_contains('data-active="false" href="/">Home</a>', $html);
    assert_contains('href="https://example.com" target="_blank" rel="noreferrer noopener">Out</a>', $html);
});

test('hero: lines, optional images and icon, button order per variant', function (): void {
    $config = render_config(['home' => ['hero' => [
        'kicker' => 'K',
        'headline' => "One\nTwo & three",
        'buttons' => [
            ['label' => 'Start', 'href' => '/start', 'icon' => 'arrow-right'],
            ['label' => 'Read', 'href' => '/read', 'variant' => 'secondary', 'icon' => 'file-text'],
            ['label' => 'Plain', 'href' => '/plain', 'variant' => 'secondary'],
        ],
    ]]]);
    $html = Pholio\nd_home_hero($config);
    assert_contains('<h1 class="nd-hero-title">One<br>Two &amp; three</h1>', $html);
    assert_true(!str_contains($html, '<img'), 'no image and no icon configured');
    assert_same(1, preg_match('#<a href="/start" class="nd-hero-btn nd-hero-btn-primary">Start <svg[^>]*lucide-arrow-right#', $html));
    assert_same(1, preg_match('#<a href="/read" class="nd-hero-btn nd-hero-btn-secondary"><svg[^>]*lucide-file-text.*?</svg> Read</a>#', $html));
    assert_contains('<a href="/plain" class="nd-hero-btn nd-hero-btn-secondary">Plain</a>', $html);

    $images = Pholio\nd_home_hero(render_config(['home' => ['hero' => [
        'headline' => 'H', 'image' => '/l.png', 'image_dark' => '/d.png', 'icon' => '/i.png', 'icon_size' => 40,
    ]]]));
    assert_contains('<img src="/l.png" alt="" class="nd-hero-img nd-hero-img-light"><img src="/d.png" alt="" class="nd-hero-img nd-hero-img-dark">', $images);
    assert_contains('<img src="/i.png" alt="" width="40" height="40" class="nd-hero-icon">', $images);
});

test('cards from the tree: one per root folder with its meta.json title, description and icon', function (): void {
    $config = render_config(['base_path' => '/', 'home' => ['cards' => ['title' => 'Start here', 'from_tree' => true]]]);
    $tree = new Tree(RENDER_DEMO . '/content', $config['baseUrl'], false, ['md']);
    assert_same([
        ['title' => 'Guide', 'description' => 'Install, write and structure a documentation site.', 'href' => '/guide', 'icon' => 'book-open'],
        ['title' => 'Reference', 'description' => 'Code blocks, typed APIs and the Markdown extras.', 'href' => '/reference', 'icon' => 'library'],
    ], Pholio\nd_home_cards_from_tree($tree, $config['baseUrl']));

    $html = Pholio\nd_home_cards($config, $tree);
    assert_contains('<h2 class="nd-home-kicker">Start here</h2>', $html);
    assert_contains('<span class="nd-home-card-more">Open <svg', $html);
    assert_same(2, substr_count($html, 'class="nd-home-card"'));
});

test('explicit cards keep their order, accept React icon names and a custom link label', function (): void {
    $config = render_config(['home' => ['cards' => ['link_label' => 'Go', 'items' => [
        ['title' => 'B', 'description' => 'b', 'href' => '/b', 'icon' => 'ShieldCheck'],
        ['title' => 'A', 'href' => '/a'],
    ]]]]);
    $html = Pholio\nd_home_cards($config, new Tree(RENDER_DEMO . '/content', '/', false, ['md']));
    assert_same(1, preg_match('#href="/b" class="nd-home-card"><svg[^>]*lucide-shield-check.*href="/a" class="nd-home-card"><div class="nd-strong">A</div>#s', $html));
    assert_contains('<span class="nd-home-card-more">Go <svg', $html);
});

test('home layout leaves out hero and cards that are not configured', function (): void {
    $config = render_config(['home' => ['cards' => ['items' => [['title' => 'A', 'href' => '/a']]]]]);
    $html = Pholio\nd_home_layout($config, new Tree(RENDER_DEMO . '/content', '/', false, ['md']));
    assert_true(!str_contains($html, 'nd-hero'));
    assert_contains('<main class="nd-home-main"><section class="nd-home-section">', $html);
});

// ------------------------------------------------------------------ content errors

test('an unknown code fence language is a content error: exit 3 with file:line', function (): void {
    $dir = render_temp_dir();
    mkdir($dir . '/content', 0777, true);
    file_put_contents($dir . '/content/index.md', "---\ntitle: Start\n---\n\nText\n\n```sh\nok\n```\n\n```nosuchlang title=\"x\"\na\n```\n");
    file_put_contents($dir . '/pholio.config.php', "<?php\nreturn ['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out'];\n");

    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/bin/pholio', 'build', '--config', $dir . '/pholio.config.php', '--quiet'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    $code = proc_close($process);

    assert_same(3, $code, $err);
    assert_contains('pholio: ' . $dir . '/content/index.md:11: Language `nosuchlang` not found', $err);
});

test('an unknown DynamicCodeBlock language points at its tag', function (): void {
    $dir = render_temp_dir();
    $file = $dir . '/page.md';
    file_put_contents($file, "Text\n\n<DynamicCodeBlock lang=\"nosuchlang\">\na\n</DynamicCodeBlock>\n");
    $error = assert_throws(Pholio\ContentException::class, static fn() => Render::body(
        Markdown::parse((string) file_get_contents($file), $file),
        RenderContext::create()
    ), 'Language `nosuchlang` not found');
    assert_same(3, $error->exitCode());
    assert_same([$file, 3], [$error->sourceFile, $error->sourceLine]);
});

// ------------------------------------------------------------------ demo build

test('every demo page renders byte-identically in two builds', function (): void {
    $config = Config::load(RENDER_DEMO . '/pholio.config.php');
    $hashes = [];
    foreach (['first', 'second'] as $run) {
        $target = render_temp_dir();
        (new Builder($config))->build($target);
        $hashes[$run] = render_hashes($target);
    }

    $pages = array_values(array_filter(array_keys($hashes['first']), static fn(string $p): bool => str_ends_with($p, 'index.html')));
    assert_same(count((new Tree($config['contentDir'], $config['baseUrl'], false, $config['content']['extensions']))->pages()) + ($config['home'] === null ? 0 : 1), count($pages));
    assert_same($hashes['first'], $hashes['second']);
});
