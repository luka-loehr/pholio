<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../Exceptions.php';
require_once __DIR__ . '/SearchIndex.php';
require_once __DIR__ . '/LlmsTxt.php';

/**
 * Agent Skills and the A2A agent card.
 *
 * - skill.md: a SKILL.md (https://agentskills.io/specification) generated from the
 *   configuration and the page tree: what the documentation covers, how to fetch
 *   llms.txt and the Markdown pages, how to search. Nothing in it is written by a
 *   model; the same site gives the same file. An author's `skill.md` or
 *   `skills/<name>/SKILL.md` next to the configuration replaces or adds to it.
 * - The discovery index of Agent Skills Discovery 0.2.0
 *   (`.well-known/agent-skills/index.json`) with a SHA-256 digest per skill, and the
 *   0.1.0 index at `.well-known/skills/index.json` for older clients.
 * - The A2A agent card (`.well-known/agent-card.json`, protocol 0.3), which points
 *   agents at the same skills. The site is static, so the card advertises no
 *   endpoint beyond the documentation itself.
 *
 * Headings and sentences follow the site language; field names the specifications
 * define (`name`, `description`, `protocolVersion`, …) stay as they are.
 */
final class AgentSkills
{
    public const SCHEMA = 'https://schemas.agentskills.io/discovery/0.2.0/schema.json';

    public const MAX_DESCRIPTION = 1024;

    private const JSON = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /** Skill name from a site title: lowercase letters, digits and single hyphens, at most 64 characters. */
    public static function name(string $title): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', SearchIndex::normalize($title, 'english')), '-');
        $slug = rtrim(substr($slug, 0, 64), '-');

        return $slug === '' ? 'docs' : $slug;
    }

    public static function validName(string $name): bool
    {
        return strlen($name) <= 64 && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) === 1;
    }

    /**
     * The generated SKILL.md.
     *
     * @param array{
     *   name:string, title:string, description:?string, instructions:?string, home:string,
     *   sections:list<array{name:string, description:?string, pages:int}>,
     *   llmsTxt:?string, llmsFullTxt:?string, example:?array{url:string, markdownUrl:string}
     * } $site URLs as published
     * @return string without a final newline
     */
    public static function generate(array $site): string
    {
        $title = self::line($site['title']);
        $covers = implode(', ', array_map(static fn(array $s): string => self::line($s['name']), $site['sections']));
        $description = self::documentation($title) . '.'
            . ($site['description'] !== null ? ' ' . self::line($site['description']) : '')
            . ' ' . ($covers === ''
                ? LlmsTxt::t('Use it to answer questions about {title} or to work with it.(agent files)', ['{title}' => $title])
                : LlmsTxt::t('Use it to answer questions about {title} or to work with it; it covers {sections}.(agent files)', ['{title}' => $title, '{sections}' => $covers]));
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            $description = rtrim(mb_substr($description, 0, self::MAX_DESCRIPTION - 1)) . '…';
        }

        $out = "---\nname: " . $site['name'] . "\ndescription: " . self::yaml($description) . "\n---\n\n# " . $title;
        if ($site['description'] !== null) {
            $out .= "\n\n" . self::line($site['description']);
        }
        $out .= "\n\n" . LlmsTxt::t('Use this skill when a task involves {title}. Read the documentation instead of relying on memory, and name the pages you used.(agent files)', ['{title}' => $title]);

        if ($site['instructions'] !== null && trim($site['instructions']) !== '') {
            $out .= "\n\n## " . LlmsTxt::t('Notes for agents(agent files)') . "\n\n" . trim($site['instructions']);
        }

        if ($site['sections'] !== []) {
            $out .= "\n\n## " . LlmsTxt::t('What the documentation covers(agent files)') . "\n";
            foreach ($site['sections'] as $section) {
                $out .= "\n- **" . self::line($section['name']) . '** (' . LlmsTxt::pages($section['pages']) . ')'
                    . ($section['description'] !== null ? ': ' . self::line($section['description']) : '');
            }
        }

        $source = LlmsTxt::t('Source(agent files)');
        $steps = [];
        if ($site['llmsTxt'] !== null) {
            $steps[] = LlmsTxt::t('Start with the index at {url}. It lists every page with a one-line summary, grouped by section.(agent files)', ['{url}' => $site['llmsTxt']]);
        }
        if ($site['example'] !== null) {
            $steps[] = LlmsTxt::t(
                'Fetch a page as Markdown by appending `.md` to its URL, for example {markdownUrl} for {url}, or request the page URL with the header `Accept: text/markdown`.(agent files)',
                ['{markdownUrl}' => $site['example']['markdownUrl'], '{url}' => $site['example']['url']],
            );
        }
        if ($site['llmsFullTxt'] !== null) {
            $steps[] = LlmsTxt::t(
                'To read everything at once, fetch {url}. Each page in it starts with a `# ` heading and a `{source}:` line.(agent files)',
                ['{url}' => $site['llmsFullTxt'], '{source}' => $source],
            );
        }
        if ($steps === []) {
            $steps[] = LlmsTxt::t('Start at {url} and follow the links.(agent files)', ['{url}' => $site['home']]);
        }
        $out .= "\n\n## " . LlmsTxt::t('Fetching pages(agent files)') . "\n";
        foreach ($steps as $i => $step) {
            $out .= "\n" . ($i + 1) . '. ' . $step;
        }

        $search = [];
        if ($site['llmsTxt'] !== null) {
            $search[] = LlmsTxt::t('Match the task against the page titles and summaries in the index first, then fetch the pages that fit.(agent files)');
        }
        if ($site['llmsFullTxt'] !== null) {
            $search[] = LlmsTxt::t(
                'For an exact phrase, option or error message, search the text of {url} and follow the `{source}:` line of the page it appears in.(agent files)',
                ['{url}' => $site['llmsFullTxt'], '{source}' => $source],
            );
        }
        if ($site['example'] !== null) {
            $search[] = LlmsTxt::t(
                'Every Markdown page ends with "{related}": the other pages of its section and the previous and next page.(agent files)',
                ['{related}' => LlmsTxt::t('Related topics(agent files)')],
            );
        }
        if ($search !== []) {
            $out .= "\n\n## " . LlmsTxt::t('Searching(agent files)') . "\n\n- " . implode("\n- ", $search);
        }

        return $out;
    }

    /**
     * An author's SKILL.md: frontmatter with a one-line `name` and `description`.
     *
     * @return array{name:string, description:string, content:string}
     * @throws ContentException
     */
    public static function read(string $file): array
    {
        $content = rtrim(str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($file)));
        if (preg_match('/\A---\n(.*?)\n---(?:\n|\z)/s', $content, $m) !== 1) {
            throw new ContentException('a skill file starts with frontmatter between "---" lines holding name and description', $file);
        }
        $fields = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^(name|description):[ \t]*(.*)$/', $line, $field) === 1) {
                $fields[$field[1]] = self::unquote(trim($field[2]));
            }
        }

        $name = $fields['name'] ?? '';
        if (!self::validName($name)) {
            throw new ContentException(
                'skill name "' . $name . '": expected 1 to 64 lowercase letters, digits and single hyphens, not at the start or end',
                $file,
            );
        }
        $description = $fields['description'] ?? '';
        if ($description === '' || in_array($description, ['|', '>', '|-', '>-'], true)) {
            throw new ContentException('skill description: expected a one-line "description: …" in the frontmatter', $file);
        }
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new ContentException('skill description: at most ' . self::MAX_DESCRIPTION . ' characters, got ' . mb_strlen($description), $file);
        }

        return ['name' => $name, 'description' => $description, 'content' => $content];
    }

    /** @param list<array{name:string, description:string, url:string, digest:string}> $skills */
    public static function index(array $skills): string
    {
        return json_encode([
            '$schema' => self::SCHEMA,
            'skills' => array_map(static fn(array $s): array => [
                'name' => $s['name'],
                'type' => 'skill-md',
                'description' => $s['description'],
                'url' => $s['url'],
                'digest' => $s['digest'],
            ], $skills),
        ], self::JSON);
    }

    /**
     * The 0.1.0 index: no digests, the files of each skill below `.well-known/skills/<name>/`.
     *
     * @param list<array{name:string, description:string}> $skills
     */
    public static function legacyIndex(array $skills): string
    {
        return json_encode([
            'skills' => array_map(static fn(array $s): array => [
                'name' => $s['name'],
                'description' => $s['description'],
                'files' => ['SKILL.md'],
            ], $skills),
        ], self::JSON);
    }

    /**
     * @param array{title:string, description:?string, url:string, origin:string} $site absolute URLs
     * @param list<array{name:string, description:string}> $skills
     */
    public static function agentCard(array $site, array $skills): string
    {
        return json_encode([
            'protocolVersion' => '0.3',
            'name' => $site['title'],
            'description' => $site['description'] ?? self::documentation(self::line($site['title'])),
            'url' => $site['url'],
            'preferredTransport' => 'HTTP+JSON',
            'provider' => ['organization' => $site['title'], 'url' => $site['origin']],
            'version' => '1.0.0',
            'documentationUrl' => $site['url'],
            'capabilities' => ['streaming' => false, 'pushNotifications' => false, 'stateTransitionHistory' => false],
            'defaultInputModes' => ['text/markdown', 'text/plain'],
            'defaultOutputModes' => ['text/markdown', 'text/plain'],
            'skills' => array_map(static fn(array $s): array => [
                'id' => $s['name'],
                'name' => $s['name'],
                'description' => $s['description'],
                'tags' => ['documentation'],
            ], $skills),
        ], self::JSON);
    }

    /** "{title} documentation" in the site language. */
    private static function documentation(string $title): string
    {
        return LlmsTxt::t('{title} documentation(agent files)', ['{title}' => $title]);
    }

    private static function yaml(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[strlen($value) - 1] === $value[0]) {
            $inner = substr($value, 1, -1);

            return $value[0] === '"' ? str_replace(['\\"', '\\\\'], ['"', '\\'], $inner) : str_replace("''", "'", $inner);
        }

        return $value;
    }

    private static function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
