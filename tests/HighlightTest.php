<?php

declare(strict_types=1);

/**
 * Regression tests of the PHP syntax highlighter (src/lib/Highlight.php).
 *
 * Every sample under tests/fixtures/highlight/** has its committed output next to it, `*.expected.html` (or
 * `.expected.error.txt` for input that must stop the build), compared byte for byte. After an intended change to
 * the highlighter, `--update` rewrites the expected files from the current output; review them with `git diff`.
 *
 * Usage: php tests/HighlightTest.php [--only <substring>] [--diff] [--update]
 */

require __DIR__ . '/run.php';

use Pholio\Highlight;

$root = dirname(__DIR__);
$samples = __DIR__ . '/fixtures/highlight';
$args = array_slice($argv, 1);
$only = null;
if (($i = array_search('--only', $args, true)) !== false) {
    $only = $args[$i + 1] ?? null;
}
$showDiff = in_array('--diff', $args, true);
$update = in_array('--update', $args, true);

if (!is_file($root . '/src/lib/Highlight.php')) {
    skip_all('engine src/lib/Highlight.php not present');
}
require_full_pcre2_or_skip('simplified grammars differ from the expected output', true);
require_once $root . '/src/lib/Highlight.php';

/** @return list<string> sample paths relative to $samples, sorted */
function highlight_samples(string $samples, ?string $only): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($samples, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        $rel = substr($p, strlen($samples) + 1);
        if (preg_match('/\.(expected\.html|expected\.error\.txt|meta\.json)$/', $p)
            || $rel === 'README.md' || str_starts_with(basename($p), '.')) {
            continue;
        }
        if ($only === null || str_contains($rel, $only)) {
            $files[] = $rel;
        }
    }
    sort($files);
    return $files;
}

function highlight_first_difference(string $expected, string $got, bool $long): string
{
    $n = min(strlen($expected), strlen($got));
    $i = 0;
    while ($i < $n && $expected[$i] === $got[$i]) {
        $i++;
    }
    $width = $long ? 400 : 160;
    $from = max(0, $i - 60);
    return sprintf(
        "first difference at byte %d\n     expected: %s\n     actual:   %s",
        $i,
        json_encode(substr($expected, $from, $width), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode(substr($got, $from, $width), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

$files = highlight_samples($samples, $only);

if ($only === null) {
    test('every sample directory has samples', function () use ($files, $samples): void {
        $groups = array_unique(array_map(static fn(string $rel): string => explode('/', $rel)[0], $files));
        $dirs = array_map('basename', glob($samples . '/*', GLOB_ONLYDIR) ?: []);
        sort($groups);
        sort($dirs);
        assert_same($dirs, $groups);
    });
}

$stats = [];
foreach ($files as $rel) {
    test('highlight ' . $rel, function () use ($rel, $samples, $showDiff, $update, &$stats): void {
        $group = explode('/', $rel)[0];
        $file = $samples . '/' . $rel;
        $cfg = is_file($file . '.meta.json')
            ? json_decode((string) file_get_contents($file . '.meta.json'), true, 512, JSON_THROW_ON_ERROR) : [];
        $lang = array_key_exists('lang', $cfg) ? $cfg['lang'] : $group;
        $code = (string) file_get_contents($file);
        if (str_ends_with($code, "\n")) {
            $code = substr($code, 0, -1);
        }
        $dynamic = ($cfg['mode'] ?? '') === 'dynamic';
        $meta = $dynamic ? [] : Highlight::parseMeta((string) ($cfg['info'] ?? ''));

        $t0 = hrtime(true);
        $got = null;
        $error = null;
        try {
            $got = Highlight::code($code, $lang, $meta, ['dynamic' => $dynamic])['html'];
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $stats[$group] ??= ['lines' => 0, 'time' => 0.0];
        $stats[$group]['lines'] += substr_count($code, "\n") + 1;
        $stats[$group]['time'] += (hrtime(true) - $t0) / 1e9;

        $errFile = $file . '.expected.error.txt';
        $expFile = $file . '.expected.html';
        if ($update) {
            @unlink($errFile);
            @unlink($expFile);
            file_put_contents($error === null ? $expFile : $errFile, $error === null ? (string) $got : $error . "\n");
            return;
        }
        if (is_file($errFile)) {
            $expected = trim((string) file_get_contents($errFile));
            assert_same($expected, $error, 'expected error, got ' . ($error === null ? 'HTML' : 'another error'));
            return;
        }
        assert_true(is_file($expFile), 'no expected file');
        assert_true($error === null, 'exception: ' . $error);
        $expected = (string) file_get_contents($expFile);
        if ($got !== $expected) {
            throw new TestFailure(highlight_first_difference($expected, (string) $got, $showDiff));
        }
    });
}

$lines = array_sum(array_column($stats, 'lines'));
$time = array_sum(array_column($stats, 'time'));
printf("highlight: %d samples, %d lines in %.3f s (%.0f lines/s)\n", count($files), $lines, $time, $time > 0 ? $lines / $time : 0);
