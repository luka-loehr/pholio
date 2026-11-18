<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/Fs.php';
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/Htaccess.php';

/**
 * Renders a site from a normalised config (see Config) into a target directory.
 *
 * One run writes, relative to the start page's directory:
 *   index.html, <section>/…/index.html   the pages
 *   <assets>/css/notebook.css            theme CSS with @import parts inlined and the palette inserted
 *   <assets>/fonts/**, <assets>/js/*.js  fonts and behaviour modules
 *   <assets>/LICENSES/*.txt              third-party licences
 *   <copy targets>                       the `copy` directories, without *.md
 *   <docs>/search-index.json             search index
 *   .htaccess                            hardening, redirects, slashless URLs, agent headers
 *   <page>.md, llms.txt, skill.md, …     files for AI agents (lib/AgentSite.php, docs/agents.md)
 *   og.png, og/<page>.png                share cards, with site.url (lib/ShareCards.php)
 *
 * Seams. The parsing, tree, rendering and search libraries live in src/lib
 * (share card drawing in src/lib/Card),
 * the components in src/components and the document shell in src/templates.
 * They are loaded on demand by loadGenerator(), which requires every PHP file
 * found there. The calls this class makes are the contract with them:
 *
 *   new Tree(string $contentDir, string $baseUrl, bool $includeDrafts, list<string> $extensions)
 *   Markdown::parse(string $source, string $file, array<string,string> $frontmatterAliases): Document
 *   Ids::reset(); Toc::build($document->headings())
 *   new RenderContext(Slugger, Closure $link, Closure $asset, string $copyLabel, ?Closure $imageSize),
 *   RenderContext::replacePrefix(string $value, string $from, string $to)
 *   Render::body(Document, RenderContext)
 *   SearchIndex::build(Tree, callable $load, string $baseUrl, ?array $order, bool $includeDrafts, string $tokenizer)
 *   new AgentSite(array $config, Tree, Closure $parse, Closure $asset, Closure $outputPath, ?Closure $image),
 *   AgentSite::pageActions($url), ::head($url), ::homeHead(), ::write($target, Closure $authored)
 *   new ShareCards(array $config, Tree, Closure $parse, Closure $outputPath),
 *   ShareCards::imageUrl($url), ::head($url), ::homeHead(), ::write($target, $urls, $home, Closure $authored)
 *   nd_header($config, $url, $tabs, $selected), nd_sidebar($config, $tree, $url, $groups, $slot),
 *   nd_toc_popover($toc, $pageName), nd_page($data, $breadcrumb, $body, $footerItems, $actions, $slot),
 *   nd_page_actions($actions), nd_toc($toc, $slot),
 *   nd_layout($inner), nd_search_dialog_portal(), nd_home_layout($config, $tree), nd_document($head, $body)
 *
 * Tests replace individual steps by subclassing; every step is a protected method.
 */
class Builder
{
    /** Marker line in theme/css/tokens.css replaced by the site palette. */
    public const PALETTE_MARKER = '/* @pholio:palette */';

    /** Files of the generator the build cannot run without. */
    public const REQUIRED = [
        'src/lib/Markdown.php', 'src/lib/Tree.php', 'src/lib/Render.php', 'src/lib/SearchIndex.php',
        'src/lib/Toc.php', 'src/lib/Ids.php', 'src/templates/document.php', 'src/components/layout.php',
        'src/components/home-layout.php', 'theme/css/notebook.css',
    ];

    private static bool $loaded = false;

    /** @var ?array<string, string> slot name => HTML, null until the slot files have run */
    private ?array $slotHtml = null;

    /** @var array<string, Document> parsed pages by file */
    private array $documents = [];

    /**
     * @param array<string, mixed> $config normalised, see Config
     * @param bool $dev include pages whose file name starts with "_"
     * @param ?string $only render only pages whose URL contains this string
     * @param bool $checkLinks check every link of the finished build (`--no-link-check` turns it off)
     */
    public function __construct(
        protected readonly array $config,
        protected readonly bool $dev = false,
        protected readonly ?string $only = null,
        protected readonly bool $checkLinks = true,
    ) {
    }

    /** Repository root: src/.. */
    public static function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * A redirect away from an address this build publishes would hide that page:
     * the server answers with the redirect before it looks at the file.
     *
     * @param list<array{url:string}> $written
     * @throws ConfigException
     */
    private function checkRedirectSources(array $written): void
    {
        $norm = static fn(string $path): string => rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
        $published = [];
        foreach ($written as $page) {
            $published[$norm($page['url'])] = true;
        }
        foreach (Htaccess::redirects($this->config) as $i => $entry) {
            if (isset($published[$norm($entry['from'])])) {
                throw new ConfigException(
                    "redirect {$i}: a page is published at {$entry['from']} and the redirect would hide it; remove the redirect or the page",
                    $this->config['configFile'] ?: null,
                );
            }
        }
    }

    /** @return list<string> required generator files that are not present */
    public static function missingParts(): array
    {
        return array_values(array_filter(self::REQUIRED, static fn(string $f): bool => !is_file(self::root() . '/' . $f)));
    }

    /**
     * Build the site into $target, the directory of the start page.
     *
     * @return array{pages:int, indexed:int, redirects:int, warnings:list<string>}
     */
    public function build(string $target): array
    {
        $this->loadGenerator();
        I18n::use($this->config['lang'], $this->config['translations']);
        \Pholio\Highlight\Shiki::useThemes($this->config['theme']['code'] ?? ['light' => 'github-light', 'dark' => 'github-dark']);

        $config = $this->config;
        $tree = $this->tree();
        $this->checkDocsRoot($tree);
        $this->checkStrictContent($tree);
        $cards = $this->shareCards($tree);
        $agents = $this->agentSite($tree, $cards);

        $pages = 0;
        $written = [];
        $rendered = [];
        foreach ($tree->pages() as $page) {
            if ($this->only !== null && !str_contains($page['url'], $this->only)) {
                continue;
            }
            Fs::write($target, $this->outputPath($page['url']), $this->renderPage($tree, $page, $agents, $cards));
            $rendered[] = $page['url'];
            $written[] = ['url' => $page['url'], 'html' => $target . '/' . $this->outputPath($page['url']), 'file' => $config['contentDir'] . '/' . ltrim($page['file'], '/'), 'prose' => true];
            $pages++;
        }
        $home = $config['home'] !== null && ($this->only === null || str_contains($config['homeUrl'], $this->only));
        if ($home) {
            Fs::write($target, $this->outputPath($config['homeUrl'], true), $this->renderHome($tree, $agents, $cards));
            $written[] = ['url' => $config['homeUrl'], 'html' => $target . '/' . $this->outputPath($config['homeUrl'], true), 'file' => $config['configFile'] ?: null];
            $pages++;
        }

        $this->writeTheme($target);
        $this->writeCopies($target);
        // search.enabled false: no index file, no dialog, no triggers, no hotkey.
        $indexed = ($this->config['search']['enabled'] ?? true) ? $this->writeSearchIndex($tree, $target) : 0;
        $redirects = Htaccess::write($config, $target);
        $this->checkRedirectSources($written);
        $warnings = $agents->write($target, fn(string $relative): bool => $this->authored($relative));
        $cards->write($target, $rendered, $home, fn(string $relative): bool => $this->authored($relative));
        if ($this->only === null && $this->checkLinks) {
            $this->checkLinks($target, $written);
        }

        return ['pages' => $pages, 'indexed' => $indexed, 'redirects' => $redirects, 'warnings' => $warnings];
    }

    /**
     * Every link and image of the written pages must reach something the site
     * publishes (lib/LinkCheck.php). Skipped for `--only`, whose output is partial,
     * and for `--no-link-check`.
     *
     * @param list<array{url:string, html:string, file:?string}> $written
     * @throws ContentException listing every broken link
     */
    protected function checkLinks(string $target, array $written): void
    {
        $findings = (new LinkCheck($target, $this->config['homeUrl'], self::siteRoot($this->config, $target), $this->config['site']['url']))->check($written);
        if ($findings !== []) {
            throw new ContentException(
                count($findings) . ' broken link' . (count($findings) === 1 ? '' : 's') . ":\n  " . implode("\n  ", $findings),
            );
        }
    }

    /**
     * strict_content: every page needs a description in its frontmatter (search results,
     * the agent files and share cards show it). All missing ones are listed at once.
     *
     * @throws ContentException listing every page without a description
     */
    protected function checkStrictContent(Tree $tree): void
    {
        if (!($this->config['strictContent'] ?? false)) {
            return;
        }
        $missing = [];
        foreach ($tree->pages() as $page) {
            if (trim((string) ($page['data']['description'] ?? '')) === '') {
                $missing[] = $this->relativeToProject($this->config['contentDir'] . '/' . ltrim((string) $page['file'], '/'));
            }
        }
        if ($missing !== []) {
            throw new ContentException(
                'strict_content: ' . count($missing) . ' page' . (count($missing) === 1 ? '' : 's') . ' without a description in the frontmatter:' . "\n  " . implode("\n  ", $missing),
            );
        }
    }

    /** Require the libraries, components and templates once. */
    protected function loadGenerator(): void
    {
        if (self::$loaded) {
            return;
        }
        $missing = self::missingParts();
        if ($missing !== []) {
            throw new \LogicException('generator incomplete, missing: ' . implode(', ', $missing));
        }
        foreach (['src/lib', 'src/lib/Highlight', 'src/lib/Card', 'src/templates', 'src/components'] as $dir) {
            foreach (glob(self::root() . '/' . $dir . '/*.php') ?: [] as $file) {
                require_once $file;
            }
        }
        self::$loaded = true;
    }

    // ------------------------------------------------------------ pages

    protected function tree(): Tree
    {
        return new Tree($this->config['contentDir'], $this->config['baseUrl'], $this->dev, $this->config['content']['extensions']);
    }

    /** Parsed once per build: the pages, the search index and the agent files all read the same documents. */
    protected function parse(string $file): Document
    {
        if (isset($this->documents[$file])) {
            return $this->documents[$file];
        }
        if (!is_file($file)) {
            throw new ContentException('page file not found', $file);
        }

        return $this->documents[$file] = Markdown::parse((string) file_get_contents($file), $file, $this->config['content']['frontmatterAliases']);
    }

    protected function agentSite(Tree $tree, ?ShareCards $cards = null): AgentSite
    {
        return new AgentSite(
            $this->config,
            $tree,
            fn(string $file): Document => $this->parse($file),
            fn(string $src, string $pageFile): string => $this->resolveAsset($src, $pageFile)['url'],
            fn(string $url, bool $isHome): string => $this->outputPath($url, $isHome),
            $cards?->enabled() ? fn(string $url): ?string => $cards->imageUrl($url) : null,
        );
    }

    protected function shareCards(Tree $tree): ShareCards
    {
        return new ShareCards(
            $this->config,
            $tree,
            fn(string $file): Document => $this->parse($file),
            fn(string $url, bool $isHome): string => $this->outputPath($url, $isHome),
        );
    }

    /**
     * Whether the site provides a file below the start page's directory itself:
     * it is kept (`output.keep`) or a `copy` directory holds it. Agent files
     * such as robots.txt and share cards are then not generated.
     */
    protected function authored(string $relative): bool
    {
        if (Fs::isKept($relative, $this->config['keep'])) {
            return true;
        }
        $url = rtrim($this->config['homeUrl'], '/') . '/' . $relative;
        foreach ($this->config['copy'] as $copy) {
            $to = rtrim($copy['to'], '/');
            if (str_starts_with($url, $to . '/') && is_file($copy['from'] . '/' . substr($url, strlen($to) + 1))) {
                return true;
            }
        }

        return false;
    }

    /** @param array{url:string, slugs:list<string>, file:string} $page */
    protected function renderPage(Tree $tree, array $page, ?AgentSite $agents = null, ?ShareCards $cards = null): string
    {
        Ids::reset();
        $config = $this->config;

        $url = $page['url'];
        $file = $config['contentDir'] . '/' . ltrim($page['file'], '/');
        $document = $this->parse($file);
        $toc = Toc::build($document->headings());
        $frontmatter = $document->frontmatter;

        $data = [
            'title' => (string) ($frontmatter['heading'] ?? $frontmatter['title'] ?? ''),
            'description' => isset($frontmatter['description']) ? (string) $frontmatter['description'] : null,
            'updated' => isset($frontmatter['updated']) ? (string) $frontmatter['updated'] : null,
            'full' => ($frontmatter['full'] ?? false) === true || ($frontmatter['full'] ?? '') === 'true',
        ];

        $tabs = $tree->tabsFor($url);
        $path = $tree->pathTo($url);
        $pageName = $path === [] ? $data['title'] : (string) $path[count($path) - 1]->name;

        $actions = $agents?->pageActions($url);

        $slots = $this->slots();

        $inner = nd_header($config, $url, $tabs['tabs'], $tabs['selected'])
            . nd_sidebar($config, $tree, $url, $tabs['groups'], $slots['sidebar'])
            . nd_toc_popover($toc, $pageName)
            . nd_page($data, $tree->breadcrumb($url), $this->body($document, $file), $tree->footerItems($url), $actions === null ? '' : nd_page_actions($actions), $slots['footer'])
            . nd_toc($toc, $slots['toc']);

        $title = str_replace('{title}', (string) ($frontmatter['title'] ?? $data['title']), $config['titleTemplate']);
        $head = $this->head($title, $data['description'], true, $this->canonical($url, false)) + ($agents?->head($url) ?? []) + ['social' => $cards?->head($url) ?? []];

        return nd_document($head, $this->searchPortal() . nd_layout($inner));
    }

    protected function renderHome(Tree $tree, ?AgentSite $agents = null, ?ShareCards $cards = null): string
    {
        Ids::reset();

        // The start page has no ScrollArea, so no scrollbar <style> either.
        return nd_document(
            $this->head($this->config['homeTitle'], null, false, $this->canonical($this->config['homeUrl'], true)) + ($agents?->homeHead() ?? []) + ['social' => $cards?->homeHead() ?? []],
            $this->searchPortal() . nd_home_layout($this->config, $tree),
        );
    }

    /**
     * Absolute URL of a page for <link rel="canonical">, in the form the sitemap and og:url use;
     * null without base_url and site.url.
     */
    protected function canonical(string $url, bool $isHome): ?string
    {
        $origin = $this->config['canonicalOrigin'] ?? null;
        if ($origin === null) {
            return null;
        }
        $html = $this->outputPath($url, $isHome);
        $path = $html === 'index.html'
            ? $this->config['homeUrl']
            : rtrim($this->config['homeUrl'], '/') . '/' . substr($html, 0, -strlen('/index.html'));

        return $origin . $path;
    }

    /** @return array<string, mixed> the $head argument of nd_document() */
    protected function head(string $title, ?string $description, bool $scrollArea, ?string $canonical = null): array
    {
        $config = $this->config;

        return [
            'canonical' => $canonical,
            'lang' => $config['lang'],
            'fontClass' => $config['theme']['fontClass'],
            'defaultScheme' => $config['theme']['defaultScheme'] ?? 'system',
            'title' => $title,
            'description' => $description,
            'scrollArea' => $scrollArea,
            'assetBase' => $config['assetBase'],
            'baseUrl' => $config['baseUrl'],
            'searchIndexUrl' => ($config['search']['enabled'] ?? true) ? $config['search']['indexUrl'] : null,
            'icons' => $config['head']['icons'],
            'preload' => $config['head']['preload'] ?? [],
            'manifest' => $config['head']['manifest'],
            'themeColor' => $config['head']['themeColor'],
        ];
    }

    /**
     * The contents of the prose container, rendered from the Markdown AST.
     *
     * $pageFile is the page's Markdown file; relative image paths resolve
     * against its directory (see resolveAsset()).
     */
    protected function body(Document $document, string $pageFile): string
    {
        $content = $this->config['content'];
        $baseUrl = $this->config['baseUrl'];
        $linkPrefix = $content['linkPrefix'];

        return Render::body($document, new RenderContext(
            new Slugger(),
            static fn(string $href): string => $linkPrefix === null ? $href : RenderContext::replacePrefix($href, $linkPrefix, $baseUrl),
            fn(string $src): string => $this->resolveAsset($src, $pageFile)['url'],
            I18n::t('Copy Anchor Link(heading anchor)(aria-label)'),
            fn(string $src): ?array => $this->imageSize($src, $pageFile),
        ));
    }

    /**
     * Published URL and source file of an image or file referenced from a page.
     *
     * - A relative path ("../assets/images/x.png") resolves against the page
     *   file's directory and must lie inside a `copy` source directory (by
     *   default assets/); the URL is that directory's URL plus the rest.
     * - A path starting with `content.asset_prefix` is rewritten to
     *   `content.asset_target`; its file lies below `content.asset_root`.
     * - Any other absolute path ("/assets/images/x.png") stays as written; its
     *   file is looked up in the `copy` directory whose URL it starts with.
     * - URLs with a scheme, protocol-relative URLs and fragments stay as written.
     *
     * @return array{url:string, file:?string}
     * @throws ContentException for a relative path outside the copied directories
     */
    public function resolveAsset(string $src, ?string $pageFile = null): array
    {
        if ($src === '' || $src[0] === '#' || str_starts_with($src, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $src) === 1) {
            return ['url' => $src, 'file' => null];
        }
        $content = $this->config['content'];
        $path = (string) (parse_url($src, PHP_URL_PATH) ?? $src);
        $suffix = substr($src, strlen($path));

        if ($src[0] !== '/') {
            if ($pageFile === null) {
                return ['url' => $src, 'file' => null];
            }
            $file = self::normalizePath(dirname($pageFile) . '/' . $path);
            foreach ($this->config['copy'] as $copy) {
                if (str_starts_with($file, $copy['from'] . '/')) {
                    return ['url' => rtrim($copy['to'], '/') . '/' . substr($file, strlen($copy['from']) + 1) . $suffix, 'file' => $file];
                }
            }
            $dirs = array_map(fn(array $c): string => $this->relativeToProject($c['from']) . '/', $this->config['copy']);
            throw new ContentException(
                'image "' . $src . '" resolves to ' . $this->relativeToProject($file) . ', outside the copied asset directories ('
                . ($dirs === [] ? 'none configured' : implode(', ', $dirs)) . '); move the file there or use its published URL',
                $pageFile,
            );
        }

        $prefix = $content['assetPrefix'];
        if ($prefix !== null && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
            $root = $content['assetRoot'] ?? $this->config['contentDir'];

            return [
                'url' => RenderContext::replacePrefix($src, $prefix, $content['assetTarget']),
                'file' => rtrim($root, '/') . '/' . ltrim(substr($path, strlen($prefix)), '/'),
            ];
        }

        foreach ($this->config['copy'] as $copy) {
            $to = rtrim($copy['to'], '/');
            if (str_starts_with($path, $to . '/')) {
                return ['url' => $src, 'file' => $copy['from'] . '/' . substr($path, strlen($to) + 1)];
            }
        }

        return ['url' => $src, 'file' => $this->config['contentDir'] . $path];
    }

    /**
     * Natural size of a content image, from the file resolveAsset() finds.
     * SVG carries its size in attributes or the viewBox, raster images are read
     * with getimagesize().
     *
     * @return array{0:int,1:int}|null
     */
    public function imageSize(string $src, ?string $pageFile = null): ?array
    {
        $file = $this->resolveAsset($src, $pageFile)['file'];

        return $file === null ? null : self::measure($file);
    }

    /** Resolve "." and ".." segments without touching the file system. */
    public static function normalizePath(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        return '/' . implode('/', $out);
    }

    private function relativeToProject(string $path): string
    {
        $dir = $this->config['configDir'];

        return str_starts_with($path, $dir . '/') ? substr($path, strlen($dir) + 1) : $path;
    }

    /** @return array{0:int,1:int}|null */
    public static function measure(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'svg') {
            $head = (string) file_get_contents($file, false, null, 0, 4096);
            if (preg_match('/<svg\b[^>]*>/i', $head, $tag) !== 1) {
                return null;
            }
            if (preg_match('/\bwidth="([\d.]+)"/i', $tag[0], $w) === 1
                && preg_match('/\bheight="([\d.]+)"/i', $tag[0], $h) === 1) {
                return [(int) round((float) $w[1]), (int) round((float) $h[1])];
            }
            if (preg_match('/\bviewBox="[\d.\s-]*?([\d.]+)\s+([\d.]+)"/i', $tag[0], $v) === 1) {
                return [(int) round((float) $v[1]), (int) round((float) $v[2])];
            }

            return null;
        }

        $size = @getimagesize($file);

        return $size === false ? null : [(int) $size[0], (int) $size[1]];
    }

    /**
     * When the docs root and the start page share a URL, the docs root page
     * needs `docs_root_suffix`.
     */
    protected function checkDocsRoot(Tree $tree): void
    {
        $config = $this->config;
        if ($config['home'] === null || $config['baseUrl'] !== $config['homeUrl'] || $config['docsRootSuffix'] !== null) {
            return;
        }
        foreach ($tree->pages() as $page) {
            if (rtrim($page['url'], '/') === rtrim($config['baseUrl'], '/')) {
                throw new ConfigException(
                    'the docs root page ' . $page['file'] . ' and the start page share the URL ' . $config['homeUrl']
                    . '; set docs_root_suffix (e.g. "/overview"), set docs_path, or set home to null',
                    $config['configFile'] ?: null,
                );
            }
        }
    }

    // ------------------------------------------------------------ assets

    /**
     * Theme files: theme/css/notebook.css with its `@import "./x.css";` lines
     * replaced by the parts (one request), the palette marker replaced by the
     * site palette, theme/fonts/**, theme/js/*.js and licenses/*.txt.
     */
    protected function writeTheme(string $target): void
    {
        $assetDir = $this->urlDir($target, $this->config['assetBase']);
        $theme = self::root() . '/theme';

        $entry = (string) file_get_contents($theme . '/css/notebook.css');
        $css = (string) preg_replace_callback(
            '/^@import\s+"\.\/([^"]+)";\s*$/m',
            static function (array $m) use ($theme): string {
                $part = $theme . '/css/' . $m[1];
                if (!is_file($part)) {
                    throw new \LogicException('theme stylesheet part missing: ' . $part);
                }

                return '/* ---- ' . $m[1] . " ---- */\n" . rtrim((string) file_get_contents($part)) . "\n";
            },
            $entry,
        );
        if (str_contains($css, self::PALETTE_MARKER)) {
            $css = str_replace(self::PALETTE_MARKER, rtrim($this->palette()), $css);
        }
        $css .= $this->customCss();
        Fs::write($assetDir, 'css/notebook.css', $css);

        Fs::copyDir($theme . '/fonts', $assetDir . '/fonts');

        // Modules only: no subdirectories and no notes.
        foreach (Fs::files($theme . '/js') as $name) {
            if (str_ends_with($name, '.js')) {
                Fs::copyFile($theme . '/js/' . $name, $assetDir . '/js/' . $name);
            }
        }

        foreach (Fs::files(self::root() . '/licenses') as $name) {
            if (str_ends_with($name, '.txt')) {
                Fs::copyFile(self::root() . '/licenses/' . $name, $assetDir . '/LICENSES/' . $name);
            }
        }
    }

    /**
     * Generated token blocks followed by `theme.palette_css`: `theme.tokens` as
     * custom properties of :root (fonts, radii and any other design token, both
     * schemes), then the colour tokens of :root and .dark.
     */
    public function palette(): string
    {
        $theme = $this->config['theme'];
        $out = '';
        if (($theme['tokens'] ?? []) !== []) {
            $out .= ":root {\n";
            foreach ($theme['tokens'] as $token => $value) {
                if (preg_match('/^[a-z][a-z0-9-]*$/', (string) $token) !== 1 || preg_match('/[;{}]/', $value) === 1) {
                    throw new ConfigException("theme.tokens: invalid token {$token}: {$value}", $this->config['configFile'] ?: null);
                }
                $out .= '  --' . $token . ': ' . $value . ";\n";
            }
            $out .= "}\n";
        }
        foreach ([':root' => $theme['light'], '.dark' => $theme['dark']] as $selector => $tokens) {
            if ($tokens === []) {
                continue;
            }
            $out .= $selector . " {\n";
            foreach ($tokens as $token => $value) {
                if (preg_match('/^[a-z0-9-]+$/', (string) $token) !== 1 || preg_match('/[;{}]/', $value) === 1) {
                    throw new ConfigException("theme: invalid colour token {$token}: {$value}", $this->config['configFile'] ?: null);
                }
                $out .= '  --color-fd-' . $token . ': ' . $value . ";\n";
            }
            $out .= "}\n";
        }
        if ($theme['paletteCss'] !== null) {
            if (!is_file($theme['paletteCss'])) {
                throw new ConfigException('theme.palette_css not found: ' . $theme['paletteCss'], $this->config['configFile'] ?: null);
            }
            $out .= (string) file_get_contents($theme['paletteCss']);
        }

        return $out;
    }

    /**
     * `theme.custom_css`, appended after the whole theme stylesheet (unlayered, so
     * it wins over every theme rule), or "" without one. url() values in it are
     * written as published URLs, such as /assets/fonts/x.woff2.
     */
    public function customCss(): string
    {
        $file = $this->config['theme']['customCss'] ?? null;
        if ($file === null) {
            return '';
        }
        if (!is_file($file)) {
            throw new ConfigException('theme.custom_css not found: ' . $file, $this->config['configFile'] ?: null);
        }

        return "\n/* ---- theme.custom_css ---- */\n" . rtrim((string) file_get_contents($file)) . "\n";
    }

    /**
     * `slots`: the HTML of every slot, "" for a slot that is not set. Each file is
     * run once per build, in a scope of its own, and has to return a string; the
     * same HTML goes on every docs page, verbatim.
     *
     * @return array{sidebar:string, toc:string, footer:string}
     */
    public function slots(): array
    {
        if ($this->slotHtml !== null) {
            return $this->slotHtml;
        }
        $configFile = $this->config['configFile'] ?: null;
        $html = array_fill_keys(Config::SLOTS, '');
        foreach ($this->config['slots'] ?? [] as $name => $file) {
            if (!is_file($file)) {
                throw new ConfigException("slots.{$name} not found: " . $file, $configFile);
            }
            try {
                $value = (static function (string $__file) {
                    return require $__file;
                })($file);
            } catch (Exception $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new ConfigException("slots.{$name}: the slot file could not be loaded: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e);
            }
            if (!is_string($value)) {
                throw new ConfigException("slots.{$name}: the slot file must return a string of HTML, got " . get_debug_type($value) . ': ' . $file, $configFile);
            }
            $html[$name] = trim($value);
        }

        return $this->slotHtml = $html;
    }

    /** The `copy` directories, verbatim and without *.md files; a missing optional one is skipped. */
    protected function writeCopies(string $target): void
    {
        foreach ($this->config['copy'] as $copy) {
            if (!is_dir($copy['from']) && ($copy['optional'] ?? false)) {
                continue;
            }
            if (!is_dir($copy['from'])) {
                throw new ConfigException('copy source directory not found: ' . $copy['from'], $this->config['configFile'] ?: null);
            }
            Fs::copyDir(
                $copy['from'],
                $this->urlDir($target, $copy['to']),
                static fn(string $relative): bool => !str_ends_with(strtolower($relative), '.md'),
            );
        }
    }

    /** The search dialog's portal, or nothing when search.enabled is false. */
    protected function searchPortal(): string
    {
        return ($this->config['search']['enabled'] ?? true) ? nd_search_dialog_portal() : '';
    }

    /** @return int number of indexed pages */
    protected function writeSearchIndex(Tree $tree, string $target): int
    {
        $config = $this->config;
        $index = SearchIndex::build(
            $tree,
            fn(array $page): Document => $this->parse($config['contentDir'] . '/' . ltrim((string) $page['file'], '/')),
            $config['baseUrl'],
            null,
            $this->dev,
            $config['search']['tokenizer'],
        );

        $json = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        Fs::write($this->urlDir($target, $config['baseUrl']), $config['search']['indexPath'], $json);

        return count($index['pages']);
    }

    // ------------------------------------------------------------ paths

    /**
     * Output path of a URL, relative to the start page's directory.
     *
     * $isHome separates the start page from a docs root page with the same URL,
     * which moves to `docs_root_suffix`.
     */
    public function outputPath(string $url, bool $isHome = false): string
    {
        $config = $this->config;
        if (!$isHome && $config['home'] !== null && $config['docsRootSuffix'] !== null
            && rtrim($url, '/') === rtrim($config['baseUrl'], '/') && $config['baseUrl'] === $config['homeUrl']) {
            $url = rtrim($url, '/') . $config['docsRootSuffix'];
        }

        $home = rtrim($config['homeUrl'], '/');
        $url = rtrim($url, '/');
        if ($url === $home) {
            return 'index.html';
        }
        $relative = str_starts_with($url, $home . '/') ? substr($url, strlen($home) + 1) : trim($url, '/');

        return $relative . '/index.html';
    }

    /**
     * File system directory for a URL directory.
     *
     * URLs below homeUrl live below $target. Others are resolved from the site
     * root, which is as many levels above $target as homeUrl has segments.
     */
    public function urlDir(string $target, string $url): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1) {
            throw new ConfigException('cannot write to an absolute URL: ' . $url, $this->config['configFile'] ?: null);
        }
        $home = rtrim($this->config['homeUrl'], '/');
        $url = rtrim($url, '/');
        if ($url === $home) {
            return $target;
        }
        if (str_starts_with($url, $home . '/')) {
            return $target . '/' . substr($url, strlen($home) + 1);
        }

        return self::siteRoot($this->config, $target) . '/' . ltrim($url, '/');
    }

    /** The directory serving "/", derived from the start page's directory. */
    public static function siteRoot(array $config, string $target): string
    {
        $root = rtrim($target, '/');
        foreach (array_filter(explode('/', trim($config['homeUrl'], '/')), 'strlen') as $ignored) {
            $root = dirname($root);
        }

        return $root;
    }

    /**
     * Where a build below a fresh root directory puts the start page: the root
     * plus homeUrl's segments, so assets outside homeUrl stay inside the root.
     */
    public static function targetBelow(array $config, string $root): string
    {
        return rtrim(rtrim($root, '/') . '/' . trim($config['homeUrl'], '/'), '/');
    }
}
