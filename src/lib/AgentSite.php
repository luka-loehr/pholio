<?php

declare(strict_types=1);

namespace Pholio;

use Closure;

require_once __DIR__ . '/../Exceptions.php';
require_once __DIR__ . '/../Fs.php';
require_once __DIR__ . '/../AgentHeaders.php';
require_once __DIR__ . '/Html.php';
require_once __DIR__ . '/Markdown.php';
require_once __DIR__ . '/Render.php';
require_once __DIR__ . '/Slug.php';
require_once __DIR__ . '/Tree.php';
require_once __DIR__ . '/PageMarkdown.php';
require_once __DIR__ . '/LlmsTxt.php';
require_once __DIR__ . '/AgentSkills.php';

/**
 * Everything a build publishes for AI agents, derived from the page tree that
 * also feeds the sidebar and the search index (docs/agents.md). Paths are
 * relative to the start page's directory:
 *
 *   <page>.md, index.md                 Markdown twin of every page and of the start page
 *   llms.txt, .well-known/llms.txt      index in navigation order; _llms/**.md when too long (LlmsTxt)
 *   llms-full.txt, .well-known/llms-full.txt   every listed page in full
 *   skill.md, .well-known/agent-skills/**, .well-known/skills/**   Agent Skills (AgentSkills)
 *   .well-known/agent-card.json         A2A agent card, needs site.url
 *   robots.txt, sitemap.xml             the sitemap needs site.url
 *   _headers                            Link and X-Llms-Txt for static hosts (AgentHeaders)
 *
 * and, for the HTML pages, the page actions, the alternate link to the twin,
 * `<meta name="robots" content="noindex">` and the JSON-LD block.
 *
 * A page is listed in llms.txt, llms-full.txt and skill.md unless it is a draft,
 * has `noindex: true` or matches `agents.exclude`. The sitemap leaves out drafts
 * and `noindex` pages. Every built page gets its twin.
 *
 * URLs are published with `site.url` in front when it is set, otherwise as
 * base-path URLs ("/manuals/llms.txt"). Headings and fixed sentences follow the
 * site language, and they describe rather than instruct, so an agent reading a
 * twin finds metadata, not commands.
 */
final class AgentSite
{
    public const SUMMARY_LENGTH = 300;

    /** @var array<string, array{url:string, slugs:list<string>, file:string, data:array<string,mixed>}> tree pages by URL */
    private array $built = [];

    /**
     * Every built page, in navigation order; pages the navigation doesn't show come last.
     *
     * @var array<string, array{url:string, file:string, title:string, description:?string, updated:?string, draft:bool, noindex:bool, excluded:bool}>
     */
    private array $pages = [];

    /** @var list<array<string,mixed>> top-level groups of listed pages, see LlmsTxt */
    private array $sections = [];

    /** @var array<string, array{title:string, url:string}> external navigation links by URL */
    private array $optional = [];

    /**
     * @param array<string,mixed> $config normalised
     * @param Closure(string):Document $parse absolute page file => document
     * @param Closure(string, string):string $asset image source, absolute page file => published URL path
     * @param Closure(string, bool):string $outputPath page URL, whether it is the start page => HTML file
     *        relative to the start page's directory (Builder::outputPath)
     */
    public function __construct(
        private readonly array $config,
        private readonly Tree $tree,
        private readonly Closure $parse,
        private readonly Closure $asset,
        private readonly Closure $outputPath,
    ) {
        foreach ($tree->pages() as $page) {
            $this->built[$page['url']] = $page;
        }
        foreach ($config['links'] as $link) {
            if ($link['external'] || Html::isExternal($link['href'])) {
                $this->optional[$link['href']] ??= ['title' => $link['title'], 'url' => $link['href']];
            }
        }
        $this->collect();
    }

    // ------------------------------------------------------------------ HTML

    /**
     * Page actions of a page, or null when they are off.
     *
     * @return array{markdownUrl:string, llmsTxtUrl:?string}|null
     */
    public function pageActions(string $url): ?array
    {
        if (!$this->config['agents']['pageActions'] || !isset($this->pages[$url])) {
            return null;
        }

        return [
            'markdownUrl' => $this->homePath($this->markdownPath($url)),
            'llmsTxtUrl' => $this->config['agents']['llmsTxt'] ? $this->homePath('llms.txt') : null,
        ];
    }

    /**
     * Additions to the document head of a page.
     *
     * @return array{markdownUrl:?string, robots:?string, jsonLd:?string}
     */
    public function head(string $url): array
    {
        $page = $this->pages[$url] ?? null;
        if ($page === null) {
            return ['markdownUrl' => null, 'robots' => null, 'jsonLd' => null];
        }

        return [
            'markdownUrl' => $this->config['agents']['markdown'] ? $this->homePath($this->markdownPath($url)) : null,
            'robots' => $page['noindex'] ? 'noindex' : null,
            'jsonLd' => $this->config['agents']['structuredData'] ? self::json($this->pageGraph($url)) : null,
        ];
    }

    /** @return array{markdownUrl:?string, robots:?string, jsonLd:?string} */
    public function homeHead(): array
    {
        return [
            'markdownUrl' => $this->config['agents']['markdown'] ? $this->homePath($this->markdownPath($this->config['homeUrl'], true)) : null,
            'robots' => null,
            'jsonLd' => $this->config['agents']['structuredData'] ? self::json(['@context' => 'https://schema.org'] + $this->website()) : null,
        ];
    }

    // ------------------------------------------------------------------ files

    /**
     * Write the agent files below $target, the start page's directory.
     *
     * @param Closure(string):bool $authored path relative to $target => the site provides that file itself
     * @return list<string> warnings
     */
    public function write(string $target, Closure $authored): array
    {
        $agents = $this->config['agents'];
        $siteUrl = $this->config['site']['url'];
        $files = [];
        $warnings = [];

        if ($agents['markdown']) {
            foreach (array_keys($this->pages) as $url) {
                $files[$this->markdownPath((string) $url)] = $this->pageMarkdown((string) $url);
            }
            if ($this->config['home'] !== null) {
                $files[$this->markdownPath($this->config['homeUrl'], true)] = $this->homeMarkdown();
            }
        }

        if ($agents['llmsTxt']) {
            $files += LlmsTxt::files(
                $this->config['title'],
                $this->siteDescription(),
                $this->config['agents']['instructions'],
                $this->llmsGroups($this->sections),
                array_values($this->optional),
                fn(string $path): string => $this->published($path),
            );
            $files['.well-known/llms.txt'] = $files['llms.txt'];
        }
        if ($agents['llmsFullTxt']) {
            $files['llms-full.txt'] = $this->llmsFull();
            $files['.well-known/llms-full.txt'] = $files['llms-full.txt'];
        }

        if ($siteUrl === null) {
            $skipped = array_keys(array_filter(['sitemap.xml' => $agents['sitemap'], '.well-known/agent-card.json' => $agents['agentCard']]));
            if ($skipped !== []) {
                $warnings[] = 'site.url is not set, so ' . implode(' and ', $skipped) . ' '
                    . (count($skipped) === 1 ? 'is' : 'are') . ' not written: they need absolute URLs. '
                    . 'Set site.url to the origin the site is served from, e.g. "https://docs.example.org"';
            }
        }
        if ($agents['robotsTxt']) {
            $files['robots.txt'] = $this->robots();
        }
        if ($agents['sitemap'] && $siteUrl !== null) {
            $files['sitemap.xml'] = $this->sitemap();
        }

        $skills = [];
        if ($agents['skill']) {
            foreach ($this->skills() as $skill) {
                $path = '.well-known/agent-skills/' . $skill['name'] . '/SKILL.md';
                $files[$path] = $skill['content'];
                $files['.well-known/skills/' . $skill['name'] . '/SKILL.md'] = $skill['content'];
                // Fs::write ends every file with a newline; the digest is over the served bytes.
                $skills[] = $skill + ['url' => $this->published($path), 'digest' => 'sha256:' . hash('sha256', $skill['content'] . "\n")];
            }
            $files['skill.md'] = $skills[0]['content'];
            $files['.well-known/agent-skills/index.json'] = AgentSkills::index($skills);
            $files['.well-known/skills/index.json'] = AgentSkills::legacyIndex($skills);
        }
        if ($agents['agentCard'] && $siteUrl !== null) {
            $files['.well-known/agent-card.json'] = AgentSkills::agentCard([
                'title' => $this->config['title'],
                'description' => $this->siteDescription(),
                'url' => $this->absolute($this->config['homeUrl']),
                'origin' => $siteUrl,
            ], $skills);
        }

        $headers = AgentHeaders::file($this->config);
        if ($headers !== null) {
            $files[AgentHeaders::FILE] = $headers;
        }

        foreach ($files as $path => $content) {
            if (!$authored((string) $path)) {
                Fs::write($target, (string) $path, rtrim($content, "\n"));
            }
        }

        return $warnings;
    }

    /** The Markdown twin of a page. */
    public function pageMarkdown(string $url): string
    {
        $page = $this->pages[$url];
        $parts = [];
        if ($this->config['agents']['llmsTxt']) {
            $parts[] = $this->indexNote();
        }
        $parts[] = $this->pageText($url);
        $related = $this->related($url);
        if ($related !== '') {
            $parts[] = $related;
        }

        return implode("\n\n", $parts);
    }

    /** The first line of every twin: where the index of all pages is, as a statement. */
    private function indexNote(): string
    {
        return '> ' . LlmsTxt::t(
            'Documentation index: {url}, a list of every page in this documentation.(agent files)',
            ['{url}' => $this->published('llms.txt')],
        );
    }

    /** Title, description and body of a page as Markdown. */
    private function pageText(string $url, ?string $source = null): string
    {
        $page = $this->pages[$url];
        $file = $this->file($url);
        $exporter = new PageMarkdown(
            fn(string $href): string => $this->link($href, $url),
            fn(string $src): string => $this->absolute(($this->asset)($src, $file)),
        );

        $parts = ['# ' . PageMarkdown::escape(self::oneLine($page['title']))];
        if ($source !== null) {
            $parts[] = LlmsTxt::t('Source(agent files)') . ': ' . $source;
        }
        if ($page['description'] !== null) {
            $parts[] = PageMarkdown::quote(self::oneLine($page['description']));
        }
        $body = $exporter->body($this->document($url));
        if ($body !== '') {
            $parts[] = $body;
        }

        return implode("\n\n", $parts);
    }

    /** The twin of the start page: title, description and one link per section. */
    private function homeMarkdown(): string
    {
        $parts = [];
        if ($this->config['agents']['llmsTxt']) {
            $parts[] = $this->indexNote();
        }
        $parts[] = '# ' . PageMarkdown::escape(self::oneLine($this->config['homeTitle']));
        $description = $this->siteDescription();
        if ($description !== null) {
            $parts[] = PageMarkdown::quote(self::oneLine($description));
        }
        $lead = self::oneLine((string) ($this->config['home']['hero']['lead'] ?? ''));
        if ($lead !== '' && $lead !== self::oneLine((string) $description)) {
            $parts[] = PageMarkdown::escape($lead);
        }

        $lines = [];
        foreach ($this->sections as $section) {
            $first = LlmsTxt::flatten($section)[0]['page'] ?? self::firstPage($section);
            $lines[] = '- ' . $this->pageLink($first, $section['name'])
                . ($section['description'] !== null ? ': ' . self::oneLine($section['description']) : '');
        }
        if ($lines !== []) {
            $parts[] = '## ' . LlmsTxt::t('Sections(agent files)') . "\n\n" . implode("\n", $lines);
        }

        return implode("\n\n", $parts);
    }

    private function llmsFull(): string
    {
        $parts = ['# ' . self::oneLine($this->config['title'])];
        $description = $this->siteDescription();
        if ($description !== null) {
            $parts[] = '> ' . self::oneLine($description);
        }
        foreach (array_keys($this->pages) as $url) {
            if ($this->listed((string) $url)) {
                $parts[] = $this->pageText((string) $url, $this->absolute($this->htmlUrl((string) $url)));
            }
        }

        return implode("\n\n", $parts);
    }

    private function robots(): string
    {
        $lines = ['# Generated by Pholio. Search engines and AI crawlers may read every page.'];
        if ($this->config['homeUrl'] !== '/') {
            $lines[] = '# Crawlers read robots.txt only at the root of the host: copy these lines there.';
        }
        $lines[] = 'User-agent: *';
        $lines[] = 'Allow: /';
        if ($this->config['agents']['sitemap'] && $this->config['site']['url'] !== null) {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $this->published('sitemap.xml');
        }

        return implode("\n", $lines);
    }

    private function sitemap(): string
    {
        $entries = [];
        if ($this->config['home'] !== null) {
            $entries[$this->absolute($this->config['homeUrl'])] = null;
        }
        foreach ($this->pages as $url => $page) {
            if (!$page['draft'] && !$page['noindex']) {
                $entries[$this->absolute($this->htmlUrl((string) $url))] ??= self::isoDate($page['updated']);
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($entries as $loc => $lastmod) {
            $xml .= "\n  <url><loc>" . htmlspecialchars((string) $loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
                . ($lastmod === null ? '' : '<lastmod>' . $lastmod . '</lastmod>') . '</url>';
        }

        return $xml . "\n</urlset>";
    }

    /**
     * The skills: the author's `skill.md` or the generated one first, then every
     * `skills/<name>/SKILL.md`; one with the first skill's name replaces it.
     *
     * @return non-empty-list<array{name:string, description:string, content:string}>
     */
    private function skills(): array
    {
        $dir = $this->config['configDir'];
        $primary = is_file($dir . '/skill.md') ? AgentSkills::read($dir . '/skill.md') : $this->generatedSkill();
        $skills = [$primary['name'] => $primary];

        $files = glob($dir . '/skills/*/SKILL.md') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $skill = AgentSkills::read($file);
            $directory = basename(dirname($file));
            if ($skill['name'] !== $directory) {
                throw new ContentException('skill name "' . $skill['name'] . '" differs from its directory skills/' . $directory . '/', $file);
            }
            $skills[$skill['name']] = $skill;
        }

        return array_values($skills);
    }

    /** @return array{name:string, description:string, content:string} */
    private function generatedSkill(): array
    {
        $agents = $this->config['agents'];
        $first = null;
        foreach (array_keys($this->pages) as $url) {
            if ($this->listed((string) $url)) {
                $first = (string) $url;
                break;
            }
        }

        $content = AgentSkills::generate([
            'name' => AgentSkills::name($this->config['title']),
            'title' => $this->config['title'],
            'description' => $this->siteDescription(),
            'instructions' => $agents['instructions'],
            'home' => $this->absolute($this->config['homeUrl']),
            'sections' => array_map(static fn(array $section): array => [
                'name' => $section['name'],
                'description' => $section['description'],
                'pages' => self::count($section),
            ], $this->sections),
            'llmsTxt' => $agents['llmsTxt'] ? $this->published('llms.txt') : null,
            'llmsFullTxt' => $agents['llmsFullTxt'] ? $this->published('llms-full.txt') : null,
            'example' => $first === null || !$agents['markdown'] ? null : [
                'url' => $this->absolute($this->htmlUrl($first)),
                'markdownUrl' => $this->published($this->markdownPath($first)),
            ],
        ]);
        preg_match('/^description: (.*)$/m', $content, $m);

        return [
            'name' => AgentSkills::name($this->config['title']),
            'description' => stripcslashes(substr($m[1], 1, -1)),
            'content' => $content,
        ];
    }

    // ------------------------------------------------------------------ page tree

    /** Walk the tree in navigation order: one group per top-level folder, loose pages by separator. */
    private function collect(): void
    {
        $root = $this->tree->root();
        $rootName = (string) $root->name;
        $slugger = new Slugger();
        $sections = [];
        $loose = null;

        foreach ($root->children as $node) {
            if ($node->type === Node::FOLDER) {
                if ($loose !== null) {
                    $sections[] = $loose;
                    $loose = null;
                }
                $sections[] = $this->folderGroup($node, $slugger);
                continue;
            }
            if ($node->type === Node::SEPARATOR) {
                if ($loose !== null) {
                    $sections[] = $loose;
                }
                $loose = self::group((string) $node->name !== '' ? (string) $node->name : $rootName, null, $slugger);
                continue;
            }
            $item = $this->pageItem($node);
            if ($item !== null) {
                $loose ??= self::group($rootName, null, $slugger);
                $loose['items'][] = $item;
            }
        }
        if ($loose !== null) {
            $sections[] = $loose;
        }

        // Pages outside the navigation are still published, so they are listed too.
        $rest = self::group(LlmsTxt::t('Other pages(agent files)'), null, $slugger);
        foreach (array_keys($this->built) as $url) {
            if ($this->register((string) $url)) {
                $rest['items'][] = ['page' => (string) $url];
            }
        }
        if ($rest['items'] !== []) {
            $sections[] = $rest;
        }

        $this->sections = $this->prune($sections);
    }

    /** @return array<string,mixed> */
    private function folderGroup(Node $folder, Slugger $slugger): array
    {
        $group = self::group((string) $folder->name, $folder->description, $slugger);
        $children = new Slugger();
        if ($folder->index !== null && ($item = $this->pageItem($folder->index)) !== null) {
            $group['items'][] = $item;
        }
        foreach ($folder->children as $child) {
            if ($child->type === Node::FOLDER) {
                $group['items'][] = ['group' => $this->folderGroup($child, $children)];
            } elseif ($child->type === Node::PAGE && ($item = $this->pageItem($child)) !== null) {
                $group['items'][] = $item;
            }
        }

        return $group;
    }

    /** @return array{page:string}|null */
    private function pageItem(Node $node): ?array
    {
        $url = (string) $node->url;
        if ($node->external === true || Html::isExternal($url)) {
            $this->optional[$url] ??= ['title' => (string) $node->name, 'url' => $url];

            return null;
        }

        return isset($this->built[$url]) && $this->register($url) ? ['page' => $url] : null;
    }

    /** Record a built page once; false when it was recorded before. */
    private function register(string $url): bool
    {
        if (isset($this->pages[$url])) {
            return false;
        }
        $file = ltrim($this->built[$url]['file'], '/');
        $frontmatter = $this->document($url)->frontmatter;
        $description = isset($frontmatter['description']) && trim((string) $frontmatter['description']) !== '' ? (string) $frontmatter['description'] : null;

        $excluded = false;
        foreach ($this->config['agents']['exclude'] as $glob) {
            $excluded = $excluded || fnmatch($glob, $url) || fnmatch($glob, $file);
        }

        $this->pages[$url] = [
            'url' => $url,
            'file' => $file,
            'title' => (string) ($frontmatter['title'] ?? ''),
            'description' => $description,
            'updated' => isset($frontmatter['updated']) ? (string) $frontmatter['updated'] : null,
            'draft' => str_starts_with(basename($file), '_'),
            'noindex' => ($frontmatter['noindex'] ?? '') === 'true',
            'excluded' => $excluded,
        ];

        return true;
    }

    /**
     * Groups without unlisted pages and without empty groups.
     *
     * @param list<array<string,mixed>> $groups
     * @return list<array<string,mixed>>
     */
    private function prune(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if (isset($item['page'])) {
                    if ($this->listed($item['page'])) {
                        $items[] = $item;
                    }
                    continue;
                }
                $child = $this->prune([$item['group']]);
                if ($child !== []) {
                    $items[] = ['group' => $child[0]];
                }
            }
            if ($items !== []) {
                $group['items'] = $items;
                $out[] = $group;
            }
        }

        return $out;
    }

    private function listed(string $url): bool
    {
        $page = $this->pages[$url];

        return !$page['draft'] && !$page['noindex'] && !$page['excluded'];
    }

    /**
     * The groups for LlmsTxt, with page URLs replaced by entries.
     *
     * @param list<array<string,mixed>> $groups
     * @return list<array<string,mixed>>
     */
    private function llmsGroups(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if (isset($item['group'])) {
                    $items[] = ['group' => $this->llmsGroups([$item['group']])[0]];
                    continue;
                }
                $url = $item['page'];
                $items[] = ['page' => [
                    'title' => $this->pages[$url]['title'],
                    'url' => $this->config['agents']['markdown'] ? $this->published($this->markdownPath($url)) : $this->absolute($this->htmlUrl($url)),
                    'summary' => $this->summary($url),
                ]];
            }
            $out[] = ['items' => $items] + $group;
        }

        return $out;
    }

    /** The frontmatter description, else the first paragraph cut at SUMMARY_LENGTH characters. */
    private function summary(string $url): ?string
    {
        $description = $this->pages[$url]['description'];
        if ($description !== null) {
            return self::oneLine($description);
        }
        foreach ($this->document($url)->blocks as $block) {
            if ((string) $block['type'] !== 'paragraph') {
                continue;
            }
            $text = self::oneLine(Markdown::plainText($block['inlines']));
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) <= self::SUMMARY_LENGTH) {
                return $text;
            }
            $cut = mb_substr($text, 0, self::SUMMARY_LENGTH);
            $space = mb_strrpos($cut, ' ');
            if ($space !== false && $space > self::SUMMARY_LENGTH / 2) {
                $cut = mb_substr($cut, 0, $space);
            }

            return rtrim($cut, ' ,;:.') . '…';
        }

        return null;
    }

    /**
     * "Related topics": the listed pages of the same folder, then previous and next.
     */
    private function related(string $url): string
    {
        $lines = [];
        $path = $this->tree->pathTo($url);
        if ($path !== []) {
            $parent = $this->tree->root();
            for ($i = count($path) - 2; $i >= 0; $i--) {
                if ($path[$i]->type === Node::FOLDER) {
                    $parent = $path[$i];
                    break;
                }
            }
            $seen = [$url => true];
            foreach ([$parent->index, ...$parent->children] as $node) {
                if ($node === null || $node->type !== Node::PAGE || $node->external === true) {
                    continue;
                }
                $sibling = (string) $node->url;
                if (!isset($seen[$sibling]) && isset($this->pages[$sibling]) && $this->listed($sibling)) {
                    $seen[$sibling] = true;
                    $lines[] = '- ' . $this->pageLink($sibling, $this->pages[$sibling]['title']);
                }
            }
        }

        $footer = $this->tree->footerItems($url);
        foreach (['previous' => 'Previous(agent files)', 'next' => 'Next(agent files)'] as $key => $label) {
            $target = $footer[$key]['url'] ?? null;
            if ($target !== null && isset($this->pages[$target]) && $this->listed($target)) {
                $lines[] = '- ' . LlmsTxt::t($label) . ': ' . $this->pageLink($target, $this->pages[$target]['title']);
            }
        }

        return $lines === [] ? '' : '## ' . LlmsTxt::t('Related topics(agent files)') . "\n\n" . implode("\n", $lines);
    }

    // ------------------------------------------------------------------ JSON-LD

    /** @return array<string,mixed> */
    private function website(): array
    {
        return array_filter([
            '@type' => 'WebSite',
            '@id' => $this->absolute($this->config['homeUrl']) . '#website',
            'name' => $this->config['title'],
            'url' => $this->absolute($this->config['homeUrl']),
            'description' => $this->siteDescription(),
            'inLanguage' => $this->config['lang'],
        ], static fn(mixed $value): bool => $value !== null);
    }

    /** @return array<string,mixed> WebSite, TechArticle and BreadcrumbList of a page */
    private function pageGraph(string $url): array
    {
        $page = $this->pages[$url];
        $website = $this->website();
        $pageUrl = $this->absolute($this->htmlUrl($url));

        $article = array_filter([
            '@type' => 'TechArticle',
            '@id' => $pageUrl . '#article',
            'headline' => $page['title'],
            'description' => $page['description'],
            'url' => $pageUrl,
            'inLanguage' => $this->config['lang'],
            'dateModified' => self::isoDate($page['updated']),
            'isPartOf' => ['@id' => $website['@id']],
        ], static fn(mixed $value): bool => $value !== null);

        $crumbs = [[$this->config['title'], $this->absolute($this->config['homeUrl'])]];
        foreach ($this->tree->breadcrumb($url) as $item) {
            if ($item['url'] !== null && isset($this->built[$item['url']])) {
                $crumbs[] = [$item['name'], $this->absolute($this->htmlUrl($item['url']))];
            }
        }
        $crumbs[] = [$page['title'], $pageUrl];
        $list = [];
        $seen = [];
        foreach ($crumbs as [$name, $item]) {
            if (isset($seen[$item])) {
                continue;
            }
            $seen[$item] = true;
            $list[] = ['@type' => 'ListItem', 'position' => count($list) + 1, 'name' => $name, 'item' => $item];
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [$website, $article, ['@type' => 'BreadcrumbList', 'itemListElement' => $list]],
        ];
    }

    // ------------------------------------------------------------------ URLs

    /** The twin of a page, relative to the start page's directory: x/index.html gives x.md. */
    public function markdownPath(string $url, bool $isHome = false): string
    {
        $html = ($this->outputPath)($url, $isHome);

        return $html === 'index.html' ? 'index.md' : substr($html, 0, -strlen('/index.html')) . '.md';
    }

    /** The URL path a page is published at, after `docs_root_suffix`. */
    private function htmlUrl(string $url): string
    {
        $html = ($this->outputPath)($url, false);

        return $html === 'index.html' ? $this->config['homeUrl'] : $this->homePath(substr($html, 0, -strlen('/index.html')));
    }

    private function homePath(string $relative): string
    {
        return rtrim($this->config['homeUrl'], '/') . '/' . $relative;
    }

    /** Published URL of a file relative to the start page's directory. */
    private function published(string $relative): string
    {
        return $this->absolute($this->homePath($relative));
    }

    /** $url with site.url in front when it is a path and site.url is set. */
    private function absolute(string $url): string
    {
        $origin = $this->config['site']['url'];

        return $origin !== null && str_starts_with($url, '/') && !str_starts_with($url, '//') ? $origin . $url : $url;
    }

    private function pageLink(string $url, string $title): string
    {
        return '[' . LlmsTxt::linkText($title) . '](' . $this->published($this->markdownPath($url)) . ')';
    }

    /**
     * A link in a twin of the page at $pageUrl: internal page links point at
     * the linked page's twin, other paths get site.url in front. A relative link
     * is resolved against the page's URL first, so it also works in
     * llms-full.txt. Anchors and external links stay as written.
     */
    private function link(string $href, string $pageUrl): string
    {
        if ($href === '' || $href[0] === '#' || $href[0] === '?' || Html::isExternal($href)) {
            return $href;
        }
        $prefix = $this->config['content']['linkPrefix'];
        if ($prefix !== null) {
            $href = RenderContext::replacePrefix($href, $prefix, $this->config['baseUrl']);
        }
        $parsed = parse_url($href, PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : $href;
        $suffix = substr($href, strlen($path));
        if (!str_starts_with($path, '/')) {
            $directory = dirname($this->htmlUrl($pageUrl) . 'x');
            $path = Builder::normalizePath(rtrim($directory, '/') . '/' . $path) . (str_ends_with($path, '/') ? '/' : '');
        }
        $normal = rtrim($path, '/') === '' ? '/' : rtrim($path, '/');

        if ($this->config['agents']['markdown']) {
            if ($this->config['home'] !== null && $normal === $this->config['homeUrl']) {
                $path = $this->homePath($this->markdownPath($normal, true));
            } elseif (isset($this->built[$normal])) {
                $path = $this->homePath($this->markdownPath($normal));
            }
        }

        return $this->absolute($path) . $suffix;
    }

    // ------------------------------------------------------------------ helpers

    private function document(string $url): Document
    {
        return ($this->parse)($this->file($url));
    }

    private function file(string $url): string
    {
        return $this->config['contentDir'] . '/' . ltrim($this->built[$url]['file'], '/');
    }

    /** site.description, else the lead of the start page, else the description of the root meta.json. */
    private function siteDescription(): ?string
    {
        foreach ([$this->config['site']['description'], $this->config['home']['hero']['lead'] ?? null, $this->tree->root()->description] as $candidate) {
            if ($candidate !== null && trim($candidate) !== '') {
                return self::oneLine($candidate);
            }
        }

        return null;
    }

    /** @param array<string,mixed> $group */
    private static function group(string $name, ?string $description, Slugger $slugger): array
    {
        $slug = $slugger->slug($name);

        return ['name' => $name, 'slug' => $slug === '' ? 'section' : $slug, 'description' => $description, 'items' => []];
    }

    /** @param array<string,mixed> $group */
    private static function count(array $group): int
    {
        $count = 0;
        foreach ($group['items'] as $item) {
            $count += isset($item['page']) ? 1 : self::count($item['group']);
        }

        return $count;
    }

    /** @param array<string,mixed> $group */
    private static function firstPage(array $group): string
    {
        $item = $group['items'][0];

        return isset($item['page']) ? $item['page'] : self::firstPage($item['group']);
    }

    /** "2026-09-13" as it is, "13.09.2026" as "2026-09-13", anything else null. */
    private static function isoDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $value;
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return null;
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** JSON for a `<script type="application/ld+json">`: `<`, `>` and `&` escaped. */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }
}
