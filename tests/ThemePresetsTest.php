<?php

declare(strict_types=1);

require __DIR__ . '/run.php';
require __DIR__ . '/../src/Cli.php';

use Pholio\Config;
use Pholio\Fs;

/**
 * The built-in color presets of `theme.preset`: one stylesheet per name in Config::PRESETS, each builds
 * the demo, lands at the palette marker and changes the color tokens; `theme.light`, `theme.dark` and
 * `theme.palette_css` still come after it.
 */

const PRESETS_ROOT = __DIR__ . '/..';
const PRESETS_DEMO_CONFIG = PRESETS_ROOT . '/examples/demo/pholio.config.php';

/** @return array{0:int, 1:string, 2:string} exit code, stdout, stderr */
function presets_cli(array $args): array
{
    $process = proc_open(
        array_merge([PHP_BINARY, PRESETS_ROOT . '/bin/pholio'], $args),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $out, $err];
}

function presets_temp(): string
{
    $dir = Fs::tempDir('pholio-presets-test-');
    register_shutdown_function(static fn() => Fs::removeDir($dir));

    return $dir;
}

/** The delivered notebook.css below a build directory, wherever `asset_base` puts it. */
function presets_stylesheet(string $out): string
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (str_ends_with($file->getPathname(), '/css/notebook.css')) {
            return (string) file_get_contents($file->getPathname());
        }
    }
    throw new TestFailure('no css/notebook.css below ' . $out);
}

/** The --color-fd-* declarations of the first block with this selector, token => value. */
function presets_tokens(string $css, string $selector): array
{
    if (preg_match('/^' . preg_quote($selector, '/') . ' \{\n(.*?)^\}/ms', $css, $m) !== 1) {
        return [];
    }
    preg_match_all('/--color-fd-([a-z-]+):\s*([^;]+);/', $m[1], $pairs, PREG_SET_ORDER);

    return array_column($pairs, 2, 1);
}

/** The palette block of a delivered notebook.css: everything between tokens.css's marker slot and @property. */
function presets_palette(string $css): string
{
    $start = strpos($css, '/* ' . Config::PRESETS[0] . ': ');
    foreach (Config::PRESETS as $name) {
        $at = strpos($css, '/* ' . $name . ': ');
        if ($at !== false) {
            $start = $at;
            break;
        }
    }
    assert_true($start !== false, 'no preset stylesheet in notebook.css');

    return substr($css, (int) $start, strpos($css, '@property', (int) $start) - (int) $start);
}

test('every preset name has a stylesheet and every stylesheet a name', function (): void {
    $files = array_map(static fn(string $f): string => basename($f, '.css'), glob(PRESETS_ROOT . '/theme/presets/*.css') ?: []);
    sort($files);
    $names = Config::PRESETS;
    sort($names);
    assert_same($names, $files);
    assert_same('neutral', Config::PRESETS[0], 'neutral is the default');
});

$neutralCss = null;
foreach (Config::PRESETS as $preset) {
    test("preset {$preset}: the demo builds, <html> names it and notebook.css carries it", function () use ($preset, &$neutralCss): void {
        $out = presets_temp();
        [$code, , $err] = presets_cli([
            'build', '--config', PRESETS_DEMO_CONFIG, '--out', $out, '--only', '/guide/installation', '--quiet',
            '--set', 'theme.preset=' . $preset,
        ]);
        assert_same(0, $code, $err);
        assert_contains(' data-preset="' . $preset . '"', (string) file_get_contents($out . '/guide/installation/index.html'));

        $css = presets_stylesheet($out);
        $sheet = rtrim((string) file_get_contents(PRESETS_ROOT . '/theme/presets/' . $preset . '.css'));
        assert_contains($sheet, $css);
        assert_true(!str_contains($css, '/* @pholio:palette */'), 'marker replaced');

        if ($preset === 'neutral') {
            $neutralCss = $css;
            assert_true(!str_contains($sheet, '{'), 'neutral declares nothing');
            return;
        }
        $light = presets_tokens(presets_palette($css), ':root');
        $dark = presets_tokens(presets_palette($css), '.dark');
        assert_true(isset($light['primary']), "{$preset}: :root sets --color-fd-primary");
        assert_true(isset($dark['primary']), "{$preset}: .dark sets --color-fd-primary");
        assert_true($neutralCss === null || $css !== $neutralCss, "{$preset}: differs from neutral");
    });
}

test('an unknown preset exits 2 and lists the presets', function (): void {
    [$code, , $err] = presets_cli(['build', '--config', PRESETS_DEMO_CONFIG, '--out', presets_temp(), '--set', 'theme.preset=sepia']);
    assert_same(2, $code, $err);
    assert_contains('theme.preset', $err);
    assert_contains('ocean', $err);
});

test('theme.light, theme.dark and theme.palette_css come after the preset', function (): void {
    $dir = presets_temp();
    mkdir($dir . '/content');
    file_put_contents($dir . '/content/index.md', "---\ntitle: Home\n---\n\nText.\n");
    file_put_contents($dir . '/palette.css', "/* site palette */\n:root { --color-fd-card: white; }\n");
    file_put_contents($dir . '/pholio.config.php', "<?php\nreturn ['title' => 'Site', 'theme' => ["
        . "'preset' => 'ocean', 'light' => ['primary' => 'rebeccapurple'], 'dark' => ['primary' => 'gold'], 'palette_css' => 'palette.css']];\n");
    [$code, , $err] = presets_cli(['build', $dir, '--quiet']);
    assert_same(0, $code, $err);

    $css = presets_stylesheet($dir . '/public');
    $preset = strpos($css, '/* ocean: ');
    $light = strpos($css, "--color-fd-primary: rebeccapurple;");
    $dark = strpos($css, "--color-fd-primary: gold;");
    $file = strpos($css, '/* site palette */');
    assert_true($preset !== false && $light !== false && $dark !== false && $file !== false, 'all four blocks present');
    assert_true($preset < $light && $light < $dark && $dark < $file, 'order: preset, theme.light, theme.dark, palette_css');
});
