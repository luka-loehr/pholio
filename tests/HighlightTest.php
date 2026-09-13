<?php

declare(strict_types=1);

/**
 * Byte parity of the PHP syntax highlighter (src/lib/Highlight.php) with the reference build and Shiki.
 *
 * The reference is the committed `*.expected.html` (or `.expected.error.txt`) next to every sample under
 * verify/highlight-samples/**, byte for byte. With `node` and verify/node_modules, a fresh run of
 * verify/highlight-oracle.mjs must reproduce the committed references, and every entry in
 * known-differences.json is proven by isolated oracle runs. Without them those two tests are skipped.
 *
 * Usage: php tests/HighlightTest.php [--only <substring>] [--diff]
 */

require __DIR__ . '/run.php';

use Pholio\Highlight;

$root = dirname(__DIR__);
$samples = $root . '/verify/highlight-samples';
$oracle = $root . '/verify/highlight-oracle.mjs';
$args = array_slice($argv, 1);
$only = null;
if (($i = array_search('--only', $args, true)) !== false) {
    $only = $args[$i + 1] ?? null;
}
$showDiff = in_array('--diff', $args, true);

if (!is_file($root . '/src/lib/Highlight.php')) {
    skip_all('engine src/lib/Highlight.php not present');
}
require_full_pcre2_or_skip('simplified grammars differ from the references', true);
require_once $root . '/src/lib/Highlight.php';

$nodeOk = is_dir($root . '/verify/node_modules/fumadocs-core')
    && trim((string) shell_exec('command -v node 2>/dev/null')) !== '';

/** @return list<string> sample paths relative to $samples, sorted */
function highlight_samples(string $samples, ?string $only): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($samples, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        $rel = substr($p, strlen($samples) + 1);
        if (preg_match('/\.(expected\.html|expected\.error\.txt|meta\.json)$/', $p)
            || $rel === 'known-differences.json' || $rel === 'README.md' || str_starts_with(basename($p), '.')) {
            continue;
        }
        if ($only === null || str_contains($rel, $only)) {
            $files[] = $rel;
        }
    }
    sort($files);
    return $files;
}

/** Runs the oracle into a temporary directory and returns it. */
function highlight_oracle_run(string $oracle, ?string $only): string
{
    $dir = sys_get_temp_dir() . '/pholio-highlight-oracle-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $cmd = 'node ' . escapeshellarg($oracle) . ' --out ' . escapeshellarg($dir)
        . ($only !== null ? ' --only ' . escapeshellarg($only) : '') . ' 2>&1';
    exec($cmd, $out, $code);
    if ($code !== 0) {
        throw new TestFailure("oracle failed (exit $code):\n" . implode("\n", $out));
    }
    return $dir;
}

function highlight_rmdir(string $dir): void
{
    exec('rm -rf ' . escapeshellarg($dir));
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
$entries = json_decode((string) file_get_contents($samples . '/known-differences.json'), true, 512, JSON_THROW_ON_ERROR)['entries'];

// Known differences. Proof "isolated": the oracle runs once per listed sample in a fresh process; that output must
// differ from the committed reference, and PHP must equal it. The listed samples must be exactly the ones where
// PHP differs from the committed reference.
$known = [];
test('known differences are proven by isolated oracle runs', function () use ($entries, $nodeOk, $oracle, $samples, &$known): void {
    if ($entries === []) {
        return;
    }
    if (!$nodeOk) {
        skip('node and verify/node_modules are needed to prove ' . count($entries) . ' known difference(s)');
    }
    foreach ($entries as $entry) {
        $id = (string) ($entry['id'] ?? '?');
        $listed = array_map('strval', $entry['samples'] ?? []);
        assert_true($listed !== [] && ($entry['proof']['isolated'] ?? false) === true, "$id: needs samples and proof.isolated = true");
        foreach ($listed as $s) {
            $dir = highlight_oracle_run($oracle, $s);
            $isolated = @file_get_contents($dir . '/' . $s . '.expected.html');
            highlight_rmdir($dir);
            assert_true(is_string($isolated), "$id: isolated oracle run wrote nothing for $s");
            $reference = (string) @file_get_contents($samples . '/' . $s . '.expected.html');
            assert_true($isolated !== $reference, "$id: $s equals the reference in isolation, the proof does not reproduce");
            $known[$s] = ['id' => $id, 'expected' => $isolated];
        }
    }
});

$stats = [];
foreach ($files as $rel) {
    test('highlight ' . $rel, function () use ($rel, $samples, $showDiff, &$known, &$stats): void {
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
        if (is_file($errFile)) {
            $expected = trim((string) file_get_contents($errFile));
            assert_same($expected, $error, 'expected error, got ' . ($error === null ? 'HTML' : 'another error'));
            return;
        }
        assert_true(is_file($expFile), 'no expected file');
        assert_true($error === null, 'exception: ' . $error);
        $expected = (string) file_get_contents($expFile);
        if (isset($known[$rel])) {
            assert_true($got !== $expected, 'listed in known-differences.json, but PHP equals the reference (stale entry)');
            assert_true($got === $known[$rel]['expected'], $known[$rel]['id'] . ': PHP differs from the isolated proof run');
            return;
        }
        if ($got !== $expected) {
            throw new TestFailure(highlight_first_difference($expected, (string) $got, $showDiff));
        }
    });
}

test('a fresh oracle run reproduces the committed references', function () use ($nodeOk, $oracle, $only, $files, $samples): void {
    if (!$nodeOk) {
        skip('node and verify/node_modules are needed for the oracle drift check');
    }
    $dir = highlight_oracle_run($oracle, $only);
    $drift = [];
    foreach ($files as $rel) {
        foreach (['.expected.html', '.expected.error.txt'] as $suffix) {
            $committed = @file_get_contents($samples . '/' . $rel . $suffix);
            $fresh = @file_get_contents($dir . '/' . $rel . $suffix);
            if ($committed !== $fresh) {
                $drift[] = $rel . $suffix;
            }
        }
    }
    highlight_rmdir($dir);
    assert_same([], $drift, 'fresh oracle output differs from the committed references');
});

$lines = array_sum(array_column($stats, 'lines'));
$time = array_sum(array_column($stats, 'time'));
printf("highlight: %d samples, %d lines in %.3f s (%.0f lines/s)\n", count($files), $lines, $time, $time > 0 ? $lines / $time : 0);
