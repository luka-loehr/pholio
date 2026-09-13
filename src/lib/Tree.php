<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../Exceptions.php';
require_once __DIR__ . '/Frontmatter.php';

/**
 * A node of the page tree: page, folder or separator.
 *
 * An object instead of an array because the tree code relies on node identity in
 * several places (`path.includes(node)`, `item.index !== path[i + 1]`, change
 * of ownership while collecting folders).
 */
final class Node
{
    public const PAGE = 'page';
    public const FOLDER = 'folder';
    public const SEPARATOR = 'separator';

    public ?string $name = null;
    public ?string $url = null;
    public ?string $description = null;
    public ?string $icon = null;
    public ?bool $root = null;
    public ?bool $defaultOpen = null;
    public ?bool $collapsible = null;
    public ?bool $external = null;
    public ?Node $index = null;
    /** @var list<Node> */
    public array $children = [];
    /** File of the page or folder path. */
    public ?string $ref = null;
    public ?string $refMeta = null;
    /** Slugs of the page (without the base URL). @var list<string> */
    public array $slugs = [];
    public string $id = '';
    /** Title from frontmatter/`meta.json` for sorting by `name` (`SymbolName`). */
    public ?string $sortName = null;
    public bool $unfinished = false;
    /** @var array{owner:string, priority:int}|null */
    public ?array $owner = null;

    public function __construct(public string $type)
    {
    }
}

/**
 * Page tree of a content directory (`meta.json` plus Markdown frontmatter): page
 * URLs, root areas, breadcrumbs, previous and next, and the sidebar state.
 *
 * The base URL is configurable because the docs can live under any path.
 */
final class Tree
{
    private const GROUP = '/^\((?<name>.+)\)$/';
    private const LINK = '/^(?<external>external:)?(?:\[(?<icon>[^\]]+)\])?\[(?<name>[^\]]+)\]\((?<url>[^)]+)\)$/';
    private const SEPARATOR = '/^---(?:\[(?<icon>[^\]]+)\])?(?<name>.+)---|^---$/';
    private const REST = '...';
    private const REST_REVERSED = 'z...a';
    private const EXTRACT_PREFIX = '...';
    private const EXCLUDE_PREFIX = '!';

    /** @var array<string, array{format:string, data:array<string, mixed>, slugs:list<string>, path:string}> */
    private array $files = [];
    /** @var array<string, list<string>> folder path → direct children (files and subfolders) */
    private array $dirs = [];
    /** @var array<string, string> `path.format` → actual file path */
    private array $flatten = [];
    /** @var array<string, Node> */
    private array $pathToNode = [];
    /** @var list<string> */
    private array $baseSlugs;

    private Node $root;
    /** @var list<array{url:string, slugs:list<string>, file:string, data:array<string,mixed>}> */
    private array $pages = [];

    /**
     * @param bool $includeDrafts Pages whose file name starts with `_` are drafts and
     *        examples. They are left out of the tree unless the generator runs with `--dev`.
     * @param list<string> $extensions Page file extensions without dot (`content.extensions`).
     *        A `.md` or `.mdx` file whose extension is not listed is a content error.
     */
    public function __construct(
        private string $contentDir,
        private string $baseUrl = '/',
        private bool $includeDrafts = false,
        private array $extensions = ['md'],
    ) {
        $this->contentDir = rtrim($contentDir, '/');
        $this->extensions = array_values(array_map('strtolower', $extensions));
        $this->baseSlugs = array_values(array_filter(explode('/', $baseUrl), static fn(string $s): bool => $s !== ''));

        $this->scan('');
        $this->assignSlugs();
        $this->build();
    }

    // ---------------------------------------------------------------- Reading

    /** Read the content directory recursively as a virtual file system. */
    private function scan(string $dir): void
    {
        $absolute = $this->contentDir . ($dir === '' ? '' : '/' . $dir);
        $entries = @scandir($absolute);
        if ($entries === false) {
            throw new IoException('directory is not readable', $absolute);
        }
        sort($entries, SORT_STRING);

        $this->dirs[$dir] ??= [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $path = $dir === '' ? $entry : $dir . '/' . $entry;
            if (is_dir($absolute . '/' . $entry)) {
                $this->dirs[$dir][] = $path;
                $this->scan($path);
                continue;
            }

            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (in_array($extension, $this->extensions, true)) {
                if (str_starts_with($entry, '_') && !$this->includeDrafts) {
                    continue;
                }
                $this->dirs[$dir][] = $path;
                $this->files[$path] = [
                    'format' => 'page',
                    'data' => Frontmatter::read($absolute . '/' . $entry),
                    'slugs' => [],
                    'path' => $path,
                ];
                continue;
            }
            if ($extension === 'md' || $extension === 'mdx') {
                throw new ContentException(
                    'page extension ".' . $extension . '" is not enabled; add "' . $extension . '" to content.extensions',
                    $absolute . '/' . $entry,
                );
            }
            if ($extension === 'json') {
                $raw = @file_get_contents($absolute . '/' . $entry);
                if ($raw === false) {
                    throw new IoException('file is not readable', $absolute . '/' . $entry);
                }
                $data = json_decode($raw, true);
                if (!is_array($data)) {
                    throw new ContentException('expected a JSON object', $absolute . '/' . $entry);
                }
                $this->dirs[$dir][] = $path;
                $this->files[$path] = [
                    'format' => 'meta',
                    'data' => $data,
                    'slugs' => [],
                    'path' => $path,
                ];
            }
        }

        foreach ($this->files as $path => $file) {
            $this->flatten[self::withoutExtension($path) . '.' . $file['format']] = $path;
        }
    }

    /**
     * Assign slugs like `slugsPlugin`: first every non-index page, then the
     * `index` files (with `index` appended when the slug is taken).
     */
    private function assignSlugs(): void
    {
        $taken = [];
        $indexFiles = [];

        foreach ($this->files as $path => $file) {
            if ($file['format'] !== 'page') {
                continue;
            }
            if (basename(self::withoutExtension($path)) === 'index') {
                $indexFiles[] = $path;
                continue;
            }
            $slugs = self::getSlugs($path);
            $this->files[$path]['slugs'] = $slugs;
            $key = implode('/', $slugs);
            if (isset($taken[$key])) {
                throw new ContentException('duplicate slug: ' . $key, $this->contentDir . '/' . $path);
            }
            $taken[$key] = true;
        }

        foreach ($indexFiles as $path) {
            $slugs = self::getSlugs($path);
            if (isset($taken[implode('/', $slugs)])) {
                $slugs[] = 'index';
            }
            $key = implode('/', $slugs);
            if (isset($taken[$key])) {
                throw new ContentException('duplicate slug: ' . $key, $this->contentDir . '/' . $path);
            }
            $taken[$key] = true;
            $this->files[$path]['slugs'] = $slugs;
        }

        foreach ($this->files as $path => $file) {
            if ($file['format'] !== 'page') {
                continue;
            }
            $this->pages[] = [
                'url' => $this->url($this->files[$path]['slugs']),
                'slugs' => $this->files[$path]['slugs'],
                'file' => $path,
                'data' => $file['data'],
            ];
        }

        usort($this->pages, static fn(array $a, array $b): int => strcmp($a['file'], $b['file']));
    }

    /** URL slugs of a content file: `index` is dropped, folder groups `(name)` are skipped. */
    private static function getSlugs(string $file): array
    {
        $dir = dirname($file);
        $name = basename(self::withoutExtension($file));
        $slugs = [];

        if ($dir !== '.' && $dir !== '') {
            foreach (explode('/', $dir) as $segment) {
                if ($segment !== '' && preg_match(self::GROUP, $segment) !== 1) {
                    $slugs[] = self::encodeUri($segment);
                }
            }
        }
        if (preg_match(self::GROUP, $name) === 1) {
            throw new ContentException('a folder group "(name)" is not allowed as a file name: ' . $file);
        }
        if ($name !== 'index') {
            $slugs[] = self::encodeUri($name);
        }

        return $slugs;
    }

    /** `createGetUrl(baseUrl)`. */
    private function url(array $slugs): string
    {
        $parts = array_filter([...$this->baseSlugs, ...$slugs], static fn(string $s): bool => $s !== '');

        return '/' . implode('/', $parts);
    }

    // ------------------------------------------------------------------- Tree

    private function build(): void
    {
        $folder = $this->buildFolder('', true);

        $root = new Node(Node::FOLDER);
        $root->type = 'root';
        $root->id = 'root';
        $root->ref = $folder?->ref;
        $root->name = ($folder?->name ?? '') !== '' ? $folder->name : 'Docs';
        $root->description = $folder?->description;
        $root->children = $folder ? $folder->children : [];

        $this->root = $root;
    }

    private function buildFolder(string $folderPath, bool $isGlobalRoot = false): ?Node
    {
        if (isset($this->pathToNode[$folderPath])) {
            return $this->pathToNode[$folderPath];
        }
        if (!isset($this->dirs[$folderPath])) {
            return null;
        }
        $files = $this->dirs[$folderPath];

        $metaPath = $this->resolveFlattenPath(self::joinPath($folderPath, 'meta'), 'meta');
        $meta = $this->files[$metaPath] ?? null;
        if ($meta === null || $meta['format'] !== 'meta') {
            $meta = null;
            $metaPath = null;
        }
        $metadata = $meta['data'] ?? [];
        $isRoot = $metadata['root'] ?? $isGlobalRoot;

        $node = new Node(Node::FOLDER);
        $node->name = null;
        $node->root = isset($metadata['root']) ? (bool) $metadata['root'] : null;
        $node->defaultOpen = isset($metadata['defaultOpen']) ? (bool) $metadata['defaultOpen'] : null;
        $node->description = isset($metadata['description']) ? (string) $metadata['description'] : null;
        $node->collapsible = isset($metadata['collapsible']) ? (bool) $metadata['collapsible'] : null;
        $node->id = $folderPath;
        $node->ref = $folderPath;
        $node->refMeta = $metaPath;
        $node->unfinished = true;
        $this->pathToNode[$folderPath] = $node;

        $indexPath = null;
        if (isset($metadata['pagesIndex'])) {
            $resolved = $this->resolveFlattenPath(self::joinPath($folderPath, (string) $metadata['pagesIndex']), 'page');
            $page = $this->buildFile($resolved);
            if ($page !== null && $this->own($folderPath, $page, 3)) {
                $indexPath = $resolved;
                $node->index = $page;
            } else {
                $node->index = $this->resolveLink((string) $metadata['pagesIndex']);
            }
        } elseif (!$isRoot) {
            $defaultPath = $this->resolveFlattenPath(self::joinPath($folderPath, 'index'), 'page');
            $page = $this->buildFile($defaultPath);
            if ($page !== null && $this->own($folderPath, $page, 0)) {
                $indexPath = $defaultPath;
                $node->index = $page;
            }
        }

        if (isset($metadata['pages']) && is_array($metadata['pages'])) {
            $output = [];
            $excluded = [];
            foreach ($metadata['pages'] as $item) {
                $this->resolveFolderItem($folderPath, (string) $item, $output, $excluded);
            }
            if ($indexPath !== null) {
                if (isset($excluded[$indexPath])) {
                    $node->index = null;
                } else {
                    $excluded[$indexPath] = true;
                }
            }
            foreach ($output as $item) {
                if ($item !== self::REST && $item !== self::REST_REVERSED) {
                    $node->children[] = $item;
                    continue;
                }
                $resolved = $this->buildPaths(
                    $files,
                    static fn(string $file): bool => !isset($excluded[$file]),
                    $item === self::REST_REVERSED,
                );
                foreach ($resolved as $child) {
                    if ($this->own($folderPath, $child, 0)) {
                        $node->children[] = $child;
                    }
                }
            }
        } else {
            $filter = $indexPath === null ? null : static fn(string $file): bool => $file !== $indexPath;
            foreach ($this->buildPaths($files, $filter) as $item) {
                if ($this->own($folderPath, $item, 0)) {
                    $node->children[] = $item;
                }
            }
        }

        $node->icon = $metadata['icon'] ?? $node->index?->icon;
        $node->name = isset($metadata['title']) ? (string) $metadata['title'] : $node->index?->name;
        $node->sortName = isset($metadata['title']) ? (string) $metadata['title'] : $node->index?->sortName;
        if (($node->name ?? '') === '') {
            $folderName = basename($folderPath);
            $node->name = self::pathToName(
                preg_match(self::GROUP, $folderName, $m) === 1 ? $m['name'] : $folderName,
            );
        }

        $this->pathToNode[$folderPath] = $node;
        $node->unfinished = false;

        return $node;
    }

    private function buildFile(string $path): ?Node
    {
        if (isset($this->pathToNode[$path])) {
            return $this->pathToNode[$path];
        }
        $page = $this->files[$path] ?? null;
        if ($page === null || $page['format'] !== 'page') {
            return null;
        }

        $title = isset($page['data']['title']) ? (string) $page['data']['title'] : null;
        $node = new Node(Node::PAGE);
        $node->id = $path;
        $node->name = $title ?? self::pathToName(basename(self::withoutExtension($path)));
        $node->description = isset($page['data']['description']) ? (string) $page['data']['description'] : null;
        $node->icon = isset($page['data']['icon']) ? (string) $page['data']['icon'] : null;
        $node->url = $this->url($page['slugs']);
        $node->slugs = $page['slugs'];
        $node->ref = $path;
        $node->sortName = $title;

        $this->pathToNode[$path] = $node;

        return $node;
    }

    /**
     * `buildPaths`: sort the remaining files and folders of a directory.
     * The index page always comes first, folders after pages.
     */
    private function buildPaths(array $paths, ?callable $filter = null, bool $reversed = false): array
    {
        $nodes = [];
        $indexNode = null;

        foreach ($paths as $path) {
            if ($filter !== null && !$filter($path)) {
                continue;
            }
            $fileNode = $this->buildFile($path);
            if ($fileNode !== null) {
                $nodes[] = $fileNode;
                if ($indexNode === null && basename(self::withoutExtension($path)) === 'index') {
                    $indexNode = $fileNode;
                }
                continue;
            }
            $dirNode = $this->buildFolder($path);
            if ($dirNode !== null) {
                $nodes[] = $dirNode;
            }
        }

        $factor = $reversed ? -1 : 1;
        usort($nodes, static function (Node $a, Node $b) use ($indexNode, $factor): int {
            if ($a === $indexNode) {
                return -100;
            }
            if ($b === $indexNode) {
                return 100;
            }
            $aText = (string) $a->ref;
            $bText = (string) $b->ref;
            $aKind = $a->type === Node::FOLDER ? 10 : 0;
            $bKind = $b->type === Node::FOLDER ? 10 : 0;

            return $factor * (self::localeCompare($aText, $bText) + ($aKind - $bKind));
        });

        return $nodes;
    }

    private function resolveFolderItem(string $folderPath, string $item, array &$output, array &$excluded): void
    {
        if ($item === self::REST || $item === self::REST_REVERSED) {
            $output[] = $item;
            return;
        }

        $separator = $this->resolveSeparator($item);
        if ($separator !== null) {
            $output[] = $separator;
            return;
        }

        $link = $this->resolveLink($item);
        if ($link !== null) {
            $output[] = $link;
            return;
        }

        if (str_starts_with($item, self::EXCLUDE_PREFIX)) {
            $path = self::joinPath($folderPath, substr($item, 1));
            $excluded[$path] = true;
            $excluded[$this->resolveFlattenPath($path, 'page')] = true;
            return;
        }

        if (str_starts_with($item, self::EXTRACT_PREFIX)) {
            $path = self::joinPath($folderPath, substr($item, 3));
            $node = $this->buildFolder($path);
            if ($node === null) {
                return;
            }
            $children = $node->index !== null ? [$node->index, ...$node->children] : $node->children;
            if ($this->own($folderPath, $node, 2)) {
                foreach ($children as $child) {
                    $this->transferOwner($folderPath, $child);
                    $output[] = $child;
                }
                $excluded[$path] = true;
            } else {
                foreach ($children as $child) {
                    if ($this->own($folderPath, $child, 2)) {
                        $output[] = $child;
                    }
                }
            }
            return;
        }

        $path = self::joinPath($folderPath, $item);
        $node = $this->buildFolder($path);
        if ($node === null) {
            $path = $this->resolveFlattenPath($path, 'page');
            $node = $this->buildFile($path);
        }
        if ($node === null || !$this->own($folderPath, $node, 2)) {
            return;
        }
        $output[] = $node;
        $excluded[$path] = true;
    }

    private function resolveLink(string $item): ?Node
    {
        if (preg_match(self::LINK, $item, $m) !== 1) {
            return null;
        }
        $node = new Node(Node::PAGE);
        $node->id = $this->generateId();
        $node->icon = ($m['icon'] ?? '') !== '' ? $m['icon'] : null;
        $node->name = $m['name'];
        $node->url = $m['url'];
        $node->external = ($m['external'] ?? '') !== '' ? true : null;

        return $node;
    }

    private function resolveSeparator(string $item): ?Node
    {
        if (preg_match(self::SEPARATOR, $item, $m) !== 1) {
            return null;
        }
        $node = new Node(Node::SEPARATOR);
        $node->id = $this->generateId();
        $node->icon = ($m['icon'] ?? '') !== '' ? $m['icon'] : null;
        $node->name = $m['name'] ?? null;

        return $node;
    }

    /**
     * `own`: a node belongs to exactly one folder. The owner with the higher
     * priority wins and the previous owner loses the child.
     */
    private function own(string $ownerPath, Node $node, int $priority): bool
    {
        if ($node->unfinished) {
            return false;
        }
        if ($node->owner === null) {
            $node->owner = ['owner' => $ownerPath, 'priority' => $priority];
            return true;
        }
        if ($node->owner['owner'] === $ownerPath) {
            $node->owner['priority'] = max($node->owner['priority'], $priority);
            return true;
        }
        if ($node->owner['priority'] >= $priority) {
            return false;
        }

        $folder = $this->pathToNode[$node->owner['owner']] ?? null;
        if ($folder !== null && $folder->type === Node::FOLDER) {
            if ($folder->index === $node) {
                $folder->index = null;
            } else {
                foreach ($folder->children as $i => $child) {
                    if ($child === $node) {
                        array_splice($folder->children, $i, 1);
                        break;
                    }
                }
            }
        }
        $node->owner = ['owner' => $ownerPath, 'priority' => $priority];

        return true;
    }

    private function transferOwner(string $ownerPath, Node $node): void
    {
        if ($node->owner !== null) {
            $node->owner['owner'] = $ownerPath;
        }
    }

    private int $nextId = 0;

    private function generateId(): string
    {
        return '_' . $this->nextId++;
    }

    private function resolveFlattenPath(string $name, string $format): string
    {
        return $this->flatten[$name . '.' . $format] ?? $name;
    }

    // ------------------------------------------------------------------ Queries

    public function root(): Node
    {
        return $this->root;
    }

    /** @return list<array{url:string, slugs:list<string>, file:string, data:array<string,mixed>}> */
    public function pages(): array
    {
        return $this->pages;
    }

    /** The tree as a plain array, for inspection (tools/dump-tree.php) and tests. */
    public function toArray(): array
    {
        return array_map([self::class, 'nodeToArray'], $this->root->children);
    }

    private static function nodeToArray(Node $node): array
    {
        if ($node->type === Node::FOLDER) {
            return [
                'folder' => (string) $node->name,
                'root' => $node->root ?? false,
                'index' => $node->index?->url,
                'children' => array_map([self::class, 'nodeToArray'], $node->children),
            ];
        }
        if ($node->type === Node::SEPARATOR) {
            return ['sep' => (string) $node->name];
        }

        return ['page' => (string) $node->url];
    }

    /**
     * `searchPath`: the path from the root to the node with this URL,
     * including the preceding separator (`findPath`, `includeSeparator = true`).
     *
     * @return list<Node>
     */
    public function pathTo(string $url): array
    {
        return self::findPath($this->root->children, self::normalizeUrl($url)) ?? [];
    }

    /** @return list<Node>|null */
    private static function findPath(array $nodes, string $url): ?array
    {
        $separator = null;
        foreach ($nodes as $node) {
            if ($node->type === Node::PAGE && $node->url === $url) {
                return $separator !== null ? [$separator, $node] : [$node];
            }
            if ($node->type === Node::SEPARATOR) {
                $separator = $node;
                continue;
            }
            if ($node->type === Node::FOLDER) {
                $items = $node->index !== null && $node->index->url === $url
                    ? [$node->index]
                    : self::findPath($node->children, $url);
                if ($items !== null) {
                    array_unshift($items, $node);
                    if ($separator !== null) {
                        array_unshift($items, $separator);
                    }
                    return $items;
                }
            }
        }

        return null;
    }

    /** The `root: true` folder on the path, otherwise the tree itself (`TreeContextProvider`). */
    public function rootFor(string $url): Node
    {
        $found = null;
        foreach ($this->pathTo($url) as $node) {
            if ($node->type === Node::FOLDER && $node->root === true) {
                $found = $node;
            }
        }

        return $found ?? $this->root;
    }

    /**
     * `getBreadcrumbItemsFromPath` with the defaults of the notebook layout
     * (`includeRoot`, `includePage` and `includeSeparator` are all off).
     *
     * @return list<array{name:string, url:?string}>
     */
    public function breadcrumb(string $url, array $options = []): array
    {
        $includePage = $options['includePage'] ?? false;
        $includeSeparator = $options['includeSeparator'] ?? false;
        $includeRoot = $options['includeRoot'] ?? false;
        $path = $this->pathTo($url);
        $tree = $this->rootFor($url);

        $items = [];
        for ($i = 0; $i < count($path); $i++) {
            $item = $path[$i];
            if ($item->type === Node::PAGE) {
                if ($includePage) {
                    $items[] = ['name' => (string) $item->name, 'url' => $item->url];
                }
                continue;
            }
            if ($item->type === Node::FOLDER) {
                if ($item->root === true) {
                    $items = [];
                    if ($includeRoot !== false) {
                        $items[] = [
                            'name' => (string) $tree->name,
                            'url' => is_array($includeRoot) ? $includeRoot['url'] : $item->index?->url,
                        ];
                    }
                    continue;
                }
                if ($i === count($path) - 1 || $item->index !== ($path[$i + 1] ?? null)) {
                    $items[] = ['name' => (string) $item->name, 'url' => $item->index?->url];
                }
                continue;
            }
            if ($item->name !== null && $includeSeparator) {
                $items[] = ['name' => $item->name, 'url' => null];
            }
        }

        return $items;
    }

    /**
     * Flat page list of the current root folder (`useFooterItems`).
     *
     * @return list<Node>
     */
    public function footerList(string $url): array
    {
        $list = [];
        $walk = static function (Node $node) use (&$walk, &$list): void {
            if ($node->type === Node::FOLDER) {
                if ($node->index !== null) {
                    $walk($node->index);
                }
                foreach ($node->children as $child) {
                    $walk($child);
                }
                return;
            }
            if ($node->type === Node::PAGE && $node->external !== true) {
                $list[] = $node;
            }
        };
        foreach ($this->rootFor($url)->children as $child) {
            $walk($child);
        }

        return $list;
    }

    /**
     * Previous and next card of the footer navigation (`Footer` in the notebook layout).
     *
     * @return array{previous?:array{name:string, url:string, description:?string}, next?:array{name:string, url:string, description:?string}}
     */
    public function footerItems(string $url): array
    {
        $list = $this->footerList($url);
        $index = -1;
        foreach ($list as $i => $item) {
            if (self::isActive((string) $item->url, $url)) {
                $index = $i;
                break;
            }
        }
        if ($index === -1) {
            return [];
        }

        $out = [];
        if (isset($list[$index - 1])) {
            $out['previous'] = self::footerItem($list[$index - 1]);
        }
        if (isset($list[$index + 1])) {
            $out['next'] = self::footerItem($list[$index + 1]);
        }

        return $out;
    }

    private static function footerItem(Node $node): array
    {
        return ['name' => (string) $node->name, 'url' => (string) $node->url, 'description' => $node->description];
    }

    /**
     * `getLayoutTabs`: one tab per `root: true` folder, pointing at its index page
     * or the first page below it.
     *
     * @return list<array{title:string, url:string, description:?string, icon:?string, folder:Node}>
     */
    public function layoutTabs(): array
    {
        $results = [];
        $next = static function (Node $node) use (&$next, &$results): void {
            if ($node->root === true) {
                $url = $node->index?->url;
                if ($url === null) {
                    foreach ($node->children as $child) {
                        if ($child->type === Node::PAGE) {
                            $url = $child->url;
                            break;
                        }
                    }
                }
                if ($url !== null) {
                    $results[] = [
                        'title' => (string) $node->name,
                        'url' => $url,
                        'description' => $node->description,
                        'icon' => $node->icon,
                        'folder' => $node,
                    ];
                }
            }
            foreach ($node->children as $child) {
                if ($child->type === Node::FOLDER) {
                    $next($child);
                }
            }
        };
        $next($this->root);

        return $results;
    }

    /**
     * `useTabsGroups` + `collectTabs`: the tab groups for the current page.
     * The header shows the last group; `selected` is the last matching tab
     * (`isLayoutTabActive`: the tab's folder is on the path).
     *
     * @return array{groups:list<array{active:Node, options:list<array{title:string, url:string}>}>, tabs:list<array{title:string, url:string}>, selected:int}
     */
    public function tabsFor(string $url): array
    {
        $tabs = $this->layoutTabs();
        $path = $this->pathTo($url);
        $last = $path === [] ? null : $path[count($path) - 1];
        $page = $last !== null && $last->type === Node::PAGE ? $last : null;

        $groups = [];
        $scope = $this->root;
        foreach ($path as $node) {
            if ($node->type !== Node::FOLDER || $node->root !== true) {
                continue;
            }
            $options = [];
            $this->collectTabs($scope, $node, $page, $tabs, $options);
            if ($options !== []) {
                $groups[] = ['active' => $node, 'options' => $options];
            }
            $scope = $node;
        }

        $headerTabs = $groups === [] ? [] : $groups[count($groups) - 1]['options'];
        $selected = -1;
        foreach ($headerTabs as $i => $option) {
            foreach ($path as $node) {
                if ($node->type === Node::FOLDER && $node === $option['folder']) {
                    $selected = $i;
                }
            }
        }

        return ['groups' => $groups, 'tabs' => $headerTabs, 'selected' => $selected];
    }

    private function collectTabs(Node $scope, Node $active, ?Node $page, array $tabs, array &$out): void
    {
        foreach ($scope->children as $node) {
            if ($node->type !== Node::FOLDER) {
                continue;
            }
            if ($node->root === $active->root) {
                $tab = null;
                foreach ($tabs as $candidate) {
                    if ($candidate['folder'] === $node || $candidate['folder']->id === $node->id) {
                        $tab = $candidate;
                        break;
                    }
                }
                if ($tab === null) {
                    continue;
                }
                $projection = $page !== null ? self::findProjection($active, $node, $page) : null;
                if ($projection !== null) {
                    $tab['url'] = (string) $projection->url;
                }
                $out[] = $tab;
            } elseif ($node->root !== true) {
                $this->collectTabs($node, $active, $page, $tabs, $out);
            }
        }
    }

    /** `findProjection`: the same file relative to the root folder, inside another root folder. */
    private static function findProjection(Node $from, Node $to, Node $page): ?Node
    {
        if ($from->ref === null || $to->ref === null || $page->ref === null) {
            return null;
        }
        $prefix = $from->ref . '/';
        if (!str_starts_with($page->ref, $prefix)) {
            return null;
        }
        $target = $to->ref . '/' . substr($page->ref, strlen($prefix));

        $found = null;
        $visit = static function (Node $node) use (&$visit, $target, &$found): void {
            if ($found !== null) {
                return;
            }
            if ($node->type === Node::PAGE && $node->ref === $target) {
                $found = $node;
                return;
            }
            if ($node->index !== null) {
                $visit($node->index);
            }
            foreach ($node->children as $child) {
                $visit($child);
            }
        };
        $visit($to);

        return $found;
    }

    /**
     * Sidebar state for a page: only the children of the root folder are
     * visible (`SidebarPageTree` renders `root.children`).
     *
     * Folders are open when they are on the path, `defaultOpen` is set or
     * `defaultOpenLevel >= depth` holds (`defaultOpenLevel = 0`, so never).
     * Entries are active through `isActive(url, pathname)` without `nested`.
     *
     * @return list<array<string, mixed>>
     */
    public function sidebarState(string $url, int $defaultOpenLevel = 0): array
    {
        $path = $this->pathTo($url);

        $walk = function (array $nodes, int $depth) use (&$walk, $path, $url, $defaultOpenLevel): array {
            $out = [];
            foreach ($nodes as $node) {
                if ($node->type === Node::SEPARATOR) {
                    $out[] = ['type' => 'separator', 'name' => (string) $node->name, 'depth' => $depth];
                    continue;
                }
                if ($node->type === Node::PAGE) {
                    $out[] = [
                        'type' => 'page',
                        'name' => (string) $node->name,
                        'url' => (string) $node->url,
                        'external' => $node->external === true,
                        'active' => self::isActive((string) $node->url, $url),
                        'depth' => $depth,
                    ];
                    continue;
                }

                $active = in_array($node, $path, true);
                $collapsible = $node->collapsible !== false;
                $open = !$collapsible || $active || ($node->defaultOpen ?? ($defaultOpenLevel >= $depth + 1));
                $out[] = [
                    'type' => 'folder',
                    'name' => (string) $node->name,
                    'depth' => $depth,
                    'active' => $active,
                    'open' => $open,
                    'index' => $node->index?->url,
                    'indexActive' => $node->index !== null && self::isActive((string) $node->index->url, $url),
                    'children' => $walk($node->children, $depth + 1),
                ];
            }

            return $out;
        };

        return $walk($this->rootFor($url)->children, 0);
    }

    // ------------------------------------------------------------------ Helpers

    /** Whether a link to `$href` is current on `$pathname`; `$nested` also matches pages below it. */
    public static function isActive(string $href, string $pathname, bool $nested = false): bool
    {
        $href = self::normalize($href);
        $pathname = self::normalize($pathname);

        return $href === $pathname || ($nested && str_starts_with($pathname, $href . '/'));
    }

    private static function normalize(string $url): string
    {
        return strlen($url) > 1 && str_ends_with($url, '/') ? substr($url, 0, -1) : $url;
    }

    private static function normalizeUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        if (!str_starts_with($url, '/')) {
            $url = '/' . $url;
        }

        return self::normalize($url);
    }

    private static function joinPath(string $a, string $b): string
    {
        $parts = [];
        foreach (explode('/', $a . '/' . $b) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    private static function withoutExtension(string $path): string
    {
        $dot = strrpos(basename($path), '.');

        return $dot === false ? $path : substr($path, 0, strlen($path) - (strlen(basename($path)) - $dot));
    }

    /** `pathToName`: first letter upper-case, `-` becomes a space. */
    private static function pathToName(string $name): string
    {
        $result = '';
        foreach (preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $i => $char) {
            if ($i === 0) {
                $result .= mb_strtoupper($char, 'UTF-8');
            } elseif ($char === '-') {
                $result .= ' ';
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    /** `String.prototype.localeCompare`: exact with `intl`, binary otherwise. */
    private static function localeCompare(string $a, string $b): int
    {
        static $collator = null;
        if ($collator === null) {
            $collator = class_exists(\Collator::class) ? new \Collator('') : false;
        }
        if ($collator instanceof \Collator) {
            $result = $collator->compare($a, $b);
            return $result === false ? 0 : $result;
        }

        return $a <=> $b;
    }

    /** `encodeURI` for a path segment. */
    private static function encodeUri(string $segment): string
    {
        $encoded = rawurlencode($segment);
        $keep = ['%21' => '!', '%23' => '#', '%24' => '$', '%26' => '&', '%27' => "'", '%28' => '(', '%29' => ')',
            '%2A' => '*', '%2B' => '+', '%2C' => ',', '%2F' => '/', '%3A' => ':', '%3B' => ';', '%3D' => '=',
            '%3F' => '?', '%40' => '@', '%7E' => '~'];

        return strtr($encoded, $keep);
    }
}
