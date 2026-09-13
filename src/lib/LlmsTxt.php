<?php

declare(strict_types=1);

namespace Pholio;

use Closure;

/**
 * llms.txt (https://llmstxt.org): a Markdown index of the site for language models.
 *
 *   # Site title
 *
 *   > Site description
 *
 *   ## Agent Instructions          agents.instructions, when set
 *
 *   ## <section>                   one per top-level folder, in navigation order
 *
 *   - [Page title](url): summary
 *
 *   ## Optional                    external links of the navigation
 *
 * When that file would be longer than LIMIT characters, llms.txt lists section
 * indexes instead (`_llms/<section>.md`, each with its page count), preceded by a
 * two-line instruction to follow them recursively. A section index that is too
 * long itself lists its direct pages and one index per subfolder
 * (`_llms/<section>/<folder>.md`), and entries that still don't fit are split into
 * parts (`_llms/<section>-part-1.md`). No page is ever left out.
 *
 * Groups: array{name:string, slug:string, description:?string, items:list<array{page:Entry}|array{group:Group}>}
 * Entries: array{title:string, url:string, summary:?string}
 */
final class LlmsTxt
{
    public const LIMIT = 100000;

    public const INDEX_DIR = '_llms';

    /**
     * @param list<array<string,mixed>> $sections top-level groups in navigation order
     * @param list<array{title:string, url:string}> $optional
     * @param Closure(string):string $published path relative to the start page's directory => published URL
     * @return array<string,string> path relative to the start page's directory => content, llms.txt first
     */
    public static function files(
        string $title,
        ?string $description,
        ?string $instructions,
        array $sections,
        array $optional,
        Closure $published,
        int $limit = self::LIMIT,
    ): array {
        $head = self::head($title, $description);
        $optionalBlock = $optional === []
            ? ''
            : "\n\n## Optional\n\n" . implode("\n", array_map(
                static fn(array $link): string => '- ' . self::link($link['title'], $link['url']),
                $optional,
            ));

        $full = $head . self::instructions($instructions);
        foreach ($sections as $section) {
            $full .= "\n\n## " . self::line($section['name']) . "\n\n"
                . implode("\n", array_map([self::class, 'pageLine'], self::flatten($section)));
        }
        $full .= $optionalBlock;
        if (mb_strlen($full) <= $limit) {
            return ['llms.txt' => $full];
        }

        $files = ['llms.txt' => ''];
        $lines = [];
        $total = 0;
        foreach ($sections as $section) {
            $base = self::INDEX_DIR . '/' . $section['slug'];
            self::group($section, $base, $published, $limit, $files);
            $total += count(self::flatten($section));
            $lines[] = self::groupLine($section, $published($base . '.md'));
        }
        $files['llms.txt'] = $head
            . "\n\nThis index lists section indexes instead of pages, because all {$total} pages do not fit into {$limit} characters."
            . "\nFetch every index below and follow the " . $published(self::INDEX_DIR . '/') . ' indexes they link to recursively: together they list every page.'
            . self::instructions($instructions)
            . "\n\n## Sections\n\n" . implode("\n", $lines)
            . $optionalBlock;

        return $files;
    }

    /**
     * Write the index of one group into $files, splitting it when it is too long.
     *
     * @param array<string,mixed> $group
     * @param array<string,string> $files
     */
    private static function group(array $group, string $base, Closure $published, int $limit, array &$files): void
    {
        $pages = self::flatten($group);
        $head = self::groupHead($group, count($pages));
        $full = $head . "\n\n" . implode("\n", array_map([self::class, 'pageLine'], $pages));
        if (mb_strlen($full) <= $limit) {
            $files[$base . '.md'] = $full;

            return;
        }

        $entries = [];
        foreach ($group['items'] as $item) {
            if (isset($item['page'])) {
                $entries[] = self::pageLine($item['page']);
                continue;
            }
            $childBase = $base . '/' . $item['group']['slug'];
            self::group($item['group'], $childBase, $published, $limit, $files);
            $entries[] = self::groupLine($item['group'], $published($childBase . '.md'));
        }
        $note = "\n\nFollow the indexes below recursively: together with the pages listed here they cover every page of this section.";
        $body = $head . $note . "\n\n" . implode("\n", $entries);
        if (mb_strlen($body) <= $limit) {
            $files[$base . '.md'] = $body;

            return;
        }

        // More entries than one file holds: parts, each within the limit.
        $budget = $limit - mb_strlen($group['name']) - 40;
        $parts = [[]];
        $size = 0;
        foreach ($entries as $entry) {
            $length = mb_strlen($entry) + 1;
            if ($size + $length > $budget && $parts[count($parts) - 1] !== []) {
                $parts[] = [];
                $size = 0;
            }
            $parts[count($parts) - 1][] = $entry;
            $size += $length;
        }
        $links = [];
        foreach ($parts as $i => $part) {
            $label = $group['name'] . ', part ' . ($i + 1) . ' of ' . count($parts);
            $path = $base . '-part-' . ($i + 1) . '.md';
            $files[$path] = '# ' . self::line($label) . "\n\n" . implode("\n", $part);
            $links[] = '- ' . self::link($label, $published($path)) . ': ' . count($part) . ' entries';
        }
        $files[$base . '.md'] = $head . $note . "\n\n" . implode("\n", $links);
    }

    /**
     * Pages of a group and its subgroups in navigation order.
     *
     * @param array<string,mixed> $group
     * @return list<array{title:string, url:string, summary:?string}>
     */
    public static function flatten(array $group): array
    {
        $out = [];
        foreach ($group['items'] as $item) {
            if (isset($item['page'])) {
                $out[] = $item['page'];
                continue;
            }
            array_push($out, ...self::flatten($item['group']));
        }

        return $out;
    }

    /** Link text with brackets and backslashes escaped, on one line. */
    public static function linkText(string $text): string
    {
        return str_replace(['\\', '[', ']'], ['\\\\', '\[', '\]'], self::line($text));
    }

    private static function head(string $title, ?string $description): string
    {
        $out = '# ' . self::line($title);
        if ($description !== null && self::line($description) !== '') {
            $out .= "\n\n> " . self::line($description);
        }

        return $out;
    }

    private static function instructions(?string $instructions): string
    {
        return $instructions === null || trim($instructions) === ''
            ? ''
            : "\n\n## Agent Instructions\n\n" . trim($instructions);
    }

    /** @param array<string,mixed> $group */
    private static function groupHead(array $group, int $count): string
    {
        $out = self::head($group['name'], $group['description'] ?? null);

        return $out . "\n\n" . $count . ($count === 1 ? ' page.' : ' pages.');
    }

    /** @param array<string,mixed> $group */
    private static function groupLine(array $group, string $url): string
    {
        $count = count(self::flatten($group));
        $description = $group['description'] ?? null;

        return '- ' . self::link($group['name'], $url) . ': ' . $count . ($count === 1 ? ' page' : ' pages')
            . ($description !== null && self::line($description) !== '' ? '. ' . self::line($description) : '');
    }

    /** @param array{title:string, url:string, summary:?string} $page */
    private static function pageLine(array $page): string
    {
        return '- ' . self::link($page['title'], $page['url'])
            . ($page['summary'] !== null && $page['summary'] !== '' ? ': ' . self::line($page['summary']) : '');
    }

    private static function link(string $text, string $url): string
    {
        return '[' . self::linkText($text) . '](' . $url . ')';
    }

    private static function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
