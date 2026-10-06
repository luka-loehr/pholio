<?php

declare(strict_types=1);

namespace Pholio;

/**
 * Checks every link and image of a finished build against what the build wrote.
 *
 * It reads the written HTML, not the Markdown, so links from component tags
 * (cards, buttons), rewritten prefixes and the start page are checked the way a
 * browser resolves them. On content pages only the prose is read: the sidebar,
 * breadcrumb and table of contents come from the page tree, and the header
 * links are the ones the start page already shows.
 *
 *   /guide/install           a page (guide/install/index.html) or a file below the site root
 *   install                  relative to the page URL, which has no trailing slash:
 *                            from /guide/setup it is /guide/install, from /guide it is /install
 *   #usage, /guide#usage     a page plus an element id in that page
 *   https://<site.url>/x     an absolute link to the site itself, checked like /x
 *
 * Links with another scheme or host are not fetched. A finding names the
 * content file and the line where the link is written when the target text
 * appears there; otherwise the page URL.
 */
final class LinkCheck
{
    /** @var array<string, list<string>> HTML file => element ids */
    private array $ids = [];

    /**
     * @param string $target directory of the start page, which serves $homeUrl
     * @param string $siteRoot directory that serves "/", for URLs outside $homeUrl
     * @param ?string $siteUrl `site.url`, absolute links to it are internal
     */
    public function __construct(
        private readonly string $target,
        private readonly string $homeUrl,
        private readonly string $siteRoot,
        private readonly ?string $siteUrl = null,
    ) {
    }

    /**
     * @param list<array{url:string, html:string, file:?string, prose?:bool}> $pages page URL, written
     *        HTML file, source file (content page or config); prose: read only the page's prose container
     * @return list<string> findings, "file:line: message"
     */
    public function check(array $pages): array
    {
        $findings = [];
        foreach ($pages as $page) {
            $html = (string) file_get_contents($page['html']);
            if ($page['prose'] ?? false) {
                $start = strpos($html, 'class="nd-prose prose"');
                $end = $start === false ? false : strpos($html, '</article>', $start);
                $html = $start === false ? '' : substr($html, $start, $end === false ? null : $end - $start);
            }
            if (preg_match_all('/<(a|img)\b[^>]*?\s(href|src)="([^"]*)"/i', $html, $matches, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($matches as [, $tag, , $raw]) {
                $href = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $problem = $this->problem($href, $page['url'], strtolower($tag) === 'img');
                if ($problem === null) {
                    continue;
                }
                $where = $this->locate($href, $page);
                $findings[$where . ': ' . $problem] = true;
            }
        }

        return array_keys($findings);
    }

    /** What is wrong with one link of the page at $pageUrl, or null. */
    public function problem(string $href, string $pageUrl, bool $image = false): ?string
    {
        $kind = $image ? 'image' : 'link';
        $target = $href;
        if ($this->siteUrl !== null && ($target === $this->siteUrl || str_starts_with($target, $this->siteUrl . '/')
            || str_starts_with($target, $this->siteUrl . '#') || str_starts_with($target, $this->siteUrl . '?'))) {
            $target = substr($target, strlen($this->siteUrl));
            $target = $target === '' || $target[0] !== '/' ? '/' . $target : $target;
        }
        if ($target === '' || str_starts_with($target, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1) {
            return null;
        }

        $fragment = null;
        if (($hash = strpos($target, '#')) !== false) {
            $fragment = substr($target, $hash + 1);
            $target = substr($target, 0, $hash);
        }
        if (($query = strpos($target, '?')) !== false) {
            $target = substr($target, 0, $query);
        }

        if ($target === '') {
            $path = $pageUrl;
        } elseif ($target[0] === '/') {
            $path = $target;
        } else {
            $path = substr($pageUrl, 0, (int) strrpos($pageUrl, '/') + 1) . $target;
        }
        $path = self::normalize(rawurldecode($path), str_ends_with($target, '/'));

        $file = $this->resolve($path);
        if ($file === null) {
            return "broken {$kind} \"{$href}\": nothing is published at {$path}";
        }
        if ($fragment === null || $fragment === '' || $image || !str_ends_with($file, '.html')) {
            return null;
        }
        $id = rawurldecode($fragment);
        if (!in_array($id, $this->idsOf($file), true)) {
            return "broken link \"{$href}\": {$path} has no element with the id \"{$id}\"";
        }

        return null;
    }

    /** The file that serves a URL path: the file itself or its index.html. */
    private function resolve(string $path): ?string
    {
        $home = rtrim($this->homeUrl, '/');
        $file = $path === $home || str_starts_with($path, $home . '/')
            ? rtrim($this->target, '/') . substr($path, strlen($home))
            : rtrim($this->siteRoot, '/') . $path;
        if ($path !== '/' && !str_ends_with($path, '/') && is_file($file)) {
            return $file;
        }
        $index = rtrim($file, '/') . '/index.html';

        return is_file($index) ? $index : null;
    }

    /** @return list<string> */
    private function idsOf(string $file): array
    {
        if (!isset($this->ids[$file])) {
            preg_match_all('/\sid="([^"]*)"/', (string) file_get_contents($file), $m);
            $this->ids[$file] = array_map(
                static fn(string $id): string => html_entity_decode($id, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $m[1],
            );
        }

        return $this->ids[$file];
    }

    /** "file:line" of the link in its source, "file" when the text is not there, or the page URL. */
    private function locate(string $href, array $page): string
    {
        $file = $page['file'];
        if ($file === null || !is_file($file)) {
            return $page['url'];
        }
        $needle = $href;
        if ($this->siteUrl === null || !str_starts_with($needle, $this->siteUrl)) {
            $needle = (string) preg_replace('/^[^#?]*\//', '', $needle) ?: $href;
        }
        foreach (file($file) ?: [] as $index => $line) {
            if (str_contains($line, $href)) {
                return $file . ':' . ($index + 1);
            }
        }
        foreach (file($file) ?: [] as $index => $line) {
            if ($needle !== '' && str_contains($line, $needle)) {
                return $file . ':' . ($index + 1);
            }
        }

        return $file . ' (' . $page['url'] . ')';
    }

    /** Resolve "." and ".." segments; keeps a trailing slash when $slash is set. */
    private static function normalize(string $path, bool $slash): string
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
        $normal = '/' . implode('/', $out);

        return $slash && $normal !== '/' ? $normal . '/' : $normal;
    }
}
