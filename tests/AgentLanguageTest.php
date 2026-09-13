<?php

declare(strict_types=1);

require __DIR__ . '/run.php';
require __DIR__ . '/../src/Fs.php';
require __DIR__ . '/../src/I18n.php';
require __DIR__ . '/../src/lib/LlmsTxt.php';

use Pholio\Fs;
use Pholio\I18n;
use Pholio\LlmsTxt;

/**
 * The files for agents follow the site language: a German site gets no English
 * headings or fixed sentences in its twins, llms.txt, llms-full.txt, skills and
 * agent card. Every "(agent files)" key whose German text differs from the English
 * one is checked: no English fragment of it may appear in the output.
 */

function language_temp(): string
{
    $dir = Fs::tempDir('pholio-agent-language-test-');
    register_shutdown_function(static fn() => Fs::removeDir($dir));

    return $dir;
}

/**
 * English fragments of the agent file texts, split at their placeholders, with the
 * key they come from; texts that read the same in German are left out.
 *
 * @return array<string, string> fragment => key
 */
function language_english_fragments(): array
{
    $en = I18n::load('en');
    $de = I18n::load('de');
    $fragments = [];
    foreach ($en as $key => $text) {
        if (!str_ends_with((string) $key, '(agent files)') || $text === $de[$key]) {
            continue;
        }
        foreach (preg_split('/\{[A-Za-z]+\}/', $text) ?: [] as $part) {
            $part = trim($part, " .,;:\"`#");
            if (mb_strlen($part) >= 4 && preg_match('/\p{L}/u', $part) === 1) {
                $fragments[$part] = (string) $key;
            }
        }
    }

    return $fragments;
}

/**
 * English fragments found in $text, as "fragment (key)"; a fragment only counts as a
 * word of its own, not inside a URL or file name such as `-part-1.md`.
 *
 * @return list<string>
 */
function language_english_in(string $text): array
{
    $found = [];
    foreach (language_english_fragments() as $fragment => $key) {
        if (preg_match('/(?<![\p{L}\p{N}\/_-])' . preg_quote($fragment, '/') . '(?![\p{L}\p{N}_-])/u', $text) === 1) {
            $found[] = $fragment . ' (' . $key . ')';
        }
    }

    return $found;
}

test('every agent file text has a German translation of its own', function (): void {
    $keys = array_filter(array_keys(I18n::load('en')), static fn(string $key): bool => str_ends_with($key, '(agent files)'));
    assert_true(count($keys) >= 30, 'agent file keys: ' . count($keys));
    assert_true(count(language_english_fragments()) >= 30, 'fragments: ' . count(language_english_fragments()));
});

test('German llms.txt split indexes: headings, counts, parts and notes in German', function (): void {
    I18n::use('de');
    try {
        $page = static fn(int $i): array => ['page' => ['title' => 'Seite ' . $i, 'url' => '/s' . $i . '.md', 'summary' => str_repeat('Wort ', 10)]];
        $deep = ['name' => 'Tiefer', 'slug' => 'tiefer', 'description' => null, 'items' => array_map($page, range(1, 30))];
        $section = ['name' => 'Anleitung', 'slug' => 'anleitung', 'description' => 'Alles', 'items' => [$page(0), ['group' => $deep]]];
        $files = LlmsTxt::files('Handbuch', 'Über das Handbuch', 'Antworte kurz.', [$section], [], static fn(string $path): string => '/docs/' . $path, 600);
    } finally {
        I18n::use('en');
    }

    assert_contains("## Hinweise für Agenten\n\nAntworte kurz.", $files['llms.txt']);
    assert_contains('## Bereiche', $files['llms.txt']);
    assert_contains('- [Anleitung](/docs/_llms/anleitung.md): 31 Seiten. Alles', $files['llms.txt']);
    assert_contains('Die 31 Seiten dieser Dokumentation passen nicht in einen Index mit 600 Zeichen', $files['llms.txt']);
    assert_contains('- [Tiefer, Teil 1 von 5](/docs/_llms/anleitung/tiefer-part-1.md): 7 Einträge', $files['_llms/anleitung/tiefer.md']);
    assert_contains("# Tiefer, Teil 5 von 5\n", $files['_llms/anleitung/tiefer-part-5.md']);
    assert_contains("30 Seiten.\n\nDieser Bereichsindex listet", $files['_llms/anleitung/tiefer.md']);
    $english = language_english_in(implode("\n", $files));
    assert_same([], $english, 'English in the German split indexes');
});

test('a German site builds its files for agents without English fixed strings', function (): void {
    $dir = language_temp();
    $files = [
        'content/meta.json' => '{"title": "Handbuch", "pages": ["anleitung"]}',
        'content/anleitung/meta.json' => '{"title": "Anleitung", "description": "Erste Schritte.", "pages": ["installation", "konfiguration"]}',
        'content/anleitung/installation.md' => "---\ntitle: Installation\ndescription: So wird das Programm eingerichtet.\n---\n\n"
            . "Zuerst wird das Archiv entpackt.\n\n"
            . "<Callout type=\"info\" title=\"Hinweis\">\nNur einmal nötig.\n</Callout>\n\n"
            . "<Callout type=\"tip\">\nSchneller mit dem Skript.\n</Callout>\n\n"
            . "<Callout type=\"warning\">\nVorher sichern.\n</Callout>\n\n"
            . "<Callout type=\"error\">\nDas löscht alles.\n</Callout>\n\n"
            . "<Callout type=\"success\">\nFertig eingerichtet.\n</Callout>\n\n"
            . "<Callout type=\"idea\">\nAutomatisch starten.\n</Callout>\n\n"
            . "<TypeTable>\n  <TypeProp name=\"pfad\" type=\"string\" default=\"/opt\" required>\nZielordner.\n  </TypeProp>\n"
            . "  <TypeProp name=\"alt\" type=\"bool\" deprecated>\nNicht mehr nutzen.\n  </TypeProp>\n</TypeTable>\n",
        'content/anleitung/konfiguration.md' => "---\ntitle: Konfiguration\n---\n\nDie Einstellungen stehen in einer Datei.\n",
        'content/ausserhalb.md' => "---\ntitle: Außerhalb\n---\n\nDiese Seite steht nicht in der Navigation.\n",
        'pholio.config.php' => "<?php\nreturn " . var_export([
            'title' => 'Handbuch',
            'language' => 'de',
            'site' => ['url' => 'https://handbuch.example.org'],
            'agents' => ['instructions' => 'Antworte auf Deutsch.'],
        ], true) . ";\n",
    ];
    foreach ($files as $path => $content) {
        Fs::write($dir, $path, $content);
    }

    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/../bin/pholio', 'build', $dir, '--quiet'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    assert_same(0, proc_close($process), $err);

    $out = $dir . '/public';
    $read = static function (string $path) use ($out): string {
        assert_true(is_file($out . '/' . $path), 'missing: ' . $path);

        return (string) file_get_contents($out . '/' . $path);
    };
    $twin = $read('anleitung/installation.md');
    assert_true(str_starts_with($twin, "> Dokumentationsindex: https://handbuch.example.org/llms.txt, eine Liste aller Seiten dieser Dokumentation.\n"), $twin);
    assert_contains('> **Warnung**', $twin);
    assert_contains('`string`, Standardwert `/opt`, erforderlich', $twin);
    assert_contains('`bool`, veraltet', $twin);
    assert_contains("## Verwandte Themen\n\n- [Konfiguration]", $twin);
    assert_contains('- Nächste Seite: [Konfiguration]', $twin);
    assert_contains('- Vorherige Seite: [Installation]', $read('anleitung/konfiguration.md'));

    $llms = $read('llms.txt');
    assert_contains("## Hinweise für Agenten\n\nAntworte auf Deutsch.", $llms);
    assert_contains('## Weitere Seiten', $llms);
    assert_contains("Quelle: https://handbuch.example.org/anleitung/installation", $read('llms-full.txt'));

    $skill = $read('skill.md');
    foreach (['## Inhalt der Dokumentation', '## Seiten abrufen', '## Suchen', '(2 Seiten)', 'Dokumentation zu Handbuch.'] as $german) {
        assert_contains($german, $skill);
    }
    // The agent card's keys and machine values (the `documentation` tag, the media types) belong to the
    // A2A specification and stay English; its descriptions are text for readers.
    $card = json_decode($read('.well-known/agent-card.json'), true, 512, JSON_THROW_ON_ERROR);
    assert_same('0.3', $card['protocolVersion'], 'spec field names stay English');
    assert_same(['documentation'], $card['skills'][0]['tags'], 'machine tags stay English');
    assert_contains('Dokumentation zu Handbuch.', $card['skills'][0]['description']);

    $all = implode("\n", [
        $twin, $read('anleitung/konfiguration.md'), $read('ausserhalb.md'), $llms, $read('llms-full.txt'), $skill,
        $read('.well-known/agent-skills/handbuch/SKILL.md'), $read('.well-known/skills/handbuch/SKILL.md'),
        (string) $card['description'], ...array_column($card['skills'], 'description'),
    ]);
    assert_same([], language_english_in($all), 'English fixed strings in the files of a German site');
});

test('an English site keeps the English texts', function (): void {
    I18n::use('en');
    assert_same('Related topics', LlmsTxt::t('Related topics(agent files)'));
    assert_same('12 pages', LlmsTxt::pages(12));
    assert_same('1 page', LlmsTxt::pages(1));
});
