<?php

declare(strict_types=1);

require __DIR__ . '/run.php';
require __DIR__ . '/../src/I18n.php';

use Pholio\ConfigException;
use Pholio\I18n;

test('English is the default and every value is the key without its notes', function (): void {
    I18n::use('en');
    assert_same('en', I18n::language());
    foreach (I18n::all() as $key => $text) {
        if ($key === 'displayName') {
            assert_same('English', $text);
            continue;
        }
        $expected = preg_replace('/(\([^()]*\))+$/', '', $key);
        if ($key === 'Last updated(page)') {
            $expected = 'Last updated: ';
        }
        assert_same($expected, $text, $key);
    }
});

test('every shipped language has exactly the English key set', function (): void {
    $keys = I18n::keys();
    sort($keys);
    foreach (I18n::languages() as $language) {
        $other = array_keys(I18n::load($language));
        sort($other);
        assert_same($keys, $other, $language);
    }
    assert_same(['de', 'en'], I18n::languages());
});

test('German table is selected and returns German texts', function (): void {
    I18n::use('de');
    assert_same('Suchen', I18n::t('Search(search trigger)'));
    assert_same('Stand: ', I18n::t('Last updated(page)'));
    assert_same('Öffnen', I18n::t('Open(home card)'));
    I18n::use('en');
});

test('overrides replace single entries', function (): void {
    I18n::use('en', ['Search(search trigger)' => 'Find']);
    assert_same('Find', I18n::t('Search(search trigger)'));
    assert_same('Search', I18n::t('Search(search dialog)'));
    I18n::use('en');
    assert_same('Search', I18n::t('Search(search trigger)'));
});

test('unknown key in code fails loud', function (): void {
    I18n::use('en');
    assert_throws(\LogicException::class, fn() => I18n::t('Nope(nowhere)'), 'Nope(nowhere)');
    assert_true(!I18n::has('Nope(nowhere)'));
});

test('unknown override key is a config error', function (): void {
    assert_throws(ConfigException::class, fn() => I18n::use('en', ['Serch(search trigger)' => 'x']), 'translations: unknown key');
});

test('unshipped language needs a complete translation table', function (): void {
    $e = assert_throws(ConfigException::class, fn() => I18n::use('fr'), 'no shipped translation');
    assert_same(2, $e->exitCode());
    $full = array_map(static fn(string $text): string => '[fr] ' . $text, I18n::load('en'));
    I18n::use('fr', $full);
    assert_same('fr', I18n::language());
    assert_same('[fr] Search', I18n::t('Search(search trigger)'));
    I18n::use('en');
});

test('German values equal the original table', function (): void {
    $source = getenv('PHOLIO_E_I18N');
    if ($source === false || $source === '') {
        skip('set PHOLIO_E_I18N to the original I18n.php to compare the German table');
    }
    if (!is_file($source)) {
        throw new \RuntimeException('PHOLIO_E_I18N does not exist: ' . $source);
    }
    // The original is a class with a private DE constant; read the constant's
    // array literal without executing the file.
    $code = (string) file_get_contents($source);
    if (preg_match('/const\s+DE\s*=\s*(\[.*?\n\s*\]);/s', $code, $m) !== 1) {
        throw new \RuntimeException('no DE table found in ' . $source);
    }
    $original = eval('return ' . $m[1] . ';');
    $german = I18n::load('de');
    foreach ($original as $key => $text) {
        assert_true(array_key_exists($key, $german), 'missing key in de.php: ' . $key);
        assert_same($text, $german[$key], $key);
    }
    $added = array_diff_key($german, $original);
    ksort($added);
    assert_same(['Back to reference(footnote)(aria-label)', 'Last updated(page)', 'Open(home card)'], array_keys($added));
    echo '     ', count($original), " original entries compared\n";
});

/**
 * The string tables of theme/js/i18n.js: language => key => text. Parses the
 * `STRINGS = { en: { 'key': 'text', ... }, de: { ... } }` literal line by line;
 * any line inside a table that is not a quoted pair is an error.
 *
 * @return array<string, array<string, string>>
 */
function js_i18n_tables(string $file): array
{
    $code = (string) file_get_contents($file);
    if (preg_match('/export const STRINGS = \{\n(.*?)\n\};/s', $code, $m) !== 1) {
        throw new \RuntimeException('no "export const STRINGS = {...};" literal in ' . $file);
    }
    $string = '(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")';
    $unquote = static fn(string $s): string => stripcslashes(substr($s, 1, -1));

    $tables = [];
    $language = null;
    foreach (explode("\n", $m[1]) as $number => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '//')) {
            continue;
        }
        if (preg_match('/^([a-z]{2,3}): \{$/', $line, $open) === 1 && $language === null) {
            $language = $open[1];
            $tables[$language] = [];
        } elseif ($line === '},' || $line === '}') {
            if ($language === null) {
                throw new \RuntimeException("unexpected closing brace in STRINGS, line {$number}");
            }
            $language = null;
        } elseif ($language !== null && preg_match('/^' . $string . ':\s*' . $string . ',?$/', $line, $pair) === 1) {
            $tables[$language][$unquote($pair[1])] = $unquote($pair[2]);
        } else {
            throw new \RuntimeException("cannot parse STRINGS line {$number}: {$line}");
        }
    }

    return $tables;
}

test('theme/js/i18n.js strings equal the PHP tables', function (): void {
    $file = __DIR__ . '/../theme/js/i18n.js';
    if (!is_file($file)) {
        throw new \RuntimeException('theme/js/i18n.js not found');
    }
    $tables = js_i18n_tables($file);
    assert_same(['en', 'de'], array_keys($tables), 'languages in theme/js/i18n.js');
    assert_true(count($tables['en']) > 0, 'parsed 0 keys from the en table of theme/js/i18n.js');
    assert_same(array_keys($tables['en']), array_keys($tables['de']), 'en and de key sets in theme/js/i18n.js');
    foreach ($tables as $language => $table) {
        $php = I18n::load($language);
        foreach ($table as $key => $text) {
            assert_true(array_key_exists($key, $php), "theme/js/i18n.js {$language}: key missing in src/i18n/{$language}.php: {$key}");
            assert_same($php[$key], $text, "theme/js/i18n.js {$language}: {$key}");
        }
    }
    echo '     ', count($tables['en']), " keys per language compared\n";
});

test('the JavaScript table parser fails loudly', function (): void {
    $dir = sys_get_temp_dir() . '/pholio-i18n-js-' . bin2hex(random_bytes(4));
    mkdir($dir);
    try {
        file_put_contents($dir . '/a.js', "export const OTHER = {};\n");
        assert_throws(\RuntimeException::class, fn() => js_i18n_tables($dir . '/a.js'), 'no "export const STRINGS');
        file_put_contents($dir . '/b.js', "export const STRINGS = {\n  en: {\n    'Search(search dialog)': t('x'),\n  },\n};\n");
        assert_throws(\RuntimeException::class, fn() => js_i18n_tables($dir . '/b.js'), 'cannot parse');
        file_put_contents($dir . '/c.js', "export const STRINGS = {\n  en: {\n    \"It\\'s(x)\": 'It\\'s',\n  },\n};\n");
        assert_same(['en' => ["It's(x)" => "It's"]], js_i18n_tables($dir . '/c.js'));
    } finally {
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
});
