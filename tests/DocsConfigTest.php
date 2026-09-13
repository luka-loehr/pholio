<?php

declare(strict_types=1);

require __DIR__ . '/run.php';
require __DIR__ . '/../src/Config.php';

use Pholio\Config;

/**
 * The key tables in docs/configuration.md and the schema in src/Config.php
 * describe the same keys. A list item's keys are written `nav[].title`; nested
 * structs that only group keys (`content`, `theme`, …) have no row of their own.
 */

/** @return list<string> */
function schema_keys(array $struct, string $prefix = ''): array
{
    $keys = [];
    foreach ($struct as $key => $spec) {
        $path = $prefix . $key;
        if (is_array($spec) && !is_string($spec[0] ?? null)) {
            array_push($keys, ...schema_keys($spec, $path . '.'));
            continue;
        }
        $keys[] = $path;
        if (is_array($spec) && $spec[0] === 'list') {
            array_push($keys, ...schema_keys($spec[1], $path . '[].'));
        } elseif (is_array($spec) && $spec[0] === 'nullable') {
            array_push($keys, ...schema_keys($spec[1], $path . '.'));
        }
    }

    return $keys;
}

/** @return array<string, string> key => table row, in document order */
function documented_keys(): array
{
    $rows = [];
    $source = (string) file_get_contents(__DIR__ . '/../docs/configuration.md');
    preg_match_all('/^\| `([a-z_.\[\]]+)` \|.*$/m', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as [$row, $key]) {
        if (isset($rows[$key])) {
            throw new TestFailure("docs/configuration.md documents {$key} twice");
        }
        $rows[$key] = $row;
    }

    return $rows;
}

function private_static(string $method): mixed
{
    return (new ReflectionMethod(Config::class, $method))->invoke(null);
}

test('every schema key is documented and every documented key exists', function (): void {
    $schema = schema_keys(private_static('schema'));
    $documented = array_keys(documented_keys());
    assert_same([], array_values(array_diff($schema, $documented)), 'in src/Config.php but not in docs/configuration.md');
    assert_same([], array_values(array_diff($documented, $schema)), 'in docs/configuration.md but not in src/Config.php');
});

test('planned keys are marked planned in the docs, and only those', function (): void {
    $planned = array_map(static fn(array $entry): string => str_replace('.[].', '[].', implode('.', $entry[0])), private_static('planned'));
    $rows = documented_keys();
    foreach ($planned as $key) {
        assert_contains('Planned', $rows[$key] ?? '', "{$key} is planned");
    }
    foreach ($rows as $key => $row) {
        if (!in_array($key, $planned, true)) {
            assert_true(!str_contains($row, 'Planned'), "{$key} is implemented but documented as planned");
        }
    }
});

test('the example configuration is valid and uses only documented keys', function (): void {
    $config = Config::load(__DIR__ . '/../pholio.config.example.php');
    assert_same('/', $config['homeUrl']);
    Config::load(__DIR__ . '/../pholio.config.example.php', 'staging');

    $raw = require __DIR__ . '/../pholio.config.example.php';
    $documented = array_keys(documented_keys());
    $walk = static function (array $value, string $prefix) use (&$walk, $documented): void {
        foreach ($value as $key => $child) {
            $path = $prefix . $key;
            if (in_array($path, ['translations', 'copy', 'profiles', 'redirects', 'theme.light', 'theme.dark', 'content.frontmatter_aliases'], true)) {
                continue;
            }
            if (is_array($child) && $child !== [] && array_is_list($child) && is_array($child[0])) {
                assert_true(in_array($path, $documented, true), "example uses undocumented {$path}");
                foreach ($child as $item) {
                    $walk($item, $path . '[].');
                }
            } elseif (is_array($child) && $child !== [] && !array_is_list($child)) {
                $walk($child, $path . '.');
            } else {
                assert_true(in_array($path, $documented, true), "example uses undocumented {$path}");
            }
        }
    };
    $walk($raw, '');
});
