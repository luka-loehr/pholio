<?php

declare(strict_types=1);

require __DIR__ . '/run.php';
require __DIR__ . '/../src/Cli.php';

use Pholio\Config;
use Pholio\Fs;

/**
 * Build the demo once, assert what its content relies on, and compare the build
 * with the committed snapshot in tests/snapshots/demo.
 *
 * Regenerate the snapshot with ./scripts/update-snapshots.sh and commit the
 * whole run at once.
 */

const PIPELINE_CONFIG = __DIR__ . '/../examples/demo/pholio.config.php';
const PIPELINE_SNAPSHOT = __DIR__ . '/snapshots/demo';

/**
 * The snapshot is built with PCRE2 10.43 or newer. An older PCRE2 simplifies some syntax colours, so the
 * comparison is skipped there, or fails when the environment sets PHOLIO_REQUIRE_PCRE2=1.
 */
function pipeline_require_full_pcre2(string $why): void
{
    $version = explode(' ', PCRE_VERSION)[0];
    if (version_compare($version, '10.43', '>=')) {
        return;
    }
    $reason = "PCRE2 {$version} < 10.43: {$why}";
    if (getenv('PHOLIO_REQUIRE_PCRE2') === '1') {
        throw new TestFailure($reason . ' (PHOLIO_REQUIRE_PCRE2=1 requires PCRE2 10.43 or newer)');
    }
    skip($reason);
}

/** @return array{0:int, 1:string, 2:string} exit code, stdout, stderr */
function pipeline_cli(array $args): array
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

$root = Fs::tempDir('pholio-pipeline-test-');
register_shutdown_function(static fn() => Fs::removeDir($root));
$site = $root . '/site';
[$buildCode, , $buildErr] = pipeline_cli(['build', '--config', PIPELINE_CONFIG, '--out', $site, '--quiet']);
if ($buildCode !== 0) {
    echo "FAIL demo build exited {$buildCode}\n     {$buildErr}";
    exit(1);
}

function page(string $url): string
{
    global $site;
    $file = $site . ($url === '/' ? '' : $url) . '/index.html';
    assert_true(is_file($file), "no page for {$url}");

    return (string) file_get_contents($file);
}

test('nav hrefs have no trailing slash and each one is a generated page', function (): void {
    $config = Config::load(PIPELINE_CONFIG);
    $home = page('/');
    foreach ($config['links'] as $link) {
        if ($link['external']) {
            assert_contains('href="' . $link['href'] . '"', $home);
            continue;
        }
        assert_true(!str_ends_with($link['href'], '/'), "{$link['href']} ends with a slash");
        page($link['href']);
        assert_contains('href="' . $link['href'] . '"', $home);
    }
    foreach (['/guide/', '/reference/'] as $slashed) {
        assert_true(!str_contains($home, 'href="' . $slashed . '"'), "{$slashed} is linked with a slash");
    }
});

test('Screenshot emits the explicit dark twin, sized from content.asset_root', function (): void {
    $html = page('/guide');
    assert_contains('<img src="/images/pipeline.svg" alt="Content flows through the parser and the components into a static site" width="1440" height="900" loading="lazy" decoding="async" class="nd-figure-light nd-figure-twin">', $html);
    assert_contains('<img src="/images/pipeline-dark.svg" alt="" width="1440" height="900" loading="lazy" decoding="async" aria-hidden="true" class="nd-figure-dark">', $html);
});

test('a plain Markdown image is sized from the asset root, ImageZoom keeps its attributes', function (): void {
    $html = page('/guide/writing-pages');
    assert_contains('<img alt="An indigo colour scale from 50 to 950" src="/images/color-scale.svg" width="1600" height="600" loading="lazy" decoding="async" class="nd-image">', $html);
    assert_contains('width="1600" height="600" decoding="async" data-nimg="1"', $html);
});

test('Accordions keep their type, Folder defaultOpen starts open', function (): void {
    $html = page('/guide/tabs-and-accordions');
    assert_contains('<div data-orientation="vertical" type="single" class="nd-accordions">', $html);
    assert_contains('<div data-orientation="vertical" type="multiple" class="nd-accordions">', $html);

    $files = page('/guide/steps-and-files');
    assert_true(preg_match('#</svg>notes</button><div data-open=""#', $files) === 1, 'notes folder open');
    assert_true(preg_match('#</svg>index</button><div data-open=""#', $files) === 1, 'index folder open');
    assert_true(preg_match('#</svg>attachments</button><template data-collapsible-panel="">#', $files) === 1, 'attachments folder closed');
});

test('the redirect and the search index follow the config', function (): void {
    global $site;
    assert_contains('RewriteRule ^docs/install$ /guide/installation [R=301,L]', (string) file_get_contents($site . '/.htaccess'));
    $index = json_decode((string) file_get_contents($site . '/search-index.json'), true);
    assert_same(['/', 'english', 10], [$index['base'], $index['tokenizer'], count($index['pages'])]);
});

test('the demo build matches tests/snapshots/demo', function (): void {
    if (!is_dir(PIPELINE_SNAPSHOT)) {
        skip('tests/snapshots/demo not generated yet; run ./scripts/update-snapshots.sh');
    }
    pipeline_require_full_pcre2('simplified syntax colours differ from the snapshot');
    [$code, $stdout, $err] = pipeline_cli(['check', '--config', PIPELINE_CONFIG, '--against', PIPELINE_SNAPSHOT]);
    assert_same(0, $code, "differences against the snapshot (regenerate with ./scripts/update-snapshots.sh):\n" . $stdout . $err);
});
