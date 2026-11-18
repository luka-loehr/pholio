<?php

declare(strict_types=1);

require __DIR__ . '/run.php';
require __DIR__ . '/../src/Cli.php';

use Pholio\Fs;

/**
 * The real `bin/pholio` on the demo and on small broken sites: exit codes,
 * messages and what a build writes. Every command runs in its own process.
 */

const BUILD_DEMO_CONFIG = __DIR__ . '/../examples/demo/pholio.config.php';

/**
 * stderr without the PCRE2 fallback warnings, which a PCRE2 older than 10.43 prints even with --quiet. With
 * PHOLIO_REQUIRE_PCRE2=1 in the environment the warnings stay, so an old PCRE2 fails instead of passing.
 */
function build_without_pcre2_warnings(string $err): string
{
    return pcre2_supports_full_highlighting() || getenv('PHOLIO_REQUIRE_PCRE2') === '1'
        ? $err
        : (string) preg_replace('/^pholio: PCRE2 \S+ is older than 10\.43; some syntax colours are simplified \([^)]*\)\n/m', '', $err);
}

/** @return array{0:int, 1:string, 2:string} exit code, stdout, stderr */
function build_cli(array $args): array
{
    $process = proc_open(
        array_merge([PHP_BINARY, __DIR__ . '/../bin/pholio'], $args),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $out, $err];
}

function build_temp(): string
{
    $dir = Fs::tempDir('pholio-build-test-');
    register_shutdown_function(static fn() => Fs::removeDir($dir));

    return $dir;
}

/** A site with one page; $config is PHP source of the returned array. */
function build_site(string $page, string $config = "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out']"): string
{
    $dir = build_temp();
    mkdir($dir . '/content');
    file_put_contents($dir . '/content/index.md', $page);
    file_put_contents($dir . '/pholio.config.php', "<?php\nreturn {$config};\n");

    return $dir;
}

// ------------------------------------------------------------------ demo

test('the demo builds with exit 0 and reports pages, index entries and redirects', function (): void {
    $out = build_temp() . '/site';
    [$code, , $err] = build_cli(['build', '--config', BUILD_DEMO_CONFIG, '--out', $out]);
    assert_same(0, $code, $err);
    assert_contains('pholio: 11 pages, 10 in the search index, 1 redirects, written to ' . $out, $err);

    foreach ([
        'index.html', 'guide/index.html', 'guide/installation/index.html', 'reference/markdown-extras/index.html',
        'search-index.json', '.htaccess', 'assets/css/notebook.css', 'assets/js/notebook.js',
        'assets/fonts/inter/inter-latin.woff2', 'assets/LICENSES/lucide-ISC.txt', 'images/logo.svg',
    ] as $file) {
        assert_true(is_file($out . '/' . $file), "missing {$file}");
    }
    assert_true(!file_exists($out . '/_redirects'), 'no _redirects file is written');
    $css = (string) file_get_contents($out . '/assets/css/notebook.css');
    assert_true(!str_contains($css, '/* @pholio:palette */'), 'palette marker replaced');
    assert_contains(":root {\n  --color-fd-primary: hsl(234 61% 57%);\n}\n.dark {\n  --color-fd-primary: hsl(230 100% 78%);\n}", $css);

    [$code, $stdout, $err] = build_cli(['check', '--config', BUILD_DEMO_CONFIG, '--against', $out]);
    assert_same(0, $code, $stdout . $err);

    file_put_contents($out . '/guide/index.html', 'changed');
    unlink($out . '/search-index.json');
    file_put_contents($out . '/leftover.txt', 'x');
    [$code, $stdout] = build_cli(['check', '--config', BUILD_DEMO_CONFIG, '--against', $out]);
    assert_same(1, $code);
    assert_same(['extra: leftover.txt', 'missing: search-index.json', 'stale: guide/index.html'], (static function (string $s): array {
        $lines = array_values(array_filter(explode("\n", $s), 'strlen'));
        sort($lines);

        return $lines;
    })($stdout));
});

test('--quiet prints nothing but PCRE2 fallback warnings, and --only renders matching pages only', function (): void {
    $out = build_temp() . '/site';
    [$code, $stdout, $err] = build_cli(['build', '--config', BUILD_DEMO_CONFIG, '--out', $out, '--only', '/reference/', '--quiet']);
    assert_same(0, $code, $err);
    assert_same('', $stdout . build_without_pcre2_warnings($err));
    assert_true(is_file($out . '/reference/code-blocks/index.html'));
    assert_true(!is_file($out . '/guide/installation/index.html'));
});

test('--set language=de switches the interface and the tokenizer', function (): void {
    $out = build_temp() . '/site';
    [$code, , $err] = build_cli(['build', '--config', BUILD_DEMO_CONFIG, '--out', $out, '--set', 'language=de', '--set', 'search.tokenizer=', '--quiet']);
    // An empty string is not a tokenizer; only the language override is valid.
    assert_same(2, $code, $err);
    assert_contains('search.tokenizer: expected one of english, german', $err);

    $dir = build_temp();
    $config = (string) file_get_contents(BUILD_DEMO_CONFIG);
    file_put_contents($dir . '/pholio.config.php', str_replace("'tokenizer' => 'english',", '', $config));
    foreach (['content', 'assets', 'share-card-logo.svg'] as $link) {
        symlink(dirname(BUILD_DEMO_CONFIG) . '/' . $link, $dir . '/' . $link);
    }
    [$code, , $err] = build_cli(['build', '--config', $dir . '/pholio.config.php', '--set', 'language=de', '--quiet']);
    assert_same(0, $code, $err);
    assert_contains('<html lang="de"', (string) file_get_contents($dir . '/out/index.html'));
    assert_same('german', json_decode((string) file_get_contents($dir . '/out/search-index.json'), true)['tokenizer']);
});

test('search.enabled false: no index, no dialog, no triggers, no index meta', function (): void {
    $on = build_site("---\ntitle: Hello\n---\n\nText.\n");
    [$code, $out, $err] = build_cli(['build', '--config', $on . '/pholio.config.php']);
    assert_same(0, $code, $err);
    $html = (string) file_get_contents($on . '/out/index.html');
    assert_true(is_file($on . '/out/search-index.json'), 'index written by default');
    assert_contains('name="nd-search-index"', $html);
    assert_contains('data-search', $html);

    $off = build_site("---\ntitle: Hello\n---\n\nText.\n", "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'search' => ['enabled' => false]]");
    [$code, $out, $err] = build_cli(['build', '--config', $off . '/pholio.config.php']);
    assert_same(0, $code, $err);
    assert_contains('0 in the search index', $out . $err);
    $html = (string) file_get_contents($off . '/out/index.html');
    assert_true(!is_file($off . '/out/search-index.json'), 'no index file');
    assert_true(!str_contains($html, 'nd-search-index'), 'no index meta');
    assert_true(!str_contains($html, 'data-search'), 'no search trigger');
});

test('base_url: canonical links on every page, falling back to site.url', function (): void {
    $page = "---\ntitle: Hello\n---\n\nText.\n";
    $none = build_site($page);
    [$code, , $err] = build_cli(['build', '--config', $none . '/pholio.config.php']);
    assert_same(0, $code, $err);
    assert_true(!str_contains((string) file_get_contents($none . '/out/index.html'), 'rel="canonical"'), 'no canonical without base_url and site.url');

    $site = build_site($page, "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'site' => ['url' => 'https://docs.example.org']]");
    [$code, , $err] = build_cli(['build', '--config', $site . '/pholio.config.php']);
    assert_same(0, $code, $err);
    assert_contains('<link rel="canonical" href="https://docs.example.org/">', (string) file_get_contents($site . '/out/index.html'));

    $base = build_site($page, "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'base_path' => '/docs/', 'base_url' => 'https://example.org/', 'site' => ['url' => 'https://mirror.example.net']]");
    mkdir($base . '/content/guide');
    file_put_contents($base . '/content/guide/setup.md', "---\ntitle: Setup\n---\n\nSteps.\n");
    [$code, , $err] = build_cli(['build', '--config', $base . '/pholio.config.php']);
    assert_same(0, $code, $err);
    $files = glob($base . '/out/{,*/,*/*/,*/*/*/}index.html', GLOB_BRACE) ?: [];
    $all = implode("\n", array_map(static fn(string $f): string => (string) file_get_contents($f), $files));
    assert_contains('<link rel="canonical" href="https://example.org/docs">', $all);
    assert_contains('<link rel="canonical" href="https://example.org/docs/guide/setup">', $all);
    assert_true(!str_contains($all, 'rel="canonical" href="https://mirror.example.net'), 'base_url wins over site.url');

    $bad = build_site($page, "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'base_url' => 'https://example.org/docs']");
    [$code, , $err] = build_cli(['build', '--config', $bad . '/pholio.config.php']);
    assert_same(2, $code, $err);
    assert_contains('base_url: expected the origin', $err);
});

test('strict_content: pages without a description stop the build and are all listed', function (): void {
    $cfg = "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'strict_content' => true]";
    $dir = build_site("---\ntitle: Start\n---\n\nText.\n", $cfg);
    file_put_contents($dir . '/content/other.md', "---\ntitle: Other\n---\n\nText.\n");
    file_put_contents($dir . '/content/good.md', "---\ntitle: Good\ndescription: Has one.\n---\n\nText.\n");
    [$code, , $err] = build_cli(['build', '--config', $dir . '/pholio.config.php']);
    assert_same(3, $code, $err);
    assert_contains('strict_content: 2 pages without a description', $err);
    assert_contains('content/index.md', $err);
    assert_contains('content/other.md', $err);
    assert_true(!str_contains($err, 'good.md'), 'a page with a description is not listed');

    $ok = build_site("---\ntitle: Start\ndescription: The start.\n---\n\nText.\n", $cfg);
    [$code, , $err] = build_cli(['build', '--config', $ok . '/pholio.config.php']);
    assert_same(0, $code, $err);
});

// ------------------------------------------------------------------ exit 2: usage and configuration

test('a bad config exits 2 and names the key', function (): void {
    $page = "---\ntitle: Start\n---\n\nText\n";
    $cases = [
        "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'titel' => 'x']" => 'unknown key: titel (did you mean "title"?)',
        "['title' => 42, 'content_dir' => 'content', 'output_dir' => 'out']" => 'title: expected a string, got 42',
        "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'logo_size' => '24']" => 'logo_size: expected an integer, got "24"',
        "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'components' => ['Download' => 'x']]" => 'planned: components is not implemented yet',
        "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'slots' => ['nav' => 'x.php']]" => 'slots: unknown slot "nav", expected one of sidebar, toc, footer',
        "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'nav' => [['title' => 'A', 'href' => '/a', 'active' => 'all']]]" => 'nav.0.active: expected one of exact, prefix, got "all"',
        "['title' => 'Site', 'content_dir' => 'missing', 'output_dir' => 'out']" => 'content directory not found: ',
        "'not an array'" => 'the config file must return an array',
        "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'content/out']" => 'output_dir: must not be the content directory or lie inside it',
        "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'nav' => [['title' => 'A', 'href' => '/a', 'icon' => 'no-such-icon-here']]]" => 'nav.0.icon: unknown icon "no-such-icon-here"',
        "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'redirects' => ['/a' => '/b', '/b' => '/a']]" => 'redirect 0: loop: /a -> /b -> /a',
        "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'redirects' => ['/a/' => '/a']]" => 'redirect 0: loop: /a -> /a',
        "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'redirects' => ['/x' => '/a', '/a' => '/b', '/b' => '/c', '/c' => '/b']]" => 'redirect 2: loop: /b -> /c -> /b',
        // The file is PHP: its own errors are configuration errors with its file and line.
        "['title' => 'Site', 'content_dir' => ]" => 'pholio.config.php:2: the config file could not be loaded: ',
        "['title' => intdiv(1, 0), 'content_dir' => 'content']" => 'pholio.config.php:2: the config file could not be loaded: Division by zero',
        "['title' => (function () { throw new \\RuntimeException('no token'); })()]" => 'pholio.config.php:2: the config file could not be loaded: no token',
    ];
    foreach ($cases as $config => $message) {
        $dir = build_site($page, $config);
        [$code, , $err] = build_cli(['build', '--config', $dir . '/pholio.config.php']);
        assert_same(2, $code, $config . "\n" . $err);
        assert_contains($message, $err, $config);
        assert_true(!str_contains($err, 'internal error'), $config . "\n" . $err);
    }

    [$code, , $err] = build_cli(['build', '--config', build_temp() . '/none.php']);
    assert_same(2, $code);
    assert_contains('config file not found', $err);

    [$code, , $err] = build_cli(['build', '--config', BUILD_DEMO_CONFIG, '--profile', 'staging']);
    assert_same(2, $code);
    assert_contains('unknown profile "staging" (none defined)', $err);

    [$code, , $err] = build_cli(['build', '--frobnicate']);
    assert_same(2, $code);
    assert_contains('unknown option for build: --frobnicate', $err);
});

// ------------------------------------------------------------------ exit 3: content

test('content errors exit 3 with file and line', function (): void {
    $cases = [
        "---\ntitle: Start\n---\n\nText\n\n```nosuchlang\na\n```\n" => [7, 'Language `nosuchlang` not found'],
        "---\ntitle: Start\n---\n\n# Big\n" => [5, 'Heading level 1 is not allowed'],
        "---\ntitle: Start\n---\n\n<Nope />\n" => [5, 'Unknown component <Nope>'],
        "---\ntitle: Start\nfoo: bar\n---\n\nText\n" => [3, 'Unknown frontmatter key "foo"'],
        "---\ntitle: Start\n---\n\n<Callout icon=\"no-such-icon-zz\">\nText\n</Callout>\n" => [5, 'Unknown lucide icon "no-such-icon-zz"'],
    ];
    foreach ($cases as $page => [$line, $message]) {
        $dir = build_site($page);
        [$code, , $err] = build_cli(['build', '--config', $dir . '/pholio.config.php', '--quiet']);
        assert_same(3, $code, $err);
        assert_contains('pholio: ' . $dir . '/content/index.md:' . $line . ': ' . $message, $err);
    }
});

/** A site with several pages, content path => Markdown body under a title. */
function build_pages(array $pages, string $config = "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out']"): string
{
    $dir = build_temp();
    foreach ($pages as $path => $body) {
        Fs::write($dir . '/content', $path, "---\ntitle: " . basename($path, '.md') . "\n---\n\n" . $body);
    }
    file_put_contents($dir . '/pholio.config.php', "<?php\nreturn {$config};\n");

    return $dir;
}

test('a redirect away from a published page exits 2, redirect chains and old addresses pass', function (): void {
    $base = "'title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out'";
    $pages = ['index.md' => "Start\n", 'guide/setup.md' => "Setup\n"];

    foreach (["['/guide/setup' => '/']", "['/old' => '/', '/guide/setup/' => '/']"] as $n => $redirects) {
        $dir = build_pages($pages, "[{$base}, 'redirects' => {$redirects}]");
        [$code, , $err] = build_cli(['build', '--config', $dir . '/pholio.config.php']);
        assert_same(2, $code, $err);
        assert_contains("pholio.config.php: redirect {$n}: a page is published at /guide/setup", $err);
        assert_contains('the redirect would hide it; remove the redirect or the page', $err);
    }

    $dir = build_pages($pages, "[{$base}, 'redirects' => ['/older' => '/old', '/old' => '/guide/setup', '/guide/setup-2' => '/guide/setup']]");
    [$code, , $err] = build_cli(['build', '--config', $dir . '/pholio.config.php']);
    assert_same(0, $code, $err);
    assert_contains('3 redirects', $err);
});

test('broken links, anchors and images exit 3 with file and line, and all of them are listed', function (): void {
    $dir = build_pages([
        'index.md' => "Start.\n",
        'guide/index.md' => "## Usage\n\nIntro.\n",
        'guide/setup.md' => "See [install](install), [usage](/guide#usage) and [here](#steps).\n\n## Steps\n\n"
            . "Then [missing](/guide/nope), [no anchor](/guide#nothing)\nand ![a chart](/assets/chart.png).\n",
        'guide/install.md' => "Back to [setup](setup) or [the guide](../guide).\n",
    ]);
    [$code, , $err] = build_cli(['build', '--config', $dir . '/pholio.config.php', '--quiet']);
    assert_same(3, $code, $err);
    assert_contains('pholio: 3 broken links:', $err);
    assert_contains($dir . '/content/guide/setup.md:9: broken link "/guide/nope": nothing is published at /guide/nope', $err);
    assert_contains($dir . '/content/guide/setup.md:9: broken link "/guide#nothing": /guide has no element with the id "nothing"', $err);
    assert_contains($dir . '/content/guide/setup.md:10: broken image "/assets/chart.png": nothing is published at /assets/chart.png', $err);
});

test('relative links resolve like a browser, against a page URL without a trailing slash', function (): void {
    // From /guide (guide/index.md) "setup" is /setup, not /guide/setup.
    $dir = build_pages(['index.md' => "Start.\n", 'guide/index.md' => "Read [setup](setup).\n", 'guide/setup.md' => "Setup.\n"]);
    [$code, , $err] = build_cli(['build', '--config', $dir . '/pholio.config.php', '--quiet']);
    assert_same(3, $code, $err);
    assert_contains($dir . '/content/guide/index.md:5: broken link "setup": nothing is published at /setup', $err);

    $dir = build_pages(['index.md' => "Start.\n", 'guide/index.md' => "Read [setup](guide/setup) and [files](/assets/a.txt).\n", 'guide/setup.md' => "Setup.\n"]);
    Fs::write($dir . '/assets', 'a.txt', 'a');
    [$code, , $err] = build_cli(['build', '--config', $dir . '/pholio.config.php', '--quiet']);
    assert_same(0, $code, $err);
});

test('absolute links to site.url are checked; other hosts, --only and --no-link-check builds are not', function (): void {
    $config = "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'site' => ['url' => 'https://docs.example.org']]";
    $dir = build_pages(['index.md' => "[Gone](https://docs.example.org/gone), [elsewhere](https://example.org/gone).\n", 'other.md' => "Other.\n"], $config);
    [$code, , $err] = build_cli(['build', '--config', $dir . '/pholio.config.php', '--quiet']);
    assert_same(3, $code, $err);
    assert_contains('pholio: 1 broken link:', $err);
    assert_contains('broken link "https://docs.example.org/gone": nothing is published at /gone', $err);

    [$code, , $err] = build_cli(['build', '--config', $dir . '/pholio.config.php', '--quiet', '--only', 'other']);
    assert_same(0, $code, $err);
    [$code, , $err] = build_cli(['build', '--config', $dir . '/pholio.config.php', '--quiet', '--no-link-check']);
    assert_same(0, $code, $err);
});

test('theme.tokens and theme.custom_css reach the stylesheet: tokens at the palette marker, custom CSS last', function (): void {
    $config = "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'theme' => ['tokens' => ['font-sans' => '\"IBM Plex Sans\", sans-serif', 'radius-lg' => '.3rem'], 'light' => ['background' => '#fff'], 'custom_css' => 'theme.css']]";
    $dir = build_pages(['index.md' => "Start.\n"], $config);
    file_put_contents($dir . '/theme.css', ".nd-home-kicker { letter-spacing: 0; }\n");
    [$code, , $err] = build_cli(['build', '--config', $dir . '/pholio.config.php', '--quiet']);
    assert_same(0, $code, $err);
    $css = (string) file_get_contents($dir . '/out/pholio/css/notebook.css');
    $tokens = strpos($css, "--font-sans: \"IBM Plex Sans\", sans-serif;");
    $colors = strpos($css, '--color-fd-background: #fff;');
    assert_true($tokens !== false && $colors !== false && $tokens < $colors, 'tokens precede the colour maps');
    assert_contains("  --radius-lg: .3rem;\n", $css);
    assert_contains('font-family: var(--font-text, Inter, Inter Fallback);', $css);

    $preload = build_pages(['index.md' => "Start.\n"], "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'head' => ['preload' => [['href' => '/assets/f.woff2', 'type' => 'font/woff2']]]]");
    [$code, , $err] = build_cli(['build', '--config', $preload . '/pholio.config.php', '--quiet']);
    assert_same(0, $code, $err);
    assert_contains('<link rel="preload" href="/assets/f.woff2" as="font" type="font/woff2" crossorigin="anonymous">', (string) file_get_contents($preload . '/out/index.html'));
    assert_true(str_ends_with(rtrim($css), ".nd-home-kicker { letter-spacing: 0; }"), 'custom CSS comes last');

    $bad = build_pages(['index.md' => "Start.\n"], "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'theme' => ['tokens' => ['Font' => 'x']]]");
    [$code, , $err] = build_cli(['build', '--config', $bad . '/pholio.config.php', '--quiet']);
    assert_same(2, $code, $err);
    assert_contains('theme.tokens: invalid token Font', $err);

    $missing = build_pages(['index.md' => "Start.\n"], "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'theme' => ['custom_css' => 'nope.css']]");
    [$code, , $err] = build_cli(['build', '--config', $missing . '/pholio.config.php', '--quiet']);
    assert_same(2, $code, $err);
    assert_contains('theme.custom_css not found', $err);
});

test('slots: the HTML of each slot file lands in its place on every docs page, verbatim and static', function (): void {
    $base = "'title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out'";
    $pages = ['index.md' => "## Heading\n\nStart.\n", 'plain.md' => "No headings here.\n"];
    $dir = build_pages($pages, "[{$base}, 'slots' => ['sidebar' => 'slots/sidebar.php', 'toc' => 'slots/toc.php', 'footer' => 'slots/footer.php']]");
    mkdir($dir . '/slots');
    file_put_contents($dir . '/slots/sidebar.php', "<?php\nreturn '<p>v' . implode('.', [1, 2]) . '</p>';\n");
    file_put_contents($dir . '/slots/toc.php', "<?php\nreturn \"\\n<a href=\\\"https://example.com/edit\\\">Edit</a>\\n\";\n");
    file_put_contents($dir . '/slots/footer.php', "<?php\nreturn '<p>&copy; Site <?php echo 1; ?></p>';\n");
    [$code, , $err] = build_cli(['build', '--config', $dir . '/pholio.config.php', '--quiet']);
    assert_same(0, $code, $err);

    $html = (string) file_get_contents($dir . '/out/index.html');
    $sidebar = '<div class="nd-slot nd-slot-sidebar" data-slot="sidebar"><p>v1.2</p></div>';
    // Desktop sidebar and drawer: below the page tree, inside the scrolling list.
    assert_same(2, substr_count($html, $sidebar));
    assert_same(2, substr_count($html, $sidebar . '</div></div></div>'));
    assert_contains('<div class="nd-slot nd-slot-toc" data-slot="toc"><a href="https://example.com/edit">Edit</a></div></div>', $html);
    assert_true(strpos($html, 'nd-slot-toc') > strpos($html, 'nd-toc-list'), 'the toc slot follows the list');
    // Verbatim: what the file returns is not escaped and not evaluated a second time.
    $footer = '<div class="nd-slot nd-slot-footer" data-slot="footer"><p>&copy; Site <?php echo 1; ?></p></div></article>';
    assert_contains($footer, $html);
    assert_true(strpos($html, 'nd-slot-footer') > strpos($html, 'class="nd-pager'), 'the footer slot follows the footer navigation');

    // A page without headings has no table of contents, so no toc slot; the others stay.
    $plain = (string) file_get_contents($dir . '/out/plain/index.html');
    assert_true(!str_contains($plain, 'nd-slot-toc'));
    assert_same(2, substr_count($plain, $sidebar));
    assert_contains($footer, $plain);

    // Without slots a page carries no slot markup.
    $none = build_pages($pages);
    [$code, , $err] = build_cli(['build', '--config', $none . '/pholio.config.php', '--quiet']);
    assert_same(0, $code, $err);
    assert_true(!str_contains((string) file_get_contents($none . '/out/index.html'), 'nd-slot'));

    // A slot that returns "" or blank text prints nothing.
    $empty = build_pages($pages, "[{$base}, 'slots' => ['footer' => 'footer.php']]");
    file_put_contents($empty . '/footer.php', "<?php\nreturn \" \\n\";\n");
    [$code, , $err] = build_cli(['build', '--config', $empty . '/pholio.config.php', '--quiet']);
    assert_same(0, $code, $err);
    assert_true(!str_contains((string) file_get_contents($empty . '/out/index.html'), 'nd-slot'));
});

test('slots: a missing file, a file that returns no string and a PHP error exit 2 and name the slot', function (): void {
    $base = "'title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out'";
    $cases = [
        null => 'slots.footer not found: ',
        "<?php\nreturn ['<p>x</p>'];\n" => 'slots.footer: the slot file must return a string of HTML, got array',
        "<p>plain HTML, no return</p>\n" => 'slots.footer: the slot file must return a string of HTML, got int',
        "<?php\nreturn missing_function();\n" => 'slots.footer: the slot file could not be loaded: Call to undefined function missing_function()',
        "<?php\nreturn 'x' .;\n" => 'slots.footer: the slot file could not be loaded: ',
    ];
    foreach ($cases as $source => $message) {
        $dir = build_pages(['index.md' => "Start.\n"], "[{$base}, 'slots' => ['footer' => 'footer.php']]");
        if ($source !== '') {
            file_put_contents($dir . '/footer.php', $source);
        }
        ob_start();
        try {
            [$code, , $err] = build_cli(['build', '--config', $dir . '/pholio.config.php', '--quiet']);
        } finally {
            ob_end_clean();
        }
        assert_same(2, $code, $err);
        assert_contains($message, $err);
        assert_true(!is_file($dir . '/out/index.html'), 'nothing is published');
    }
});

test('theme.code takes a bundled theme by name or a TextMate theme file; an unknown name exits 2', function (): void {
    $config = "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'theme' => ['code' => ['light' => 'mono-light.json', 'dark' => 'github-dark']]]";
    $dir = build_pages(['index.md' => "```bash\n# a comment\necho hi\n```\n"], $config);
    file_put_contents($dir . '/mono-light.json', json_encode([
        'name' => 'mono-light', 'type' => 'light',
        'colors' => ['editor.foreground' => '#0a0a0a', 'editor.background' => '#ffffff'],
        'tokenColors' => [['scope' => ['comment', 'punctuation.definition.comment'], 'settings' => ['foreground' => '#686868']]],
    ]));
    [$code, , $err] = build_cli(['build', '--config', $dir . '/pholio.config.php', '--quiet']);
    assert_same(0, $code, $err);
    $html = (string) file_get_contents($dir . '/out/index.html');
    assert_contains('shiki-themes mono-light github-dark', $html);
    assert_contains('--shiki-light:#686868', $html);
    assert_contains('--shiki-light:#0a0a0a', $html);

    $bad = build_pages(['index.md' => "Start.\n"], "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'theme' => ['code' => ['light' => 'nord']]]");
    [$code, , $err] = build_cli(['build', '--config', $bad . '/pholio.config.php', '--quiet']);
    assert_same(2, $code, $err);
    assert_contains('theme.code.light: unknown code theme', $err);
});

// ------------------------------------------------------------------ exit 4: I/O

test('an output directory that cannot be created exits 4', function (): void {
    $dir = build_temp();
    touch($dir . '/file');
    [$code, , $err] = build_cli(['build', '--config', BUILD_DEMO_CONFIG, '--out', $dir . '/file/site', '--quiet']);
    assert_same(4, $code, $err);
    assert_contains('pholio: directory cannot be created: ' . $dir . '/file/site', $err);
});
