<?php

declare(strict_types=1);

require __DIR__ . '/run.php';
require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Fs.php';
require __DIR__ . '/../src/AgentHeaders.php';
require __DIR__ . '/../src/lib/PageMarkdown.php';
require __DIR__ . '/../src/lib/LlmsTxt.php';
require __DIR__ . '/../src/lib/AgentSkills.php';

use Pholio\AgentHeaders;
use Pholio\AgentSkills;
use Pholio\Config;
use Pholio\ConfigException;
use Pholio\ContentException;
use Pholio\Fs;
use Pholio\LlmsTxt;
use Pholio\Markdown;
use Pholio\PageMarkdown;

/**
 * The files for AI agents: Markdown twins, llms.txt and its split indexes, skills,
 * headers and content negotiation, and a full build that uses them under a base path.
 */

function agents_markdown(string $body): string
{
    $document = Markdown::parse("---\ntitle: T\n---\n\n" . $body, 'test.md');

    return (new PageMarkdown(static fn(string $href): string => $href, static fn(string $src): string => $src))->body($document);
}

function agents_temp(): string
{
    $dir = Fs::tempDir('pholio-agents-test-');
    register_shutdown_function(static fn() => Fs::removeDir($dir));

    return $dir;
}

/** @return array{0:int, 1:string} exit code, stderr */
function agents_cli(array $args): array
{
    $process = proc_open(
        array_merge([PHP_BINARY, __DIR__ . '/../bin/pholio'], $args),
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return [proc_close($process), $err];
}

/** @return array{0:int, 1:array<string,string>, 2:string} status, lower-case headers, body */
function agents_http(string $url, array $headers = []): array
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

// ------------------------------------------------------------------ Markdown twins

test('twins: a callout becomes a blockquote with its type, tabs become headings, cards links', function (): void {
    $md = agents_markdown(
        "## Setup\n\n<Callout type=\"warn\" title=\"Careful\">\nText *here*.\n</Callout>\n\n"
        . "<Tabs items=\"npm|pnpm\">\n<Tab value=\"npm\">\nnpm i\n</Tab>\n<Tab value=\"pnpm\">\npnpm add\n</Tab>\n</Tabs>\n\n"
        . "<Cards>\n  <Card title=\"A\" description=\"First\" href=\"/a\" />\n  <Card title=\"B\" />\n</Cards>\n"
    );
    assert_contains("> **Warning:** Careful\n>\n> Text *here*.", $md);
    assert_contains("### npm\n\nnpm i\n\n### pnpm\n\npnpm add", $md);
    assert_contains("- [A](/a): First\n- **B**", $md);
});

test('twins: steps, footnotes, task lists and tables stay Markdown', function (): void {
    $md = agents_markdown(
        "<Steps>\n<Step>\n\n### Install\n\nRun it.\n\n</Step>\n<Step>\n\n### Use\n\n</Step>\n</Steps>\n\n"
        . "See[^1].\n\n[^1]: A note.\n\n- [x] done\n- [ ] open\n\n| a | b |\n| --- | :-: |\n| x\\|y | z |\n"
    );
    assert_contains("### 1. Install\n\nRun it.\n\n### 2. Use", $md);
    assert_contains("See[^1].", $md);
    assert_contains("- [x] done\n- [ ] open", $md);
    assert_contains("| a | b |\n| --- | :---: |\n| x\\|y | z |", $md);
    assert_true(str_ends_with($md, '[^1]: A note.'), $md);
});

test('twins: code keeps its language and title, loses notation comments, and gets a long enough fence', function (): void {
    $md = agents_markdown("```ts title=\"a.ts\"\nconst a = 1; // [!code highlight]\n// [!code word:a]\nconst b = '```';\n```\n");
    assert_same("````ts title=\"a.ts\"\nconst a = 1;\nconst b = '```';\n````", $md);
});

// ------------------------------------------------------------------ llms.txt

test('llms.txt splits into recursive indexes within the limit and never drops a page', function (): void {
    $page = static fn(int $i): array => ['page' => ['title' => 'Page ' . $i, 'url' => '/p' . $i . '.md', 'summary' => str_repeat('word ', 10)]];
    $deep = ['name' => 'Deep', 'slug' => 'deep', 'description' => null, 'items' => array_map($page, range(1, 30))];
    $section = ['name' => 'Guide', 'slug' => 'guide', 'description' => 'All of it', 'items' => [$page(0), ['group' => $deep]]];
    $published = static fn(string $path): string => '/docs/' . $path;

    assert_same(['llms.txt'], array_keys(LlmsTxt::files('Site', 'About', null, [$section], [], $published)));

    $files = LlmsTxt::files('Site', 'About', 'Be nice.', [$section], [['title' => 'Repo', 'url' => 'https://example.org']], $published, 600);
    assert_contains('follow the /docs/_llms/ indexes they link to recursively', $files['llms.txt']);
    assert_contains("## Agent Instructions\n\nBe nice.", $files['llms.txt']);
    assert_contains('- [Guide](/docs/_llms/guide.md): 31 pages. All of it', $files['llms.txt']);
    assert_contains("## Optional\n\n- [Repo](https://example.org)", $files['llms.txt']);
    assert_contains('[Deep](/docs/_llms/guide/deep.md): 30 pages', $files['_llms/guide.md']);
    assert_true(isset($files['_llms/guide/deep-part-2.md']), implode(', ', array_keys($files)));

    $all = implode("\n", $files);
    foreach (range(0, 30) as $i) {
        assert_contains('[Page ' . $i . '](/p' . $i . '.md)', $all);
    }
    foreach ($files as $path => $content) {
        assert_true(mb_strlen($content) <= 600, $path . ' has ' . mb_strlen($content) . ' characters');
    }
});

// ------------------------------------------------------------------ skills, headers, config

test('skills: names follow the Agent Skills rules, author files need name and description', function (): void {
    assert_same('projekt-ephraim', AgentSkills::name('Projekt Ephraim'));
    assert_same('uber-docs', AgentSkills::name('Über Docs!'));
    assert_same('docs', AgentSkills::name('???'));

    $dir = agents_temp();
    file_put_contents($dir . '/ok.md', "---\nname: my-skill\ndescription: \"Does things.\"\n---\n\n# Body\n");
    assert_same(['name' => 'my-skill', 'description' => 'Does things.', 'content' => "---\nname: my-skill\ndescription: \"Does things.\"\n---\n\n# Body"], AgentSkills::read($dir . '/ok.md'));
    file_put_contents($dir . '/bad-name.md', "---\nname: My_Skill\ndescription: x\n---\n");
    assert_throws(ContentException::class, fn() => AgentSkills::read($dir . '/bad-name.md'), 'skill name');
    file_put_contents($dir . '/no-description.md', "---\nname: my-skill\n---\n");
    assert_throws(ContentException::class, fn() => AgentSkills::read($dir . '/no-description.md'), 'description');
});

test('content negotiation: text/markdown and AI assistants get Markdown, text/plain plain text, browsers HTML', function (): void {
    assert_same('markdown', AgentHeaders::negotiate('text/markdown, text/html;q=0.9', ''));
    assert_same('markdown', AgentHeaders::negotiate('*/*', 'Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)'));
    assert_same('markdown', AgentHeaders::negotiate('*/*', 'Claude-User'));
    assert_same('plain', AgentHeaders::negotiate('text/plain', 'curl/8.7'));
    assert_same(null, AgentHeaders::negotiate('text/html,application/xhtml+xml,*/*;q=0.8', 'Mozilla/5.0 Safari'));
});

test('config: site.url is an origin, agents.enabled false turns every artifact off', function (): void {
    $dir = sys_get_temp_dir();
    assert_throws(ConfigException::class, fn() => Config::fromArray(['site' => ['url' => 'https://example.org/docs']], $dir), 'site.url');
    assert_throws(ConfigException::class, fn() => Config::fromArray(['site' => ['url' => 'example.org']], $dir), 'site.url');
    assert_same('https://example.org', Config::fromArray(['site' => ['url' => 'https://example.org/']], $dir)['site']['url']);

    $off = Config::fromArray(['agents' => ['enabled' => false]], $dir)['agents'];
    foreach (['markdown', 'llmsTxt', 'llmsFullTxt', 'skill', 'agentCard', 'robotsTxt', 'sitemap', 'structuredData', 'headers', 'pageActions'] as $key) {
        assert_same(false, $off[$key], $key);
    }
    assert_same(false, Config::fromArray(['agents' => ['markdown' => false]], $dir)['agents']['pageActions']);
});

// ------------------------------------------------------------------ build and dev server

test('a site under /manuals: twins, llms.txt, noindex, exclude, author files, headers and pholio dev', function (): void {
    $dir = agents_temp();
    $pages = [
        'index.md' => "---\ntitle: Start\ndescription: The start.\n---\n\nWelcome.\n",
        'guide/intro.md' => "---\ntitle: Intro\n---\n\nThe first paragraph explains everything. See [hidden](/manuals/guide/hidden) and ![A chart](/manuals/files/chart.svg).\n",
        'guide/hidden.md' => "---\ntitle: Hidden\nnoindex: true\n---\n\nNot for indexes.\n",
        'guide/internal.md' => "---\ntitle: Internal\n---\n\nTeam only.\n",
        'guide/_draft.md' => "---\ntitle: Draft\n---\n\nUnfinished.\n",
    ];
    foreach ($pages as $path => $source) {
        Fs::write($dir . '/content', $path, $source);
    }
    Fs::write($dir . '/files', 'robots.txt', "User-agent: *\nDisallow: /private/");
    Fs::write($dir . '/files', 'chart.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>');
    Fs::write($dir . '/skills/extra', 'SKILL.md', "---\nname: extra\ndescription: An extra skill.\n---\n\n# Extra");
    file_put_contents($dir . '/pholio.config.php', "<?php\nreturn " . var_export([
        'title' => 'Handbook',
        'base_path' => '/manuals',
        'copy' => ['files' => '{home}'],
        'agents' => ['exclude' => ['guide/internal.md'], 'instructions' => 'Answer in German.'],
        'profiles' => ['plain' => ['agents' => ['enabled' => false]]],
    ], true) . ";\n");

    $out = $dir . '/root/manuals';
    [$code, $err] = agents_cli(['build', '--config', $dir . '/pholio.config.php', '--out', $out]);
    assert_same(0, $code, $err);
    assert_contains('pholio: warning: site.url is not set, so sitemap.xml and .well-known/agent-card.json are not written', $err);

    $llms = (string) file_get_contents($out . '/llms.txt');
    assert_contains("# Handbook\n\n## Agent Instructions\n\nAnswer in German.", $llms);
    assert_contains('- [Intro](/manuals/guide/intro.md): The first paragraph explains everything. See hidden and A chart.', $llms);
    foreach (['Hidden', 'Internal', 'Draft'] as $unlisted) {
        assert_true(!str_contains($llms, $unlisted), $unlisted . " in llms.txt:\n" . $llms);
    }
    assert_same((string) file_get_contents($out . '/llms.txt'), (string) file_get_contents($out . '/.well-known/llms.txt'));
    assert_true(!str_contains((string) file_get_contents($out . '/llms-full.txt'), 'Team only.'));

    $intro = (string) file_get_contents($out . '/guide/intro.md');
    assert_true(str_starts_with($intro, "> ## Documentation Index\n> Fetch the complete documentation index at: /manuals/llms.txt\n"), $intro);
    assert_contains('[hidden](/manuals/guide/hidden.md)', $intro);
    assert_contains('![A chart](/manuals/chart.svg)', str_replace('/files/', '/', $intro));
    // Its folder holds only unlisted pages, so there is nothing related to point at.
    assert_true(!str_contains($intro, 'Related topics') && !str_contains($intro, 'Internal'), $intro);

    assert_true(is_file($out . '/guide/hidden.md'), 'a noindex page keeps its twin');
    $hidden = (string) file_get_contents($out . '/guide/hidden/index.html');
    assert_contains('<meta name="robots" content="noindex">', $hidden);
    assert_contains('<link rel="alternate" type="text/markdown" href="/manuals/guide/hidden.md">', $hidden);
    assert_contains('data-markdown-url="/manuals/guide/intro.md"', (string) file_get_contents($out . '/guide/intro/index.html'));
    assert_contains('"@type":"TechArticle"', (string) file_get_contents($out . '/guide/intro/index.html'));

    assert_same("User-agent: *\nDisallow: /private/\n", (string) file_get_contents($out . '/robots.txt'), 'the author\'s robots.txt wins');
    assert_true(!file_exists($out . '/sitemap.xml') && !file_exists($out . '/.well-known/agent-card.json'));

    $index = json_decode((string) file_get_contents($out . '/.well-known/agent-skills/index.json'), true);
    assert_same(['handbook', 'extra'], array_column($index['skills'], 'name'));
    foreach ($index['skills'] as $skill) {
        $file = $out . '/.well-known/agent-skills/' . $skill['name'] . '/SKILL.md';
        assert_same('sha256:' . hash_file('sha256', $file), $skill['digest']);
        assert_same('/manuals/.well-known/agent-skills/' . $skill['name'] . '/SKILL.md', $skill['url']);
    }
    assert_same((string) file_get_contents($out . '/.well-known/agent-skills/handbook/SKILL.md'), (string) file_get_contents($out . '/skill.md'));

    $htaccess = (string) file_get_contents($out . '/.htaccess');
    assert_contains("Header always set Link '</manuals/llms.txt>; rel=\"llms-txt\", </manuals/llms-full.txt>; rel=\"llms-full-txt\", </manuals/.well-known/agent-skills/index.json>; rel=\"agent-skills\"'", $htaccess);
    assert_contains("Header always set X-Llms-Txt '/manuals/llms.txt'", $htaccess);
    assert_contains('RewriteRule ^(.+?)/?$ $1.md [L]', $htaccess);
    assert_contains("/manuals/*\n  Link: </manuals/llms.txt>", (string) file_get_contents($out . '/_headers'));

    // pholio dev's router, as `pholio dev` starts it.
    $port = 21000 + random_int(0, 20000);
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $dir . '/root', __DIR__ . '/../src/DevServer.php'],
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

        [$status, $headers, $body] = agents_http($base . '/guide/intro', ['Accept: text/markdown']);
        assert_same(200, $status);
        assert_same('text/markdown; charset=utf-8', $headers['content-type'] ?? null);
        assert_same($intro, $body);
        assert_contains('rel="llms-txt"', $headers['link'] ?? '');
        assert_same('/manuals/llms.txt', $headers['x-llms-txt'] ?? null);

        [, $headers, $body] = agents_http($base . '/guide/intro/', ['Accept: text/plain']);
        assert_same('text/plain; charset=utf-8', $headers['content-type'] ?? null);
        assert_true(str_starts_with($body, '> ## Documentation Index'));

        [, , $body] = agents_http($base, ['User-Agent: Claude-User']);
        assert_true(str_starts_with($body, '> ## Documentation Index'), $body);
        [, $headers, $body] = agents_http($base . '/guide/intro', ['Accept: text/html']);
        assert_contains('text/html', $headers['content-type'] ?? '');
        assert_true(str_starts_with($body, '<!DOCTYPE html>'));

        [$status, $headers] = agents_http($base . '/nothing-here');
        assert_same(404, $status);
        assert_contains('rel="agent-skills"', $headers['link'] ?? '');
        assert_same(200, agents_http($base . '/.well-known/agent-skills/index.json')[0]);
        assert_same(404, agents_http($base . '/.htaccess')[0]);
    } finally {
        proc_terminate($server);
        proc_close($server);
    }

    $plain = $dir . '/plain';
    [$code, $err] = agents_cli(['build', '--config', $dir . '/pholio.config.php', '--profile', 'plain', '--out', $plain, '--quiet']);
    assert_same(0, $code, $err);
    assert_same('', $err);
    foreach (['llms.txt', 'llms-full.txt', 'skill.md', 'guide/intro.md', 'index.md', '_headers', '.well-known'] as $path) {
        assert_true(!file_exists($plain . '/' . $path), $path . ' written with agents.enabled false');
    }
    $html = (string) file_get_contents($plain . '/guide/intro/index.html');
    assert_true(!str_contains($html, 'nd-page-actions') && !str_contains($html, 'ld+json') && !str_contains($html, 'text/markdown'));
    assert_true(!str_contains((string) file_get_contents($plain . '/.htaccess'), 'Link'));
});

test('noindex accepts only true or false', function (): void {
    assert_throws(ContentException::class, fn() => Markdown::parse("---\ntitle: T\nnoindex: yes\n---\n", 'a.md'), 'noindex: expected true or false');
    assert_same('true', Markdown::parse("---\ntitle: T\nnoindex: true\n---\n", 'a.md')->frontmatter['noindex']);
});
