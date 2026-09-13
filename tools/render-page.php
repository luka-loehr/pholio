<?php

declare(strict_types=1);

// Spot-check tool: php tools/render-page.php <file.md>
// Prints the article body (the innerHTML of div.prose) to standard output.
//
// Environment:
//   PHOLIO_BASE          docs root URL that rewritten links point to (default /docs)
//   PHOLIO_LINK_PREFIX   link prefix rewritten to PHOLIO_BASE (default: no link rewrite)
//   PHOLIO_ASSET_PREFIX  image path prefix to rewrite (default: no image rewrite)
//   PHOLIO_ASSET_TARGET  URL the image prefix maps to (default PHOLIO_BASE)
//   PHOLIO_LANGUAGE      UI language of labels such as the heading anchor (default en)

require_once dirname(__DIR__) . '/src/lib/Render.php';

use Pholio\ContentException;
use Pholio\I18n;
use Pholio\Markdown;
use Pholio\Render;
use Pholio\RenderContext;

$argvList = $argv ?? [];
if (count($argvList) !== 2) {
    fwrite(STDERR, 'usage: php ' . ($argvList[0] ?? 'render-page.php') . " <file.md>\n");
    exit(2);
}

$path = $argvList[1];
if (!is_file($path)) {
    fwrite(STDERR, "file not found: {$path}\n");
    exit(2);
}

$env = static fn (string $name): ?string => getenv($name) === false || getenv($name) === '' ? null : (string) getenv($name);

I18n::use($env('PHOLIO_LANGUAGE') ?? I18n::DEFAULT_LANGUAGE);

try {
    $document = Markdown::parse((string) file_get_contents($path), $path);
    $context = RenderContext::create(
        baseUrl: $env('PHOLIO_BASE') ?? '/docs',
        assetPrefix: $env('PHOLIO_ASSET_PREFIX'),
        assetTarget: $env('PHOLIO_ASSET_TARGET'),
        linkPrefix: $env('PHOLIO_LINK_PREFIX'),
    );
    echo Render::body($document, $context), "\n";
} catch (ContentException $e) {
    fwrite(STDERR, $e->describe() . "\n");
    exit(3);
}
