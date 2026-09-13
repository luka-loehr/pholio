<?php

declare(strict_types=1);

require __DIR__ . '/run.php';
require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Fs.php';
require __DIR__ . '/../src/AgentHeaders.php';
require __DIR__ . '/../src/Htaccess.php';

use Pholio\AgentHeaders;
use Pholio\Fs;
use Pholio\Htaccess;

/**
 * How servers hand Markdown to agents: text/plain for OpenAI's agents, which reject
 * text/markdown, the ChatGPT and Claude prompts of the page menu, and only generated
 * Markdown files served, in the .htaccess, pholio dev and the Cloudflare Worker example.
 */

const SERVING_ROOT = __DIR__ . '/..';
const SERVING_CHATGPT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot';

function serving_temp(): string
{
    $dir = Fs::tempDir('pholio-serving-test-');
    register_shutdown_function(static fn() => Fs::removeDir($dir));

    return $dir;
}

/** @return array{0:int, 1:array<string,string>, 2:string} status, lower-case headers, body */
function serving_http(string $url, array $headers = []): array
{
    $context = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 5]]);
    $body = (string) @file_get_contents($url, false, $context);
    $lines = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    $status = 0;
    $response = [];
    foreach ($lines as $line) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m) === 1) {
            $status = (int) $m[1];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $response[strtolower(trim($name))] = trim($value);
        }
    }

    return [$status, $response, $body];
}

test('content type: OpenAI agents get text/plain, everyone else text/markdown', function (): void {
    foreach ([SERVING_CHATGPT, 'Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)', 'Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot)'] as $agent) {
        assert_same(AgentHeaders::PLAIN_TYPE, AgentHeaders::twinContentType('markdown', $agent), $agent);
    }
    foreach (['Claude-User', 'ClaudeBot/1.0', 'PerplexityBot', 'curl/8.7', ''] as $agent) {
        assert_same(AgentHeaders::MARKDOWN_TYPE, AgentHeaders::twinContentType('markdown', $agent), $agent);
    }
    assert_same(AgentHeaders::PLAIN_TYPE, AgentHeaders::twinContentType('plain', 'Claude-User'));
    assert_same('markdown', AgentHeaders::negotiate('*/*', SERVING_CHATGPT));
});

test('generated Markdown: twins, skill.md, _llms/ and .well-known/ are served, other .md files are not', function (): void {
    $files = ['index.html', 'guide/index.html', 'guide/intro/index.html', 'about.html'];
    $exists = static fn(string $path): bool => in_array($path, $files, true);
    foreach (['index.md', 'guide.md', 'guide/intro.md', 'skill.md', '_llms/guide.md', '.well-known/agent-skills/site/SKILL.md', 'llms.txt'] as $path) {
        assert_true(AgentHeaders::servesMarkdown($path, $exists), $path);
    }
    foreach (['notes.md', 'guide/stray.md', 'files/README.md', 'guide/index.md', 'about.md', 'SKILL.md'] as $path) {
        assert_true(!AgentHeaders::servesMarkdown($path, $exists), $path);
    }
});

test('.htaccess: text/plain for OpenAI agents and a 404 for Markdown the build did not write', function (): void {
    $htaccess = Htaccess::render('/manuals/', [], "default-src 'self'", [['Vary', 'Accept, User-Agent']], true);
    assert_contains("RewriteCond %{HTTP_USER_AGENT} (?:ChatGPT\\-User|OAI\\-SearchBot|GPTBot) [NC]\n    RewriteRule \\.md$ - [NC,E=PHOLIO_PLAIN:1]", $htaccess);
    assert_contains('Header set Content-Type "text/plain; charset=utf-8" env=PHOLIO_PLAIN', $htaccess);
    assert_contains('Header set Content-Type "text/plain; charset=utf-8" env=REDIRECT_PHOLIO_PLAIN', $htaccess);
    assert_contains(
        "RewriteCond %{REQUEST_URI} !^/manuals/(?:index\\.md$|skill\\.md$|_llms/|\\.well-known/)\n"
        . "    RewriteCond %{REQUEST_FILENAME} ^(.+)\\.md$ [NC]\n"
        . "    RewriteCond %1/index.html !-f\n"
        . "    RewriteRule \\.md$ - [NC,R=404,L]",
        $htaccess,
    );
    // The rules come before the negotiation, whose internal rewrite to x.md passes through them again.
    assert_true(strpos($htaccess, 'E=PHOLIO_PLAIN:1]') < strpos($htaccess, '$1.md [L]'));
    // Without negotiation stray Markdown is still blocked.
    assert_contains('R=404,L]', Htaccess::render('/', [], "default-src 'self'"));
});

test('_headers names the limit of static hosts for OpenAI agents', function (): void {
    $config = Pholio\Config::fromArray([], sys_get_temp_dir());
    assert_contains("OpenAI's agents (ChatGPT-User, OAI-SearchBot, GPTBot)", (string) AgentHeaders::file($config));
});

test('the Cloudflare Worker example uses the user agent lists of AgentHeaders', function (): void {
    $worker = (string) file_get_contents(SERVING_ROOT . '/examples/cloudflare-worker/worker.js');
    assert_true(preg_match('#^const AGENTS = /([^/]+)/i;$#m', $worker, $agents) === 1, 'AGENTS in worker.js');
    assert_true(preg_match('#^const PLAIN_AGENTS = /([^/]+)/i;$#m', $worker, $plain) === 1, 'PLAIN_AGENTS in worker.js');
    assert_same(implode('|', AgentHeaders::USER_AGENTS), $agents[1]);
    assert_same(implode('|', AgentHeaders::PLAIN_USER_AGENTS), $plain[1]);
});

test('a site under /manuals: prompts, content types per user agent and stray Markdown in pholio dev', function (): void {
    $dir = serving_temp();
    Fs::write($dir . '/content', 'index.md', "---\ntitle: Start\n---\n\nWelcome.\n");
    Fs::write($dir . '/content', 'guide/intro.md', "---\ntitle: Intro\n---\n\nThe first paragraph.\n");
    Fs::write($dir . '/files', 'notes.md', "# Internal notes\n");
    Fs::write($dir . '/files', 'chart.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>');
    file_put_contents($dir . '/pholio.config.php', "<?php\nreturn " . var_export([
        'title' => 'Handbook', 'base_path' => '/manuals', 'copy' => ['files' => '{home}/files'],
    ], true) . ";\n");

    $out = $dir . '/root/manuals';
    $process = proc_open(
        [PHP_BINARY, SERVING_ROOT . '/bin/pholio', 'build', '--config', $dir . '/pholio.config.php', '--out', $out, '--quiet'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    assert_same(0, proc_close($process), $err);

    $html = (string) file_get_contents($out . '/guide/intro/index.html');
    assert_contains('data-prompt="Read {url}, I want to ask questions about it."', $html);
    assert_contains('data-prompt-chatgpt="Read {url}, I want to ask questions about it. A Markdown version of the page is at {markdown}."', $html);
    assert_contains('R=404,L]', (string) file_get_contents($out . '/.htaccess'));

    // Markdown the build did not write: a stray file in the output and one in a copied directory.
    assert_true(!file_exists($out . '/files/notes.md'), 'copy leaves Markdown out');
    file_put_contents($out . '/files/notes.md', "# Internal notes\n");
    file_put_contents($out . '/guide/stray.md', "# Stray\n");

    $port = 21000 + random_int(0, 20000);
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $dir . '/root', SERVING_ROOT . '/src/DevServer.php'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        null,
        getenv() + ['PHOLIO_HOME_URL' => '/manuals'],
    );
    try {
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(100000);
        }
        $base = 'http://127.0.0.1:' . $port . '/manuals';
        $twin = (string) file_get_contents($out . '/guide/intro.md');

        $cases = [
            [SERVING_CHATGPT, '/guide/intro.md', [], AgentHeaders::PLAIN_TYPE],
            ['OAI-SearchBot/1.0', '/guide/intro.md', [], AgentHeaders::PLAIN_TYPE],
            ['GPTBot/1.1', '/guide/intro.md', [], AgentHeaders::PLAIN_TYPE],
            ['ClaudeBot/1.0', '/guide/intro.md', [], AgentHeaders::MARKDOWN_TYPE],
            ['curl/8.7', '/guide/intro.md', [], AgentHeaders::MARKDOWN_TYPE],
            [SERVING_CHATGPT, '/guide/intro', [], AgentHeaders::PLAIN_TYPE],
            ['GPTBot/1.1', '/guide/intro', ['Accept: text/markdown'], AgentHeaders::PLAIN_TYPE],
            ['Claude-User', '/guide/intro', [], AgentHeaders::MARKDOWN_TYPE],
            ['curl/8.7', '/guide/intro', ['Accept: text/markdown'], AgentHeaders::MARKDOWN_TYPE],
        ];
        foreach ($cases as [$agent, $path, $headers, $type]) {
            [$status, $response, $body] = serving_http($base . $path, array_merge(['User-Agent: ' . $agent], $headers));
            assert_same(200, $status, "{$agent} {$path}");
            assert_same($type, $response['content-type'] ?? null, "{$agent} {$path}");
            assert_same($twin, $body, "{$agent} {$path}");
        }
        [, $response] = serving_http($base . '/guide/intro', ['User-Agent: ' . SERVING_CHATGPT, 'Accept: text/html']);
        assert_same(AgentHeaders::PLAIN_TYPE, $response['content-type'] ?? null, 'ChatGPT-User is an assistant: the twin, as text/plain');
        [, $response] = serving_http($base . '/guide/intro', ['User-Agent: GPTBot/1.1', 'Accept: text/html']);
        assert_contains('text/html', $response['content-type'] ?? '', 'GPTBot without Accept: text/markdown reads HTML');

        foreach (['/index.md', '/guide/intro.md', '/skill.md', '/llms.txt', '/files/chart.svg'] as $path) {
            assert_same(200, serving_http($base . $path)[0], $path);
        }
        foreach (['/files/notes.md', '/guide/stray.md', '/guide/index.md'] as $path) {
            [$status, $response] = serving_http($base . $path, ['User-Agent: ' . SERVING_CHATGPT]);
            assert_same(404, $status, $path);
            assert_contains('rel="llms-txt"', $response['link'] ?? '', $path . ' keeps the discovery headers');
        }
    } finally {
        proc_terminate($server);
        proc_close($server);
    }
});
