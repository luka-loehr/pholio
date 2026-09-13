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
