<?php

declare(strict_types=1);

require __DIR__ . '/run.php';
require __DIR__ . '/../src/Cli.php';

use Pholio\Builder;
use Pholio\Cli;
use Pholio\ContentException;
use Pholio\Fs;

/** @return array{0:int, 1:string, 2:string} exit code, stdout, stderr */
function pholio(array $args, ?string $cwd = null): array
{
    $process = proc_open(
        array_merge([PHP_BINARY, __DIR__ . '/../bin/pholio'], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
    );
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $out, (string) $err];
}

/** @return array{0:int, 1:string, 2:string} exit code, stdout, stderr, in this process */
function pholio_inline(array $args): array
{
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $code = (new Cli($out, $err))->run(array_merge(['pholio'], $args));
    rewind($out);
    rewind($err);

    return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
}

/** A site directory with a config file; $extra is merged into the config array. */
function site(array $extra = []): string
{
    $dir = Fs::tempDir('pholio-cli-test-');
    mkdir($dir . '/content');
    $config = ['title' => 'Lanternfly', 'content_dir' => 'content', 'output_dir' => 'out'] + $extra;
    file_put_contents($dir . '/pholio.config.php', '<?php return ' . var_export($config, true) . ';');

    return $dir;
}

/** Builder stand-in that writes a fixed set of files instead of rendering. */
final class StubBuilder extends Builder
{
    /** @var array<string, string> */
    public static array $files = ['index.html' => '<h1>Home</h1>', 'guide/index.html' => '<h1>Guide</h1>'];

    public static ?\Throwable $throw = null;

    public function build(string $target): array
    {
        if (self::$throw !== null) {
            throw self::$throw;
        }
        foreach (self::$files as $path => $content) {
            if ($this->only === null || str_contains($path, $this->only)) {
                Fs::write($target, $path, $content . ($this->dev ? ' dev' : ''));
            }
        }

        return ['pages' => count(self::$files), 'indexed' => 1, 'redirects' => 0];
    }
}

Cli::$builderFactory = static fn(array $config, bool $dev, ?string $only): Builder => new StubBuilder($config, $dev, $only);

test('--help exits 0 and lists the commands', function (): void {
    [$code, $out] = pholio(['--help']);
    assert_same(0, $code);
    foreach (['pholio build', 'pholio check', 'pholio dev', '--profile', '--set', 'Exit codes'] as $needle) {
        assert_contains($needle, $out);
    }
    assert_same(0, pholio(['build', '--help'])[0]);
});

test('--version prints VERSION', function (): void {
    [$code, $out] = pholio(['--version']);
    assert_same(0, $code);
    assert_same('pholio ' . trim((string) file_get_contents(__DIR__ . '/../VERSION')) . "\n", $out);
});

test('no command prints usage and exits 2', function (): void {
    [$code, , $err] = pholio([]);
    assert_same(2, $code);
    assert_contains('Usage:', $err);
});

test('unknown command and flag exit 2', function (): void {
    [$code, , $err] = pholio(['publish']);
    assert_same(2, $code);
    assert_contains('pholio: unknown command: publish', $err);
    [$code, , $err] = pholio(['build', '--frobnicate']);
    assert_same(2, $code);
    assert_contains('unknown option for build: --frobnicate', $err);
    [$code, , $err] = pholio(['--frobnicate']);
    assert_same(2, $code);
    assert_contains('unknown option: --frobnicate', $err);
    [$code, , $err] = pholio(['check', '--out', 'x']);
    assert_same(2, $code);
    assert_contains('unknown option for check: --out', $err);
    assert_same(2, pholio(['build', '--config'])[0]);
    assert_same(2, pholio(['build', '--quiet=yes'])[0]);
    assert_same(2, pholio(['build', 'stray'])[0]);
});

test('removed flags are unknown', function (): void {
    foreach (['--home', '--base', '--asset-base', '--brand-dir'] as $flag) {
        assert_same(2, pholio(['build', $flag, '/x'])[0], $flag);
    }
});

test('missing config file exits 2', function (): void {
    $dir = Fs::tempDir('pholio-cli-test-');
    try {
        [$code, , $err] = pholio(['build'], $dir);
        assert_same(2, $code);
        assert_contains('config file not found: pholio.config.php', $err);
    } finally {
        Fs::removeDir($dir);
    }
});

test('planned key exits 2 with "planned:"', function (): void {
    $dir = site(['base_url' => 'https://example.org']);
    try {
        [$code, , $err] = pholio(['build', '--config', $dir . '/pholio.config.php']);
        assert_same(2, $code);
        assert_contains('pholio: ' . $dir . '/pholio.config.php: planned: base_url', $err);
    } finally {
        Fs::removeDir($dir);
    }
});

test('unknown translations key exits 2', function (): void {
    $dir = site(['translations' => ['Serch(search trigger)' => 'Find']]);
    try {
        [$code, , $err] = pholio(['build', '--config', $dir . '/pholio.config.php']);
        assert_same(2, $code);
        assert_contains('translations: unknown key "Serch(search trigger)"', $err);
    } finally {
        Fs::removeDir($dir);
    }
});

test('unknown config key and unknown profile exit 2', function (): void {
    $dir = site(['serch' => []]);
    try {
        [$code, , $err] = pholio(['build', '--config', $dir . '/pholio.config.php']);
        assert_same(2, $code);
        assert_contains('unknown key: serch', $err);
    } finally {
        Fs::removeDir($dir);
    }
    $dir = site();
    try {
        [$code, , $err] = pholio(['build', '--config', $dir . '/pholio.config.php', '--profile', 'preview']);
        assert_same(2, $code);
        assert_contains('unknown profile "preview"', $err);
        [$code, , $err] = pholio(['build', '--config', $dir . '/pholio.config.php', '--set', 'title']);
        assert_same(2, $code);
        assert_contains('--set expects key.path=value', $err);
    } finally {
        Fs::removeDir($dir);
    }
});

test('missing content directory exits 2', function (): void {
    $dir = site();
    try {
        [$code, , $err] = pholio_inline(['build', '--config', $dir . '/pholio.config.php', '--content', $dir . '/nope']);
        assert_same(2, $code);
        assert_contains('content directory not found', $err);
    } finally {
        Fs::removeDir($dir);
    }
});

test('build writes into output_dir, honours --out, --only, --dev and --quiet', function (): void {
    $dir = site();
    try {
        [$code, , $err] = pholio_inline(['build', '--config', $dir . '/pholio.config.php']);
        assert_same(0, $code, $err);
        assert_same("<h1>Guide</h1>\n", file_get_contents($dir . '/out/guide/index.html'));
        assert_contains('2 pages', $err);

        [$code, , $err] = pholio_inline(['build', '--config', $dir . '/pholio.config.php', '--out', $dir . '/other', '--only', 'guide', '--dev', '--quiet']);
        assert_same(0, $code, $err);
        assert_same('', $err);
        assert_same("<h1>Guide</h1> dev\n", file_get_contents($dir . '/other/guide/index.html'));
        assert_true(!is_file($dir . '/other/index.html'));
    } finally {
        Fs::removeDir($dir);
    }
});

test('check reports missing, stale and extra files and exits 1', function (): void {
    $dir = site(['output' => ['keep' => ['media']]]);
    $config = $dir . '/pholio.config.php';
    try {
        assert_same(0, pholio_inline(['build', '--config', $config, '--quiet'])[0]);
        [$code, $out, $err] = pholio_inline(['check', '--config', $config]);
        assert_same(0, $code, $out . $err);
        assert_same('', $out);

        file_put_contents($dir . '/out/index.html', 'changed');
        unlink($dir . '/out/guide/index.html');
        Fs::write($dir . '/out', 'old/index.html', 'old');
        Fs::write($dir . '/out', 'media/photo.png', 'kept');
        [$code, $out, $err] = pholio_inline(['check', '--config', $config]);
        assert_same(1, $code);
        assert_same("missing: guide/index.html\nstale: index.html\nextra: old/index.html\n", $out);
        assert_contains('3 difference(s)', $err);
    } finally {
        Fs::removeDir($dir);
    }
});

test('check --against and the deprecated build --check alias', function (): void {
    $dir = site();
    $config = $dir . '/pholio.config.php';
    try {
        assert_same(0, pholio_inline(['build', '--config', $config, '--out', $dir . '/snapshot', '--quiet'])[0]);
        assert_same(0, pholio_inline(['check', '--config', $config, '--against', $dir . '/snapshot'])[0]);
        assert_same(1, pholio_inline(['check', '--config', $config])[0], 'output_dir was never built');

        [$code, , $err] = pholio_inline(['build', '--check', '--config', $config, '--out', $dir . '/snapshot']);
        assert_same(0, $code, $err);
        assert_contains('deprecated', $err);
        assert_same(2, pholio_inline(['build', '--check', '--config', $config, '--only', 'x'])[0]);
    } finally {
        Fs::removeDir($dir);
    }
});

test('check builds below the start page path in a temporary root', function (): void {
    $config = ['homeUrl' => '/docs/manual'];
    assert_same('/tmp/root/docs/manual', Builder::targetBelow($config, '/tmp/root/'));
    assert_same('/tmp/root', Builder::targetBelow(['homeUrl' => '/'], '/tmp/root'));
    assert_same('/tmp/root', Builder::siteRoot($config, '/tmp/root/docs/manual'));
});

test('content, I/O and internal errors map to 3, 4 and 70', function (): void {
    $dir = site();
    $config = $dir . '/pholio.config.php';
    try {
        StubBuilder::$throw = new ContentException('unknown component tag <Foo>', $dir . '/content/a.md', 12);
        [$code, , $err] = pholio_inline(['build', '--config', $config]);
        assert_same(3, $code);
        assert_same('pholio: ' . $dir . "/content/a.md:12: unknown component tag <Foo>\n", $err);

        StubBuilder::$throw = new \Pholio\IoException('file not writable: /x');
        assert_same(4, pholio_inline(['build', '--config', $config])[0]);

        StubBuilder::$throw = new \RuntimeException('boom');
        [$code, , $err] = pholio_inline(['build', '--config', $config]);
        assert_same(70, $code);
        assert_contains('pholio: internal error: boom', $err);
    } finally {
        StubBuilder::$throw = null;
        Fs::removeDir($dir);
    }
});

test('dev validates host and port before building', function (): void {
    assert_same(2, pholio(['dev', '--port', '99999'])[0]);
    assert_same(2, pholio(['dev', '--host', 'a b'])[0]);
});

test('Fs::compare honours keep paths as directory prefixes', function (): void {
    assert_true(Fs::isKept('media/a.png', ['media/']));
    assert_true(Fs::isKept('media', ['media']));
    assert_true(!Fs::isKept('media-old/a.png', ['media']));
    assert_true(!Fs::isKept('a.png', ['']));
});

test('Htaccess renders redirects, CSP and rejects invalid entries', function (): void {
    $text = \Pholio\Htaccess::render('/docs', [
        ['from' => '/docs/install/', 'to' => '/docs/guide/installation'],
        ['from' => '/docs/index.html', 'to' => '/docs'],
    ], "default-src 'self'");
    assert_contains('# Generated by Pholio. Do not edit.', $text);
    assert_contains('RewriteBase /docs/', $text);
    assert_contains('RewriteRule ^install/?$ /docs/guide/installation [R=301,L]', $text);
    assert_contains('RewriteRule ^index\.html$ /docs [R=301,L]', $text);
    assert_contains('Header always set Content-Security-Policy "default-src \'self\'"', $text);
    assert_contains('# No redirects configured.', \Pholio\Htaccess::render('/', [], 'x'));
    assert_throws(\Pholio\ConfigException::class, fn() => \Pholio\Htaccess::render('/docs', [['from' => '/other', 'to' => '/x']], 'x'), 'not below');
    assert_throws(\Pholio\ConfigException::class, fn() => \Pholio\Htaccess::render('/docs', [['from' => '/docs/a', 'to' => '/x'], ['from' => '/docs/a', 'to' => '/y']], 'x'), 'duplicate');
});

test('dev server router maps slashless URLs to index.html', function (): void {
    $dir = site();
    Fs::write($dir . '/out', 'guide/index.html', '<h1>Guide</h1>');
    Fs::write($dir . '/out', 'notes.md', 'secret');
    $port = 20000 + random_int(0, 20000);
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $dir . '/out', __DIR__ . '/../src/DevServer.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    try {
        $get = static function (string $path) use ($port): array {
            for ($i = 0; $i < 50; $i++) {
                $stream = @fopen('http://127.0.0.1:' . $port . $path, 'r', false, stream_context_create(['http' => ['ignore_errors' => true]]));
                if ($stream !== false) {
                    $status = (string) (stream_get_meta_data($stream)['wrapper_data'][0] ?? '');
                    $body = (string) stream_get_contents($stream);
                    fclose($stream);

                    return [(int) substr($status, 9, 3), $body];
                }
                usleep(100_000);
            }
            skip('built-in server did not start');
        };
        assert_same([200, "<h1>Guide</h1>\n"], $get('/guide'));
        assert_same([200, "<h1>Guide</h1>\n"], $get('/guide/'));
        assert_same(404, $get('/missing')[0]);
        assert_same(404, $get('/notes.md')[0]);
    } finally {
        proc_terminate($server);
        proc_close($server);
        Fs::removeDir($dir);
    }
});
