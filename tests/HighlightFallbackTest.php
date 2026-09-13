<?php

declare(strict_types=1);

// The PCRE2 < 10.43 fallback of src/lib/Highlight/OnigRegex.php: patterns that such a PCRE2 cannot
// compile are disabled one by one, Registry warns once per grammar, and the demo still builds. The
// version and the compile check are injected (OnigRegex::$pcreVersion, OnigRegex::$compiles) so the
// path runs on any PHP. Each scenario runs in its own process because translated patterns are cached.

require __DIR__ . '/run.php';
require_once dirname(__DIR__) . '/src/Fs.php';

use Pholio\Fs;

const FALLBACK_ROOT = __DIR__ . '/..';
const FALLBACK_DEMO_CONFIG = __DIR__ . '/../examples/demo/pholio.config.php';

/**
 * Runs PHP code in a child process after loading the CLI, with `$setup` executed first.
 *
 * @return array{0:int, 1:string, 2:string} exit code, stdout, stderr
 */
function fallback_child(string $setup, string $body): array
{
    $code = 'require ' . var_export(FALLBACK_ROOT . '/src/Cli.php', true) . ';'
        . 'require_once ' . var_export(FALLBACK_ROOT . '/src/lib/Highlight.php', true) . ';'
        . $setup . $body;
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $out, $err];
}

function fallback_build(string $setup): array
{
    $dir = Fs::tempDir('pholio-fallback-test-');
    register_shutdown_function(static fn() => Fs::removeDir($dir));
    $body = 'exit((new Pholio\Cli())->run(["pholio", "build", "--config", ' . var_export(FALLBACK_DEMO_CONFIG, true)
        . ', "--out", ' . var_export($dir . '/out', true) . ']));';

    return [...fallback_child($setup, $body), $dir . '/out'];
}

/** @return array<string, string> relative path => content */
function fallback_tree(string $dir): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $files[substr($f->getPathname(), strlen($dir) + 1)] = (string) file_get_contents($f->getPathname());
    }
    ksort($files);

    return $files;
}

// Harsher than PCRE2 10.42, which only rejects lookbehinds with variable-length alternatives: every lookbehind fails.
const FALLBACK_NO_LOOKBEHIND = 'Pholio\Highlight\OnigRegex::$pcreVersion = "10.42";'
    . 'Pholio\Highlight\OnigRegex::$compiles = static fn (string $re): bool => !preg_match("/\\\\(\\\\?<[=!]/", $re) && @preg_match($re, "") !== false;';

$reference = null;

test('the demo builds normally without a fallback warning', function () use (&$reference): void {
    [$code, , $err, $out] = fallback_build('');
    assert_same(0, $code, $err);
    if (version_compare(explode(' ', PCRE_VERSION)[0], '10.43', '<')) {
        skip('this PHP links PCRE2 ' . PCRE_VERSION . ', the unforced build takes the fallback itself');
    }
    assert_true(!str_contains($err, 'older than 10.43'), 'no fallback warning: ' . $err);
    $reference = fallback_tree($out);
});

test('an old PCRE2 version alone disables nothing when every pattern compiles', function () use (&$reference): void {
    if ($reference === null) {
        skip('needs the unforced build of the previous test');
    }
    [$code, , $err, $out] = fallback_build('Pholio\Highlight\OnigRegex::$pcreVersion = "10.42";');
    assert_same(0, $code, $err);
    assert_true(!str_contains($err, 'older than 10.43'), 'no fallback warning: ' . $err);
    assert_true(fallback_tree($out) === $reference, 'output is byte-identical to the unforced build');
});

test('with patterns that do not compile the demo still builds and warns once per grammar', function (): void {
    [$code, , $err, $out] = fallback_build(FALLBACK_NO_LOOKBEHIND);
    assert_same(0, $code, $err);
    assert_true(is_file($out . '/index.html'), 'index.html written');
    preg_match_all('/^pholio: PCRE2 10\.42 is older than 10\.43; some syntax colours are simplified \((\S+)\)$/m', $err, $m);
    assert_true($m[1] !== [], 'at least one warning: ' . $err);
    assert_same(array_values(array_unique($m[1])), $m[1], 'one warning per grammar');
    foreach (['shellscript', 'typescript'] as $grammar) {
        assert_true(in_array($grammar, $m[1], true), "warning for {$grammar}: " . $err);
    }
});

test('without the fallback an uncompilable pattern still fails loud', function (): void {
    $setup = str_replace('"10.42"', '"10.43"', FALLBACK_NO_LOOKBEHIND);
    [$code, , $err] = fallback_child($setup, 'Pholio\Highlight::code("if true; then echo; fi", "bash");');
    assert_true($code !== 0, 'exit code is not 0');
    assert_contains('Oniguruma pattern cannot be translated to PCRE', $err);
});

test('the warning goes to the injected sink and names the resolved grammar', function (): void {
    $body = '$w = []; Pholio\Highlight\Registry::$warn = static function (string $m) use (&$w): void { $w[] = $m; };'
        . '$html = Pholio\Highlight::code("until true; do echo hi; done", "sh")["html"];'
        . 'Pholio\Highlight::code("while true; do :; done", "bash");'
        . 'echo json_encode([$w, str_starts_with($html, "<pre class=\"shiki")]);';
    [$code, $stdout, $err] = fallback_child(FALLBACK_NO_LOOKBEHIND, $body);
    assert_same(0, $code, $err);
    assert_same('', $err);
    assert_same([["pholio: PCRE2 10.42 is older than 10.43; some syntax colours are simplified (shellscript)\n"], true], json_decode($stdout, true));
});
