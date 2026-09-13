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
 *
 * Seams. The parsing, tree, rendering and search libraries live in src/lib,
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
 *   new AgentSite(array $config, Tree, Closure $parse, Closure $asset, Closure $outputPath),
 *   AgentSite::pageActions($url), ::head($url), ::homeHead(), ::write($target, Closure $authored)
 *   nd_header($config, $url, $tabs, $selected), nd_sidebar($config, $tree, $url, $groups),
 *   nd_toc_popover($toc, $pageName), nd_page($data, $breadcrumb, $body, $footerItems, $actions),
 *   nd_page_actions($actions), nd_toc($toc),
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

    /** @var array<string, Document> parsed pages by file */
    private array $documents = [];

    /**
     * @param array<string, mixed> $config normalised, see Config
     * @param bool $dev include pages whose file name starts with "_"
     * @param ?string $only render only pages whose URL contains this string
     */
    public function __construct(
        protected readonly array $config,
        protected readonly bool $dev = false,
        protected readonly ?string $only = null,
    ) {
    }

    /** Repository root: src/.. */
    public static function root(): string
    {
        return dirname(__DIR__);
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

        $config = $this->config;
        $tree = $this->tree();
        $this->checkDocsRoot($tree);
        $agents = $this->agentSite($tree);

        $pages = 0;
        foreach ($tree->pages() as $page) {
            if ($this->only !== null && !str_contains($page['url'], $this->only)) {
                continue;
            }
            Fs::write($target, $this->outputPath($page['url']), $this->renderPage($tree, $page, $agents));
            $pages++;
        }
        if ($config['home'] !== null && ($this->only === null || str_contains($config['homeUrl'], $this->only))) {
            Fs::write($target, $this->outputPath($config['homeUrl'], true), $this->renderHome($tree, $agents));
            $pages++;
        }

        $this->writeTheme($target);
        $this->writeCopies($target);
        $indexed = $this->writeSearchIndex($tree, $target);
        $redirects = Htaccess::write($config, $target);
        $warnings = $agents->write($target, fn(string $relative): bool => $this->authored($relative));

        return ['pages' => $pages, 'indexed' => $indexed, 'redirects' => $redirects, 'warnings' => $warnings];
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
        foreach (['src/lib', 'src/lib/Highlight', 'src/templates', 'src/components'] as $dir) {
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

    protected function agentSite(Tree $tree): AgentSite
    {
        return new AgentSite(
            $this->config,
            $tree,
            fn(string $file): Document => $this->parse($file),
            fn(string $src, string $pageFile): string => $this->resolveAsset($src, $pageFile)['url'],
            fn(string $url, bool $isHome): string => $this->outputPath($url, $isHome),
        );
    }

    /**
     * Whether the site provides a file below the start page's directory itself:
     * it is kept (`output.keep`) or a `copy` directory holds it. Agent files
     * such as robots.txt are then not generated.
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
    protected function renderPage(Tree $tree, array $page, ?AgentSite $agents = null): string
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

        $inner = nd_header($config, $url, $tabs['tabs'], $tabs['selected'])
            . nd_sidebar($config, $tree, $url, $tabs['groups'])
            . nd_toc_popover($toc, $pageName)
            . nd_page($data, $tree->breadcrumb($url), $this->body($document, $file), $tree->footerItems($url), $actions === null ? '' : nd_page_actions($actions))
            . nd_toc($toc);

        $title = str_replace('{title}', (string) ($frontmatter['title'] ?? $data['title']), $config['titleTemplate']);
        $head = $this->head($title, $data['description'], true) + ($agents?->head($url) ?? []);

        return nd_document($head, nd_search_dialog_portal() . nd_layout($inner));
    }

    protected function renderHome(Tree $tree, ?AgentSite $agents = null): string
    {
        Ids::reset();

        // The start page has no ScrollArea, so no scrollbar <style> either.
        return nd_document(
            $this->head($this->config['homeTitle'], null, false) + ($agents?->homeHead() ?? []),
            nd_search_dialog_portal() . nd_home_layout($this->config, $tree),
        );
    }

    /** @return array<string, mixed> the $head argument of nd_document() */
    protected function head(string $title, ?string $description, bool $scrollArea): array
    {
        $config = $this->config;

        return [
            'lang' => $config['lang'],
            'preset' => $config['theme']['preset'],
            'fontClass' => $config['theme']['fontClass'],
            'title' => $title,
            'description' => $description,
            'scrollArea' => $scrollArea,
            'assetBase' => $config['assetBase'],
            'baseUrl' => $config['baseUrl'],
            'searchIndexUrl' => $config['search']['indexUrl'],
            'icons' => $config['head']['icons'],
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

    /** Generated :root and .dark token blocks followed by `theme.palette_css`. */
    public function palette(): string
    {
        $theme = $this->config['theme'];
        $out = '';
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
