<?php

declare(strict_types=1);

/**
 * Builds `search-index.json` from a content directory, without a full site build.
 *
 * Usage:
 *   php verify/tools/build-search-index.php --content <dir> --base-url <url> [options] <out.json>
 *
 * Options:
 *   --content <dir>       content directory (required)
 *   --base-url <url>      docs root URL the page URLs start with (required), e.g. "/" or "/docs"
 *   --tokenizer <name>    "english" (default) or "german"
 *   --extensions <list>   comma-separated page file extensions without dot, default "md"
 *   --frontmatter-alias <from=to>
 *                         map a frontmatter key onto an allowed one (content.frontmatter_aliases),
 *                         repeatable, e.g. --frontmatter-alias date=updated
 *   --order <file.json>   JSON list of content files (relative to --content) in insertion order.
 *                         It decides which hit comes first on equal scores; a reference export
 *                         records its bundler's order, which the file system can't reproduce.
 *   --drafts              include pages whose file name starts with "_"
 *   --texts <file.json>   also write the indexed pages with their plain text (SearchIndex::documents),
 *                         which verify/search-parity.mjs rebuilds the postings from
 *
 * `verify/search-parity.mjs` calls this tool; the index is identical to the one `pholio build`
 * writes for the same content, base URL and tokenizer.
 *
 * Exit codes: 0 written, 1 input error, 2 usage error.
 */

require_once __DIR__ . '/../../src/lib/SearchIndex.php';

use Pholio\Document;
use Pholio\Markdown;
use Pholio\SearchIndex;
use Pholio\Tree;

function usage(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL
        . 'Usage: php build-search-index.php --content <dir> --base-url <url> [--tokenizer english|german]'
        . ' [--extensions md,mdx] [--frontmatter-alias <from=to>]... [--order <file.json>] [--drafts] [--texts <file.json>] <out.json>' . PHP_EOL);
    exit(2);
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$args = array_slice($argv ?? [], 1);
$options = ['content' => null, 'base-url' => null, 'tokenizer' => 'english', 'extensions' => 'md', 'order' => null, 'texts' => null];
$drafts = false;
$aliases = [];
$positional = [];

for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if ($arg === '--drafts') {
        $drafts = true;
        continue;
    }
    if ($arg === '--frontmatter-alias') {
        $pair = explode('=', (string) ($args[++$i] ?? ''), 2);
        if (count($pair) !== 2 || $pair[0] === '' || $pair[1] === '') {
            usage('--frontmatter-alias expects <from=to>');
        }
        $aliases[$pair[0]] = $pair[1];
        continue;
    }
    if (str_starts_with($arg, '--')) {
        $name = substr($arg, 2);
        if (!array_key_exists($name, $options)) {
            usage('Unknown option: ' . $arg);
        }
        if (!isset($args[$i + 1])) {
            usage('Missing value for ' . $arg);
        }
        $options[$name] = $args[++$i];
        continue;
    }
    $positional[] = $arg;
}

if (count($positional) !== 1) {
    usage('Expected exactly one output file');
}
if ($options['content'] === null || $options['base-url'] === null) {
    usage('Missing: ' . ($options['content'] === null ? '--content' : '--base-url'));
}
if (!in_array($options['tokenizer'], SearchIndex::TOKENIZERS, true)) {
    usage('--tokenizer must be one of ' . implode(', ', SearchIndex::TOKENIZERS) . ', got: ' . $options['tokenizer']);
}

$extensions = array_values(array_filter(array_map('trim', explode(',', (string) $options['extensions'])), 'strlen'));
if ($extensions === []) {
    usage('--extensions needs at least one extension');
}

$content = rtrim((string) $options['content'], '/');
if (!is_dir($content)) {
    fail('Content directory not found: ' . $content);
}
$baseUrl = (string) $options['base-url'];

$order = null;
if ($options['order'] !== null) {
    if (!is_file($options['order'])) {
        fail('Order file not found: ' . $options['order']);
    }
    $order = json_decode((string) file_get_contents($options['order']), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($order) || !array_is_list($order)) {
        fail('Order file must contain a JSON list of file names: ' . $options['order']);
    }
}

$tree = new Tree($content, $baseUrl, $drafts, $extensions);

$load = static function (array $page) use ($content, $aliases): Document {
    $path = $content . '/' . $page['file'];

    return Markdown::parse((string) file_get_contents($path), $path, $aliases);
};
$index = SearchIndex::build($tree, $load, $baseUrl, $order, $drafts, $options['tokenizer']);

if ($options['texts'] !== null) {
    $texts = json_encode(SearchIndex::documents($tree, $load, $baseUrl, $order, $drafts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($options['texts'], $texts . "\n") === false) {
        fail('Cannot write: ' . $options['texts']);
    }
}

$json = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (file_put_contents($positional[0], $json . "\n") === false) {
    fail('Cannot write: ' . $positional[0]);
}

fwrite(
    STDERR,
    sprintf("%d pages, %d words, %d bytes\n", count($index['pages']), count($index['postings']), strlen($json)),
);
