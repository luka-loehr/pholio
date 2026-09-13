<?php

declare(strict_types=1);

require __DIR__ . '/run.php';
require __DIR__ . '/../src/Config.php';

use Pholio\Config;
use Pholio\ConfigException;

function config(array $raw = [], ?string $profile = null, array $overrides = []): array
{
    return Config::fromArray(
        $raw + ['title' => 'Lanternfly', 'content_dir' => 'content', 'output_dir' => 'out'],
        '/site',
        '/site/pholio.config.php',
        $profile,
        $overrides,
    );
}

function config_error(array $raw, string $contains, ?string $profile = null, array $overrides = []): void
{
    $e = assert_throws(ConfigException::class, fn() => config($raw, $profile, $overrides), $contains);
    assert_same(2, $e->exitCode());
}

test('defaults for a minimal config', function (): void {
    $c = config();
    assert_same('/', $c['homeUrl']);
    assert_same('/', $c['baseUrl']);
    assert_same('/assets', $c['assetBase']);
    assert_same('/site/content', $c['contentDir']);
    assert_same('/site/out', $c['outDir']);
    assert_same('en', $c['lang']);
    assert_same('{title} – Lanternfly', $c['titleTemplate']);
    assert_same('Lanternfly', $c['homeTitle']);
    assert_same(['title' => 'Lanternfly', 'logo' => null, 'logoSize' => 24, 'url' => '/'], $c['nav']);
    assert_same(['md'], $c['content']['extensions']);
    assert_same(null, $c['content']['linkPrefix']);
    assert_same(null, $c['content']['assetPrefix']);
    assert_same('/', $c['content']['assetTarget']);
    assert_same(null, $c['home']);
    assert_same(null, $c['theme']['preset']);
    assert_same('', $c['theme']['fontClass']);
    assert_same('d', $c['theme']['hotkey']);
    assert_same(['icons' => [], 'manifest' => null, 'themeColor' => null], $c['head']);
    assert_same(
        ['enabled' => true, 'indexPath' => 'search-index.json', 'indexUrl' => '/search-index.json', 'tokenizer' => 'english', 'hotkey' => ['⌘', 'K']],
        $c['search'],
    );
    assert_same(['htaccess' => true, 'csp' => Config::DEFAULT_CSP], $c['server']);
    assert_true(!str_contains(Config::DEFAULT_CSP, 'vimeo'));
    assert_same([], $c['redirects']);
    assert_same([], $c['keep']);
});

test('URL paths are normalised and placeholders resolved', function (): void {
    $c = config([
        'base_path' => '/docs/',
        'docs_path' => '/docs/manual/',
        'logo' => '{assets}/logo.svg',
        'nav' => [
            ['title' => 'Home', 'href' => '{home}'],
            ['title' => 'Guide', 'href' => '{docs}/guide', 'active' => 'prefix'],
            ['title' => 'Paper', 'href' => '/paper.pdf', 'external' => true],
        ],
        'home' => [
            'title' => '{site} – Docs',
            'hero' => ['headline' => "One\nTwo", 'image' => '{assets}/brand/hero.webp', 'buttons' => [
                ['label' => 'Start', 'href' => '{docs}/intro', 'icon' => 'arrow-right'],
                ['label' => 'Paper', 'href' => '/paper.pdf', 'variant' => 'secondary', 'icon' => 'file-text'],
            ]],
            'cards' => ['title' => 'Where do I start?', 'items' => [['title' => 'Guide', 'href' => '{docs}/guide']]],
        ],
        'head' => ['icons' => [['rel' => 'icon', 'href' => '{assets}/favicon.png']], 'manifest' => '{home}/site.webmanifest'],
    ]);
    assert_same('/docs', $c['homeUrl']);
    assert_same('/docs/manual', $c['baseUrl']);
    assert_same('/docs/assets', $c['assetBase']);
    assert_same('/docs/assets/logo.svg', $c['nav']['logo']);
    assert_same('/docs', $c['nav']['url']);
    assert_same([
        ['title' => 'Home', 'href' => '/docs', 'active' => 'exact', 'external' => false],
        ['title' => 'Guide', 'href' => '/docs/manual/guide', 'active' => 'prefix', 'external' => false],
        ['title' => 'Paper', 'href' => '/paper.pdf', 'active' => 'exact', 'external' => true],
    ], $c['links']);
    assert_same('Lanternfly – Docs', $c['homeTitle']);
    assert_same("One\nTwo", $c['home']['hero']['headline']);
    assert_same('/docs/assets/brand/hero.webp', $c['home']['hero']['image']);
    assert_same(null, $c['home']['hero']['imageDark']);
    assert_same(48, $c['home']['hero']['iconSize']);
    assert_same(['label' => 'Start', 'href' => '/docs/manual/intro', 'variant' => 'primary', 'icon' => 'arrow-right'], $c['home']['hero']['buttons'][0]);
    assert_same('Open', $c['home']['cards']['linkLabel']);
    assert_same(false, $c['home']['cards']['fromTree']);
    assert_same(['title' => 'Guide', 'description' => '', 'href' => '/docs/manual/guide', 'icon' => null], $c['home']['cards']['items'][0]);
    assert_same([['rel' => 'icon', 'type' => null, 'sizes' => null, 'href' => '/docs/assets/favicon.png']], $c['head']['icons']);
    assert_same('/docs/site.webmanifest', $c['head']['manifest']);
    assert_same('/docs/manual/search-index.json', $c['search']['indexUrl']);
});

test('absolute and scheme asset bases are kept', function (): void {
    assert_same('/static', config(['base_path' => '/docs', 'asset_base' => '/static/'])['assetBase']);
    assert_same('https://cdn.example.org/a', config(['asset_base' => 'https://cdn.example.org/a/'])['assetBase']);
});

test('paths resolve against the config directory', function (): void {
    $c = config([
        'content_dir' => '/abs/content',
        'output_dir' => '../public/docs/',
        'content' => ['asset_root' => 'media', 'asset_prefix' => '/media-assets/', 'link_prefix' => '/reference/docs/'],
        'copy' => ['brand' => '{assets}/brand'],
        'theme' => ['palette_css' => 'palette.css'],
        'redirects_file' => 'content/redirects.json',
        'output' => ['keep' => ['/assets/images/', 'site.webmanifest']],
    ]);
    assert_same('/abs/content', $c['contentDir']);
    assert_same('/site/../public/docs', $c['outDir']);
    assert_same('/site/media', $c['content']['assetRoot']);
    assert_same('/media-assets', $c['content']['assetPrefix']);
    assert_same('/reference/docs', $c['content']['linkPrefix']);
    assert_same([['from' => '/site/brand', 'to' => '/assets/brand']], $c['copy']);
    assert_same('/site/palette.css', $c['theme']['paletteCss']);
    assert_same('/site/content/redirects.json', $c['redirectsFile']);
    assert_same(['assets/images', 'site.webmanifest'], $c['keep']);
});

test('redirects accept a map and a list', function (): void {
    assert_same([['from' => '/old', 'to' => '/new', 'reason' => null]], config(['redirects' => ['/old' => '/new']])['redirects']);
    assert_same(
        [['from' => '/a', 'to' => '/b', 'reason' => 'renamed']],
        config(['redirects' => [['from' => '/a', 'to' => '/b', 'reason' => 'renamed']]])['redirects'],
    );
    config_error(['redirects' => [['from' => '/a']]], 'missing required key: redirects.0.to');
});

test('language drives tokenizer and translations', function (): void {
    $c = config(['language' => 'de', 'home' => ['cards' => []]]);
    assert_same('german', $c['search']['tokenizer']);
    assert_same('Öffnen', $c['home']['cards']['linkLabel']);
    assert_same('english', config(['language' => 'de', 'search' => ['tokenizer' => 'english']])['search']['tokenizer']);
    $c = config(['translations' => ['Open(home card)' => 'Read more'], 'home' => ['cards' => []]]);
    assert_same('Read more', $c['home']['cards']['linkLabel']);
    \Pholio\I18n::use('en');
});

test('unknown keys fail with their path', function (): void {
    config_error(['serch' => []], 'unknown key: serch (did you mean "search"?)');
    config_error(['search' => ['tokeniser' => 'german']], 'unknown key: search.tokeniser');
    config_error(['nav' => [['title' => 'a', 'href' => '/', 'url' => '/']]], 'unknown key: nav.0.url');
    config_error(['home' => ['hero' => ['headline' => 'x', 'appIcon' => 'x']]], 'unknown key: home.hero.appIcon');
});

test('unknown translation key fails', function (): void {
    config_error(['translations' => ['Serch(search trigger)' => 'x']], 'translations: unknown key');
    config_error(['language' => 'fr'], 'no shipped translation');
});

test('types, enums and required keys are validated', function (): void {
    config_error(['logo_size' => '24'], 'logo_size: expected an integer, got "24"');
    config_error(['nav' => [['title' => 'a', 'href' => '/', 'active' => 'nested-url']]], 'nav.0.active: expected one of exact, prefix');
    config_error(['search' => ['tokenizer' => 'french']], 'search.tokenizer: expected one of english, german');
    config_error(['content' => ['extensions' => ['.md']]], 'content.extensions');
    config_error(['base_path' => 'docs'], 'base_path: expected an absolute URL path');
    config_error(['server' => ['csp' => 'default-src "self"']], 'server.csp');
    config_error(['docs_root_suffix' => 'overview'], 'docs_root_suffix');
    $e = assert_throws(ConfigException::class, fn() => Config::fromArray(['title' => 'x', 'output_dir' => 'o'], '/site'), 'missing required key: content_dir');
    assert_same(null, $e->sourceFile);
});

test('planned keys fail with "planned:"', function (): void {
    config_error(['base_url' => 'https://example.org'], 'planned: base_url');
    config_error(['theme' => ['default_scheme' => 'dark']], 'planned: theme.default_scheme');
    config_error(['theme' => ['custom_css' => 'x.css']], 'planned: theme.custom_css');
    config_error(['search' => ['enabled' => false]], 'planned: search.enabled');
    config_error(['nav' => [['title' => 'a', 'href' => '/', 'icon' => 'github']]], 'planned: nav.0.icon');
    config_error(['nav' => [['title' => 'a', 'href' => '/', 'icon_only' => true]]], 'planned: nav.0.icon_only');
    config_error(['slots' => ['nav' => 'x.php']], 'planned: slots');
    config_error(['components' => ['Download' => 'x']], 'planned: components');
    config_error(['strict_content' => true], 'planned: strict_content');
    // Default values of planned keys are accepted.
    config(['base_url' => null, 'theme' => ['default_scheme' => 'system'], 'slots' => [], 'strict_content' => false]);
});

test('profiles merge recursively and unknown profiles fail', function (): void {
    $raw = [
        'base_path' => '/docs',
        'nav' => [['title' => 'Docs', 'href' => '/docs'], ['title' => 'Paper', 'href' => '/paper.pdf', 'external' => true]],
        'profiles' => [
            'preview' => ['base_path' => '/preview', 'title_template' => '{title} – {site} (preview)', 'nav' => [1 => ['href' => 'https://example.org/paper.pdf']]],
        ],
    ];
    $c = config($raw, 'preview');
    assert_same('preview', $c['profile']);
    assert_same('/preview', $c['homeUrl']);
    assert_same('{title} – Lanternfly (preview)', $c['titleTemplate']);
    assert_same('https://example.org/paper.pdf', $c['links'][1]['href']);
    assert_same(true, $c['links'][1]['external']);
    assert_same('/docs', config($raw)['homeUrl']);
    config_error($raw, 'unknown profile "live" (known: preview)', 'live');
    config_error(['profiles' => ['x' => ['serch' => []]]], 'unknown key: profiles.x.serch');
});

test('overrides from the command line', function (): void {
    $c = config([], null, ['output_dir' => '/tmp/out', 'search.tokenizer' => 'german', 'theme.preset' => 'ocean']);
    assert_same('/tmp/out', $c['outDir']);
    assert_same('german', $c['search']['tokenizer']);
    assert_same('ocean', $c['theme']['preset']);
    config_error([], '--set nope: unknown key', null, ['nope' => 'x']);
    config_error([], 'only string keys', null, ['logo_size' => '3']);
    config_error([], 'search.tokenizer: expected one of', null, ['search.tokenizer' => 'klingon']);
});

test('load reads a file and reports a missing one', function (): void {
    $dir = sys_get_temp_dir() . '/pholio-config-test-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir . '/pholio.config.php', "<?php return ['title' => 'T', 'content_dir' => 'content', 'output_dir' => 'out'];");
    file_put_contents($dir . '/broken.php', "<?php return 'nope';");
    try {
        $c = Config::load($dir . '/pholio.config.php');
        assert_same($dir . '/content', $c['contentDir']);
        assert_same($dir . '/pholio.config.php', $c['configFile']);
        assert_throws(ConfigException::class, fn() => Config::load($dir . '/missing.php'), 'config file not found');
        assert_throws(ConfigException::class, fn() => Config::load($dir . '/broken.php'), 'must return an array');
    } finally {
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
});
