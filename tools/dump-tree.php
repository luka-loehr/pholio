<?php

declare(strict_types=1);

/**
 * Inspection tool: the page tree as JSON; with a URL argument also the
 * breadcrumb, footer navigation, tabs and sidebar state of that page.
 *
 * Usage:
 *   php tools/dump-tree.php
 *   php tools/dump-tree.php /guide/installation
 *   PHOLIO_CONTENT=<dir> PHOLIO_BASE_URL=/docs PHOLIO_EXTENSIONS=md,mdx php tools/dump-tree.php
 *
 * Defaults: PHOLIO_CONTENT is examples/demo/content, PHOLIO_BASE_URL is "/",
 * PHOLIO_EXTENSIONS is "md". PHOLIO_DRAFTS=1 includes `_` pages.
 */

require_once __DIR__ . '/../src/lib/Tree.php';

use Pholio\Tree;

$content = getenv('PHOLIO_CONTENT') ?: dirname(__DIR__) . '/examples/demo/content';
$baseUrl = getenv('PHOLIO_BASE_URL') ?: '/';
$extensions = array_values(array_filter(array_map('trim', explode(',', getenv('PHOLIO_EXTENSIONS') ?: 'md')), 'strlen'));
$drafts = getenv('PHOLIO_DRAFTS') === '1';

if (!is_dir($content)) {
    fwrite(STDERR, 'content directory not found: ' . $content . PHP_EOL);
    exit(2);
}

try {
    $tree = new Tree($content, $baseUrl, $drafts, $extensions);
} catch (Pholio\Exception $e) {
    fwrite(STDERR, 'pholio: ' . $e->describe() . PHP_EOL);
    exit($e->exitCode());
}
$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

$url = $argv[1] ?? null;
if ($url === null) {
    echo json_encode($tree->toArray(), $flags), PHP_EOL;
    exit(0);
}

$path = array_map(
    static fn($node): string => $node->type . ':' . ($node->name ?? '') . ($node->url !== null ? ' ' . $node->url : ''),
    $tree->pathTo($url),
);

echo json_encode([
    'url' => $url,
    'path' => $path,
    'root' => $tree->rootFor($url)->name,
    'breadcrumb' => $tree->breadcrumb($url),
    'footer' => $tree->footerItems($url),
    'tabs' => array_map(
        static fn(array $tab): array => ['title' => $tab['title'], 'url' => $tab['url']],
        $tree->tabsFor($url)['tabs'],
    ),
    'selected_tab' => $tree->tabsFor($url)['selected'],
    'sidebar' => $tree->sidebarState($url),
], $flags), PHP_EOL;
