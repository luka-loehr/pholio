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

test('--quiet prints nothing and --only renders matching pages only', function (): void {
    $out = build_temp() . '/site';
    [$code, $stdout, $err] = build_cli(['build', '--config', BUILD_DEMO_CONFIG, '--out', $out, '--only', '/reference/', '--quiet']);
    assert_same(0, $code, $err);
    assert_same('', $stdout . $err);
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
    foreach (['content', 'assets'] as $link) {
        symlink(dirname(BUILD_DEMO_CONFIG) . '/' . $link, $dir . '/' . $link);
    }
    [$code, , $err] = build_cli(['build', '--config', $dir . '/pholio.config.php', '--set', 'language=de', '--quiet']);
    assert_same(0, $code, $err);
    assert_contains('<html lang="de"', (string) file_get_contents($dir . '/out/index.html'));
    assert_same('german', json_decode((string) file_get_contents($dir . '/out/search-index.json'), true)['tokenizer']);
});

// ------------------------------------------------------------------ exit 2: usage and configuration

test('a bad config exits 2 and names the key', function (): void {
    $page = "---\ntitle: Start\n---\n\nText\n";
    $cases = [
        "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'titel' => 'x']" => 'unknown key: titel (did you mean "title"?)',
        "['content_dir' => 'content', 'output_dir' => 'out']" => 'missing required key: title',
        "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'logo_size' => '24']" => 'logo_size: expected an integer, got "24"',
        "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'base_url' => 'https://example.org']" => 'planned: base_url is not implemented yet',
        "['title' => 'Site', 'content_dir' => 'content', 'output_dir' => 'out', 'nav' => [['title' => 'A', 'href' => '/a', 'active' => 'all']]]" => 'nav.0.active: expected one of exact, prefix, got "all"',
        "['title' => 'Site', 'content_dir' => 'missing', 'output_dir' => 'out']" => 'content directory not found: ',
        "'not an array'" => 'the config file must return an array',
    ];
    foreach ($cases as $config => $message) {
        $dir = build_site($page, $config);
        [$code, , $err] = build_cli(['build', '--config', $dir . '/pholio.config.php']);
        assert_same(2, $code, $config . "\n" . $err);
        assert_contains($message, $err, $config);
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

// ------------------------------------------------------------------ exit 4: I/O

test('an output directory that cannot be created exits 4', function (): void {
    $dir = build_temp();
    touch($dir . '/file');
    [$code, , $err] = build_cli(['build', '--config', BUILD_DEMO_CONFIG, '--out', $dir . '/file/site', '--quiet']);
    assert_same(4, $code, $err);
    assert_contains('pholio: directory cannot be created: ' . $dir . '/file/site', $err);
});
