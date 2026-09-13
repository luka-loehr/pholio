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
 *   .htaccess                            hardening, redirects, slashless URLs
 *
 * Seams. The parsing, tree, rendering and search libraries live in src/lib,
 * the components in src/components and the document shell in src/templates.
 * They are loaded on demand by loadGenerator(), which requires every PHP file
 * found there. The calls this class makes are the contract with them:
 *
 *   new Tree(string $contentDir, string $baseUrl, bool $includeDrafts, list<string> $extensions)
 *   Markdown::parse(string $source, string $file, array<string,string> $frontmatterAliases): Document
 *   Ids::reset(); Toc::build($document->headings())
 *   RenderContext::create(baseUrl:, linkPrefix:, assetPrefix:, assetTarget:, copyLabel:, imageSize:)
 *   Render::body(Document, RenderContext)
 *   SearchIndex::build(Tree, callable $load, string $baseUrl, ?array $order, bool $includeDrafts, string $tokenizer)
 *   nd_header($config, $url, $tabs, $selected), nd_sidebar($config, $tree, $url, $groups),
 *   nd_toc_popover($toc, $pageName), nd_page($data, $breadcrumb, $body, $footerItems), nd_toc($toc),
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
     * @return array{pages:int, indexed:int, redirects:int}
     */
    public function build(string $target): array
    {
        $this->loadGenerator();
        I18n::use($this->config['lang'], $this->config['translations']);

        $config = $this->config;
        $tree = $this->tree();
        $this->checkDocsRoot($tree);

        $pages = 0;
        foreach ($tree->pages() as $page) {
            if ($this->only !== null && !str_contains($page['url'], $this->only)) {
                continue;
            }
            Fs::write($target, $this->outputPath($page['url']), $this->renderPage($tree, $page));
            $pages++;
        }
        if ($config['home'] !== null && ($this->only === null || str_contains($config['homeUrl'], $this->only))) {
            Fs::write($target, $this->outputPath($config['homeUrl'], true), $this->renderHome($tree));
            $pages++;
        }

        $this->writeTheme($target);
        $this->writeCopies($target);
        $indexed = $this->writeSearchIndex($tree, $target);
        $redirects = Htaccess::write($config, $target);

        return ['pages' => $pages, 'indexed' => $indexed, 'redirects' => $redirects];
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

    protected function parse(string $file): Document
    {
        if (!is_file($file)) {
            throw new ContentException('page file not found', $file);
        }

        return Markdown::parse((string) file_get_contents($file), $file, $this->config['content']['frontmatterAliases']);
    }

    /** @param array{url:string, slugs:list<string>, file:string} $page */
    protected function renderPage(Tree $tree, array $page): string
    {
        Ids::reset();
        $config = $this->config;

        $url = $page['url'];
        $document = $this->parse($config['contentDir'] . '/' . ltrim($page['file'], '/'));
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

        $inner = nd_header($config, $url, $tabs['tabs'], $tabs['selected'])
            . nd_sidebar($config, $tree, $url, $tabs['groups'])
            . nd_toc_popover($toc, $pageName)
            . nd_page($data, $tree->breadcrumb($url), $this->body($document), $tree->footerItems($url))
            . nd_toc($toc);

        $title = str_replace('{title}', (string) ($frontmatter['title'] ?? $data['title']), $config['titleTemplate']);

        return nd_document($this->head($title, $data['description'], true), nd_search_dialog_portal() . nd_layout($inner));
    }

    protected function renderHome(Tree $tree): string
    {
        Ids::reset();

        // The start page has no ScrollArea, so no scrollbar <style> either.
        return nd_document(
            $this->head($this->config['homeTitle'], null, false),
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

    /** The contents of the prose container, rendered from the Markdown AST. */
    protected function body(Document $document): string
    {
        $content = $this->config['content'];

        return Render::body($document, RenderContext::create(
            baseUrl: $this->config['baseUrl'],
            linkPrefix: $content['linkPrefix'],
            assetPrefix: $content['assetPrefix'],
            assetTarget: $content['assetTarget'],
            copyLabel: I18n::t('Copy Anchor Link(heading anchor)(aria-label)'),
            imageSize: fn(string $src): ?array => $this->imageSize($src),
        ));
    }

    /**
     * Natural size of a content image.
     *
     * $src is the path as written in the content. With `content.asset_prefix`
     * set, the prefix is stripped and the file is looked up below
     * `content.asset_root`; otherwise the path is resolved below the content
     * directory. SVG carries its size in attributes or the viewBox, raster
     * images are read with getimagesize().
     *
     * @return array{0:int,1:int}|null
     */
    public function imageSize(string $src): ?array
    {
        $content = $this->config['content'];
        $path = (string) (parse_url($src, PHP_URL_PATH) ?: $src);
        $prefix = $content['assetPrefix'];
        if ($prefix !== null && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
            $path = substr($path, strlen($prefix));
        }
        $root = $content['assetRoot'] ?? $this->config['contentDir'];
        $file = rtrim($root, '/') . '/' . ltrim($path, '/');

        return self::measure($file);
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

    /** The `copy` directories, verbatim and without *.md files. */
    protected function writeCopies(string $target): void
    {
        foreach ($this->config['copy'] as $copy) {
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
