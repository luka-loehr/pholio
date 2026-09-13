<?php

declare(strict_types=1);

// src/lib/Icons.php against vendor-data/lucide/icons.json and lucide-react:
// data file and licence, fixed markup captured from lucide-react, the
// fill="currentColor" special case of the theme switch, unknown names with
// suggestions, and (Node tier) a seeded 50-name sample through verify/lucide-oracle.mjs.
// Repeat a sample with PHOLIO_ICON_SEED=<n>.

require __DIR__ . '/run.php';
require_once dirname(__DIR__) . '/src/lib/Icons.php';

use Pholio\ContentException;
use Pholio\Icons;

$root = dirname(__DIR__);
$data = json_decode((string) file_get_contents($root . '/vendor-data/lucide/icons.json'), true, 512, JSON_THROW_ON_ERROR);

const ICONS_HEAD = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ';

const ICONS_SUN = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-sun nd-theme-icon nd-theme-icon-active" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>';

const ICONS_MOON = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-moon nd-theme-icon" aria-hidden="true"><path d="M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.46.402.803a6 6 0 0 0 8.268 8.268c.344-.215.825-.004.803.401"/></svg>';

test('icons.json comes from lucide-react 1.45.0 with the complete set', function () use ($data): void {
    assert_same('lucide-react', $data['package']);
    assert_same('1.45.0', $data['version']);
    assert_same('1.45.0', Icons::version());
    assert_same(1834, count($data['icons']));
    assert_same(428, count($data['aliases']));
    assert_same('verify/tools/build-lucide-data.mjs - do not edit by hand', $data['_generated']);
});

test('lucide licence ships next to the data and in licenses/', function () use ($root): void {
    $license = (string) file_get_contents($root . '/vendor-data/lucide/LICENSE');
    assert_true(str_starts_with($license, 'ISC License'), 'vendor-data/lucide/LICENSE starts with ISC License');
    assert_same($license, (string) file_get_contents($root . '/licenses/lucide-ISC.txt'));
});

test('names are sorted, unique and include aliases', function () use ($data): void {
    $names = Icons::names();
    $sorted = $names;
    sort($sorted, SORT_STRING);
    assert_same($sorted, $names);
    assert_same(array_values(array_unique($names)), $names);
    assert_same(count($data['icons']) + count($data['aliases']), count($names));
    foreach (['archive', 'download', 'code', 'braces', 'pen-line', 'layout-grid', 'library', 'settings', 'terminal', 'text', 'sidebar', 'edit'] as $needed) {
        assert_true(in_array($needed, $names, true), "\"{$needed}\" is a valid name");
    }
});

test('canonical icon renders like lucide-react', function (): void {
    assert_same(
        ICONS_HEAD . 'class="lucide lucide-archive" aria-hidden="true"><rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/></svg>',
        Icons::svg('archive'),
    );
});

test('alias classes belong to the icon, extra classes are merged without duplicates', function (): void {
    $expected = ICONS_HEAD . 'class="lucide lucide-panel-left lucide-sidebar size-4" aria-hidden="true"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/></svg>';
    assert_same($expected, Icons::svg('sidebar', 'size-4'));
    assert_same($expected, Icons::svg('panel-left', 'lucide  size-4 size-4 lucide-sidebar'));
});

test('class attribute is escaped', function (): void {
    assert_contains('class="lucide lucide-square-pen lucide-pen-box lucide-edit lucide-pen-square a&quot;b"', Icons::svg('edit', 'a"b'));
});

test('fill special case: only the root fill changes to currentColor', function (): void {
    [$sun, $moon] = [ICONS_SUN, ICONS_MOON];
    $filled = static fn(string $name, string $class): string => (string) preg_replace('/ fill="none"/', ' fill="currentColor"', Icons::svg($name, $class), 1);
    assert_same($sun, $filled('sun', 'nd-theme-icon nd-theme-icon-active'));
    assert_same($moon, $filled('moon', 'nd-theme-icon'));
});

test('theme switch component renders the filled icons', function () use ($root): void {
    [$sun, $moon] = [ICONS_SUN, ICONS_MOON];
    $component = $root . '/src/components/theme-switch.php';
    if (!is_file($component)) {
        skip('src/components/theme-switch.php not present');
    }
    require_once $component;
    assert_same($sun, \Pholio\nd_icon_filled('sun', 'nd-theme-icon nd-theme-icon-active'));
    assert_same($moon, \Pholio\nd_icon_filled('moon', 'nd-theme-icon'));
});

test('unknown names throw ContentException with the 5 closest names', function (): void {
    $names = Icons::names();
    foreach (['not-a-lucide-icon', 'BookOpen', 'php', 'Archive', 'archive ', ''] as $unknown) {
        assert_true(!in_array($unknown, $names, true), "\"{$unknown}\" is not a name");
        $e = assert_throws(ContentException::class, static fn() => Icons::svg($unknown), 'unknown icon "' . $unknown . '"');
        $suggestions = Icons::closest($unknown, 5);
        assert_same(5, count($suggestions));
        assert_contains('; did you mean: ' . implode(', ', $suggestions) . '.', $e->getMessage());
        assert_same(3, $e->exitCode());
    }
    assert_same(['archive', 'archive-x', 'anchor', 'armchair', 'barcode'], Icons::closest('Archive'));
    assert_same(['book-open', 'book-key', 'door-open', 'lock-open', 'book'], Icons::closest('BookOpen'));
});

test('Node tier: 50-name sample matches lucide-react', function () use ($root): void {
    if (!is_dir($root . '/verify/node_modules/lucide-react')) {
        skip('node: verify/node_modules/lucide-react missing (cd verify && npm ci)');
    }
    $seed = getenv('PHOLIO_ICON_SEED');
    $seed = $seed !== false && $seed !== '' ? (int) $seed : random_int(1, 2 ** 31 - 1);
    $process = proc_open(
        ['node', $root . '/verify/lucide-oracle.mjs', '--check', '--sample', '50', '--seed', (string) $seed, '--php', PHP_BINARY],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        skip('node: not runnable');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    echo '     ', trim($stdout), "\n";
    assert_same(0, $code, "lucide oracle failed (repeat with PHOLIO_ICON_SEED={$seed})\n{$stderr}");
    assert_contains('lucide oracle: 50/50', $stdout);
});
