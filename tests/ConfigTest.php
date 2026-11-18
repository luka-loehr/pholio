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
    assert_same('/pholio', $c['assetBase']);
    assert_same('/site/content', $c['contentDir']);
    assert_same('/site/out', $c['outDir']);
    assert_same([['from' => '/site/assets', 'to' => '/assets', 'optional' => true]], $c['copy']);
    assert_same('en', $c['lang']);
    assert_same('{title} – Lanternfly', $c['titleTemplate']);
    assert_same('Lanternfly', $c['homeTitle']);
    assert_same(['title' => 'Lanternfly', 'logo' => null, 'logoSize' => 24, 'url' => '/'], $c['nav']);
    assert_same(['md'], $c['content']['extensions']);
    assert_same(null, $c['content']['linkPrefix']);
    assert_same(null, $c['content']['assetPrefix']);
    assert_same('/', $c['content']['assetTarget']);
    assert_same(null, $c['home']);
    assert_same('', $c['theme']['fontClass']);
    assert_same('d', $c['theme']['hotkey']);
    assert_same(['icons' => [], 'preload' => [], 'manifest' => null, 'themeColor' => null], $c['head']);
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
    assert_same('/docs/pholio', $c['assetBase']);
    assert_same('/docs/pholio/logo.svg', $c['nav']['logo']);
    assert_same('/docs', $c['nav']['url']);
    assert_same([
        ['title' => 'Home', 'href' => '/docs', 'active' => 'exact', 'external' => false, 'icon' => null, 'iconOnly' => false],
        ['title' => 'Guide', 'href' => '/docs/manual/guide', 'active' => 'prefix', 'external' => false, 'icon' => null, 'iconOnly' => false],
        ['title' => 'Paper', 'href' => '/paper.pdf', 'active' => 'exact', 'external' => true, 'icon' => null, 'iconOnly' => false],
    ], $c['links']);
    assert_same('Lanternfly – Docs', $c['homeTitle']);
    assert_same("One\nTwo", $c['home']['hero']['headline']);
    assert_same('/docs/pholio/brand/hero.webp', $c['home']['hero']['image']);
    assert_same(null, $c['home']['hero']['imageDark']);
    assert_same(48, $c['home']['hero']['iconSize']);
    assert_same(['label' => 'Start', 'href' => '/docs/manual/intro', 'variant' => 'primary', 'icon' => 'arrow-right'], $c['home']['hero']['buttons'][0]);
    assert_same('Open', $c['home']['cards']['linkLabel']);
    assert_same(false, $c['home']['cards']['fromTree']);
    assert_same(['title' => 'Guide', 'description' => '', 'href' => '/docs/manual/guide', 'icon' => null], $c['home']['cards']['items'][0]);
    assert_same([['rel' => 'icon', 'type' => null, 'sizes' => null, 'href' => '/docs/pholio/favicon.png']], $c['head']['icons']);
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
        'copy' => ['brand' => '/assets/brand'],
        'theme' => ['palette_css' => 'palette.css'],
        'redirects_file' => 'content/redirects.json',
        'output' => ['keep' => ['/assets/images/', 'site.webmanifest']],
    ]);
    assert_same('/abs/content', $c['contentDir']);
    assert_same('/site/../public/docs', $c['outDir']);
    assert_same('/site/media', $c['content']['assetRoot']);
    assert_same('/media-assets', $c['content']['assetPrefix']);
    assert_same('/reference/docs', $c['content']['linkPrefix']);
    assert_same([['from' => '/site/brand', 'to' => '/assets/brand', 'optional' => false]], $c['copy'], 'a configured copy replaces the default');
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
    $e = assert_throws(ConfigException::class, fn() => Config::fromArray(['nav' => [['href' => '/']]], '/site'), 'missing required key: nav.0.title');
    assert_same(null, $e->sourceFile);
});

test('nav icons: lucide names pass through, unknown names and icon_only without icon fail', function (): void {
    $c = config(['nav' => [
        ['title' => 'Guide', 'href' => '/guide', 'icon' => 'book-open'],
        ['title' => 'Source', 'href' => 'https://example.org', 'external' => true, 'icon' => 'git-branch', 'icon_only' => true],
        ['title' => 'Plain', 'href' => '/plain'],
    ]]);
    assert_same(['book-open', 'git-branch', null], array_map(static fn(array $l): ?string => $l['icon'], $c['links']));
    // React names are accepted, as in meta.json and the home cards.
    assert_same('book-open', config(['nav' => [['title' => 'a', 'href' => '/', 'icon' => 'BookOpen']]])['links'][0]['icon']);
    assert_same([false, true, false], array_column($c['links'], 'iconOnly'));
    config_error(['nav' => [['title' => 'a', 'href' => '/', 'icon' => 'book-opne']]], 'nav.0.icon: unknown icon "book-opne"; did you mean: book-open');
    config_error(['nav' => [['title' => 'a', 'href' => '/'], ['title' => 'b', 'href' => '/b', 'icon_only' => true]]], 'nav.1.icon_only: needs nav.1.icon');
    config_error(['nav' => [['title' => 'a', 'href' => '/', 'icon' => 7]]], 'nav.0.icon: expected');
    config_error(['nav' => [['title' => 'a', 'href' => '/', 'icon_only' => 'yes']]], 'nav.0.icon_only: expected');
    // A profile can switch a link to an icon button, and is validated after the merge.
    $raw = ['nav' => [['title' => 'a', 'href' => '/']], 'profiles' => [
        'icons' => ['nav' => [0 => ['icon' => 'house', 'icon_only' => true]]],
        'broken' => ['nav' => [0 => ['icon_only' => true]]],
    ]];
    assert_same(true, config($raw, 'icons')['links'][0]['iconOnly']);
    config_error($raw, 'nav.0.icon_only: needs nav.0.icon', 'broken');
});

test('home icons are checked when the configuration loads', function (): void {
    config_error(['home' => ['hero' => ['headline' => 'x', 'buttons' => [['label' => 'Go', 'href' => '/', 'icon' => 'arow-right']]]]], 'home.hero.buttons.0.icon: unknown icon "arow-right"; did you mean: arrow-right');
    config_error(['home' => ['cards' => ['items' => [['title' => 'A', 'href' => '/', 'icon' => 'nope-nope-nope']]]]], 'home.cards.items.0.icon: unknown icon "nope-nope-nope"');
    config(['home' => ['cards' => ['items' => [['title' => 'A', 'href' => '/', 'icon' => 'book-open']]]]]);
});

test('required values must not be empty', function (): void {
    config_error(['nav' => [['title' => '', 'href' => '/']]], 'nav.0.title: must not be empty');
    config_error(['nav' => [['title' => 'a', 'href' => "  \t"]]], 'nav.0.href: must not be empty');
    config_error(['home' => ['hero' => ['headline' => ' ']]], 'home.hero.headline: must not be empty');
    config_error(['home' => ['hero' => ['headline' => 'x', 'buttons' => [['label' => '', 'href' => '/']]]]], 'home.hero.buttons.0.label: must not be empty');
    config_error(['home' => ['cards' => ['items' => [['title' => 'A', 'href' => '']]]]], 'home.cards.items.0.href: must not be empty');
    config_error(['head' => ['icons' => [['rel' => '', 'href' => '/x.svg']]]], 'head.icons.0.rel: must not be empty');
    config_error(['redirects' => [['from' => '/old', 'to' => '']]], 'redirects.0.to: must not be empty');
    config_error(['redirects' => ['/old' => '']], 'redirects./old: the old and the new path must not be empty');
    config_error(['redirects' => ['' => '/new']], 'the old and the new path must not be empty');
    config_error(['title' => ''], 'title: must not be empty');
    config_error(['content_dir' => ''], 'content_dir: must not be empty');
    config_error(['output_dir' => ' '], 'output_dir: must not be empty');
    config_error(['content' => ['extensions' => []]], 'content.extensions: expected at least one extension');
    // Optional texts may stay empty.
    config(['home' => ['title' => '', 'hero' => ['headline' => 'x', 'kicker' => '', 'lead' => '']]]);
});

test('output_dir must lie outside the content directory', function (): void {
    config_error(['content_dir' => 'content', 'output_dir' => 'content'], 'output_dir: must not be the content directory or lie inside it, got "content" with content_dir "content"');
    config_error(['content_dir' => 'content', 'output_dir' => 'content/public'], 'output_dir: must not be the content directory');
    config_error(['content_dir' => 'content', 'output_dir' => './content/../content//site/'], 'output_dir: must not be the content directory');
    config_error(['content_dir' => '.', 'output_dir' => 'public'], 'output_dir: must not be the content directory');
    config_error(['content_dir' => 'content'], 'output_dir: must not be the content directory', null, ['output_dir' => '/site/content/out']);
    // Next to it, above it or with a name that only starts the same is fine.
    config(['content_dir' => 'content', 'output_dir' => 'content-site']);
    config(['content_dir' => 'content', 'output_dir' => 'content/../public']);
    config(['content_dir' => 'docs/content', 'output_dir' => '.']);
    config(['content_dir' => 'content', 'output_dir' => '/tmp/elsewhere']);
});

test('structure errors name the key and what was expected', function (): void {
    config_error(['nav' => 'x'], 'nav: expected a list');
    config_error(['nav' => ['a' => ['title' => 'a', 'href' => '/']]], 'nav: expected a list');
    config_error(['nav' => ['x']], 'nav.0: expected an array');
    config_error(['home' => false], 'home: expected an array or null');
    config_error(['home' => ['hero' => ['x']]], 'home.hero: expected keys, got a list');
    config_error(['search' => true], 'search: expected an array');
    config_error(['theme' => null], 'theme: expected an array');
    config_error(['search' => ['hotkey' => ['a', 1]]], 'search.hotkey: expected a list of strings');
    config_error(['search' => ['hotkey' => ['a' => 'b']]], 'search.hotkey: expected a list of strings');
    config_error(['theme' => ['tokens' => ['a', 'b']]], 'theme.tokens: expected an array of string keys => strings');
    config_error(['theme' => ['tokens' => ['--x' => 1]]], 'theme.tokens: expected an array of string keys => strings');
    config_error(['output' => ['keep' => 'x']], 'output.keep: expected a list of strings, got "x"');
    config_error(['strict_content' => 1], 'strict_content: expected true or false, got 1');
    config_error(['share_card' => ['enabled' => 'yes']], 'share_card.enabled: expected true, false or null, got "yes"');
    config_error([0 => 'x'], 'unknown key: 0');
    config_error(['redirects' => ['/a' => 5]], 'redirects./a: expected the target path as a string');
    config_error(['redirects' => ['x']], 'redirects.0: expected {from, to, reason}');
    config_error(['redirects' => [['from' => '/a', 'to' => '/b', 'why' => 'x']]], 'unknown key: redirects.0.why');
    config_error(['logo_size' => 0], 'logo_size and home.hero.icon_size must be positive');
    config_error(['home' => ['hero' => ['headline' => 'x', 'icon_size' => -1]]], 'logo_size and home.hero.icon_size must be positive');
    config_error(['search' => ['index_path' => '/']], 'search.index_path: expected a relative file path');
    config_error(['search' => ['index_path' => '../x.json']], 'search.index_path: expected a relative file path');
});

test('profiles: shape errors, and a profile is type-checked once it is selected', function (): void {
    config_error(['profiles' => 'x'], 'profiles: expected an array of profile name => config');
    config_error(['profiles' => ['a' => 'x']], 'profiles.a: expected an array');
    config_error(['profiles' => ['a' => ['profiles' => []]]], 'profiles.a.profiles: profiles cannot be nested', 'a');
    config_error(['profiles' => ['a' => []]], 'unknown profile "b" (known: a)', 'b');
    config_error([], 'unknown profile "b" (none defined)', 'b');
    config_error(['profiles' => ['a' => ['search' => ['x' => 1]]]], 'unknown key: profiles.a.search.x');
    $raw = ['profiles' => ['a' => ['logo_size' => 'x']]];
    config($raw);
    config_error($raw, 'logo_size: expected an integer, got "x"', 'a');
});

test('--set: only known string keys, and never a PHP warning', function (): void {
    set_error_handler(static function (int $no, string $message): never {
        throw new \ErrorException($message, 0, $no);
    });
    try {
        assert_same('german', config([], null, ['search.tokenizer' => 'german'])['search']['tokenizer']);
        assert_same('Other', config(['title' => 'Site'], null, ['title' => 'Other'])['title']);
        config_error([], '--set serch.tokenizer: unknown key', null, ['serch.tokenizer' => 'german']);
        config_error([], '--set search.tokenizer.x: unknown key', null, ['search.tokenizer.x' => 'german']);
        config_error([], '--set logo_size: only string keys can be set from the command line', null, ['logo_size' => '3']);
        // A section is not a string key.
        config_error([], '--set search: only string keys can be set from the command line', null, ['search' => 'x']);
        config_error([], '--set nav: only string keys can be set from the command line', null, ['nav' => 'x']);
        config_error([], 'search.tokenizer: expected one of english, german, got "french"', null, ['search.tokenizer' => 'french']);
        // The file holds a scalar where the section belongs: the file's error is reported.
        config_error(['search' => 'x'], 'search: expected an array', null, ['search.tokenizer' => 'german']);
    } finally {
        restore_error_handler();
    }
});

test('planned keys fail with "planned:"', function (): void {
    config_error(['components' => ['Download' => 'x']], 'planned: components');
    // Default values of planned keys are accepted.
    config(['base_url' => null, 'theme' => ['default_scheme' => 'system'], 'slots' => [], 'strict_content' => false]);
});

test('slots: known names resolve against the config directory, unknown names and blank paths fail', function (): void {
    assert_same([], config()['slots']);
    assert_same(
        ['footer' => '/site/slots/footer.php', 'sidebar' => '/abs/sidebar.php'],
        config(['slots' => ['footer' => 'slots/footer.php', 'sidebar' => '/abs/sidebar.php']])['slots'],
    );
    config_error(['slots' => ['nav' => 'x.php']], 'slots: unknown slot "nav", expected one of sidebar, toc, footer');
    config_error(['slots' => ['toc' => ' ']], 'slots.toc: expected the path of a PHP file, got " "');
    config_error(['slots' => ['toc' => 42]], 'slots');
    config_error(['slots' => 'footer.php'], 'slots');
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
    $c = config([], null, ['output_dir' => '/tmp/out', 'search.tokenizer' => 'german', 'theme.hotkey' => 'k']);
    assert_same('/tmp/out', $c['outDir']);
    assert_same('german', $c['search']['tokenizer']);
    assert_same('k', $c['theme']['hotkey']);
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

test('an empty config follows the project convention', function (): void {
    $c = Config::fromArray([], '/project');
    assert_same('Documentation', $c['title']);
    assert_same('', $c['configFile']);
    assert_same('/project', $c['configDir']);
    assert_same('/project/content', $c['contentDir']);
    assert_same('/project/public', $c['outDir']);
    assert_same('/pholio', $c['assetBase']);
    assert_same([['from' => '/project/assets', 'to' => '/assets', 'optional' => true]], $c['copy']);
    $c = Config::fromArray(['base_path' => '/docs/'], '/project');
    assert_same([['from' => '/project/assets', 'to' => '/docs/assets', 'optional' => true]], $c['copy']);
});

test('forDirectory reads pholio.config.php when present and the convention otherwise', function (): void {
    $dir = sys_get_temp_dir() . '/pholio-config-dir-' . bin2hex(random_bytes(4));
    mkdir($dir);
    try {
        $c = Config::forDirectory($dir);
        assert_same('', $c['configFile']);
        assert_same($dir . '/content', $c['contentDir']);
        file_put_contents($dir . '/pholio.config.php', "<?php return ['title' => 'Mine', 'output_dir' => 'site'];");
        $c = Config::forDirectory($dir);
        assert_same($dir . '/pholio.config.php', $c['configFile']);
        assert_same('Mine', $c['title']);
        assert_same($dir . '/site', $c['outDir']);
        assert_throws(ConfigException::class, fn() => Config::forDirectory($dir . '/missing'), 'project directory not found');
    } finally {
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
});
