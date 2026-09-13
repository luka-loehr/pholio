<?php

declare(strict_types=1);

require __DIR__ . '/run.php';
require __DIR__ . '/../src/Cli.php';

use Pholio\Builder;
use Pholio\ConfigException;
use Pholio\Fs;
use Pholio\Init;

/**
 * `pholio init` and the project convention: a fresh project builds without
 * changes, its image is published with its size, and existing files are
 * never overwritten without --force. Commands run the real bin/pholio.
 */

/** @return array{0:int, 1:string, 2:string} exit code, stdout, stderr */
function init_cli(array $args, string $cwd, ?string $program = null, ?array $env = null): array
{
    $process = proc_open(
        array_merge([PHP_BINARY, $program ?? __DIR__ . '/../bin/pholio'], $args),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        $env,
    );
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $out, $err];
}

const INIT_FILES = [
    'assets/images/pipeline.svg',
    'content/index.md',
    'content/meta.json',
    'content/writing-pages.md',
    'pholio.config.php',
];

test('init creates the project files and prints the next steps', function (): void {
    $root = Fs::tempDir('pholio-init-test-');
    try {
        [$code, $out, $err] = init_cli(['init', 'my-docs'], $root);
        assert_same(0, $code, $err);
        assert_same(INIT_FILES, Fs::treeFiles($root . '/my-docs'));
        assert_contains('Created My Docs in ', $out);
        $bin = __DIR__ . '/../bin/pholio';
        assert_contains("  php {$bin} dev my-docs ", $out, 'pholio is not on PATH: the command names the script');
        assert_contains("  php {$bin} build my-docs ", $out);
        assert_true(!str_contains($out, 'cd '), 'the commands take the directory, no cd step');

        $config = require $root . '/my-docs/pholio.config.php';
        assert_same(['title' => 'My Docs', 'language' => 'en'], $config);
    } finally {
        Fs::removeDir($root);
    }
});

test('--name and --lang are written into the config, names are escaped', function (): void {
    $root = Fs::tempDir('pholio-init-test-');
    try {
        [$code, $out, $err] = init_cli(['init', '--name', "Rob's \"Docs\"", '--lang', 'de'], $root);
        assert_same(0, $code, $err);
        assert_contains(" dev      preview", $out, 'no directory argument for the current directory');
        assert_same(['title' => "Rob's \"Docs\"", 'language' => 'de'], require $root . '/pholio.config.php');
        assert_same("Rob's \"Docs\"", json_decode((string) file_get_contents($root . '/content/meta.json'), true)['title']);
        [$code, , $err] = init_cli(['init', 'other', '--lang', 'fr'], $root);
        assert_same(2, $code);
        assert_contains('--lang: expected one of de, en', $err);
    } finally {
        Fs::removeDir($root);
    }
});

test('init refuses to overwrite and writes nothing, --force overwrites', function (): void {
    $root = Fs::tempDir('pholio-init-test-');
    try {
        Fs::write($root, 'content/index.md', 'my own page');
        [$code, , $err] = init_cli(['init'], $root);
        assert_same(2, $code);
        assert_contains('already contains content/index.md; nothing was written. Use --force', $err);
        assert_same(['content/index.md'], Fs::treeFiles($root));
        assert_same("my own page\n", file_get_contents($root . '/content/index.md'));

        [$code, , $err] = init_cli(['init', '--force'], $root);
        assert_same(0, $code, $err);
        assert_same(INIT_FILES, Fs::treeFiles($root));
        assert_true(!str_contains((string) file_get_contents($root . '/content/index.md'), 'my own page'));

        file_put_contents($root . '/file', 'x');
        assert_throws(ConfigException::class, fn() => Init::create($root . '/file', 'X', 'en'), 'not a directory');
    } finally {
        Fs::removeDir($root);
    }
});

test('a fresh project builds, publishes the image with its size and passes check', function (): void {
    if (Builder::missingParts() !== []) {
        skip('generator incomplete');
    }
    $root = Fs::tempDir('pholio-init-test-');
    try {
        assert_same(0, init_cli(['init', 'docs'], $root)[0]);
        $project = $root . '/docs';
        [$code, , $err] = init_cli(['build'], $project);
        assert_same(0, $code, $err);
        assert_contains('2 pages', $err);
        assert_true(is_file($project . '/public/index.html'));
        assert_true(is_file($project . '/public/writing-pages/index.html'));
        assert_same(file_get_contents($project . '/assets/images/pipeline.svg'), file_get_contents($project . '/public/assets/images/pipeline.svg'));
        assert_true(is_file($project . '/public/pholio/css/notebook.css'), 'theme assets under /pholio/');

        $img = '<img alt="How a page travels from Markdown to the browser" src="/assets/images/pipeline.svg" width="720" height="160"';
        assert_contains($img, (string) file_get_contents($project . '/public/index.html'), 'absolute image path');
        $relative = '<img alt="The same diagram, referenced relatively" src="/assets/images/pipeline.svg" width="720" height="160"';
        assert_contains($relative, (string) file_get_contents($project . '/public/writing-pages/index.html'), 'relative image path');

        [$code, $out, $err] = init_cli(['check'], $project);
        assert_same(0, $code, $out . $err);

        // The same project from its parent directory, and without a config file.
        unlink($project . '/pholio.config.php');
        Fs::removeDir($project . '/public');
        [$code, , $err] = init_cli(['build', 'docs', '--quiet'], $root);
        assert_same(0, $code, $err);
        assert_contains('<title>Writing pages – Documentation</title>', (string) file_get_contents($project . '/public/writing-pages/index.html'));
        assert_true(is_file($project . '/public/assets/images/pipeline.svg'));
    } finally {
        Fs::removeDir($root);
    }
});

test('a project without assets/ builds, a relative image outside assets/ is a content error', function (): void {
    if (Builder::missingParts() !== []) {
        skip('generator incomplete');
    }
    $root = Fs::tempDir('pholio-init-test-');
    try {
        assert_same(0, init_cli(['init'], $root)[0]);
        Fs::removeDir($root . '/assets');
        file_put_contents($root . '/content/writing-pages.md', "---\ntitle: Writing pages\n---\n\nNo images here.\n");
        file_put_contents($root . '/content/index.md', "---\ntitle: Home\n---\n\nPlain.\n");
        [$code, , $err] = init_cli(['build', '--quiet'], $root);
        assert_same(0, $code, $err);
        assert_true(!is_dir($root . '/public/assets'));

        file_put_contents($root . '/content/index.md', "---\ntitle: Home\n---\n\n![x](images/missing.png)\n");
        [$code, , $err] = init_cli(['build', '--quiet'], $root);
        assert_same(3, $code, $err);
        assert_contains('content/index.md: image "images/missing.png" resolves to content/images/missing.png, outside the copied asset directories (assets/)', $err);
    } finally {
        Fs::removeDir($root);
    }
});

test('next steps say "pholio" when that name on PATH is this script', function (): void {
    $root = Fs::tempDir('pholio-init-test-');
    try {
        mkdir($root . '/bin');
        symlink(realpath(__DIR__ . '/../bin/pholio'), $root . '/bin/pholio');
        $env = ['PATH' => $root . '/bin' . PATH_SEPARATOR . (string) getenv('PATH')];
        [$code, $out, $err] = init_cli(['init', 'docs'], $root, $root . '/bin/pholio', $env);
        assert_same(0, $code, $err);
        assert_contains("  pholio dev docs ", $out);
        assert_contains("  pholio build docs ", $out);

        [$code, $out] = init_cli(['init', 'site'], $root, 'bin/pholio', $env);
        assert_same(0, $code);
        assert_contains("  pholio dev site ", $out, 'a relative path to the same script also counts');

        [$code, $out] = init_cli(['init', 'other'], $root, __DIR__ . '/../bin/pholio', ['PATH' => '/usr/bin:/bin']);
        assert_same(0, $code);
        assert_contains('  php ' . __DIR__ . '/../bin/pholio dev other ', $out);
    } finally {
        Fs::removeDir($root);
    }
});
