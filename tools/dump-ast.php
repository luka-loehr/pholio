<?php

declare(strict_types=1);

// Tool for spot checks and AST snapshots.
//
//   php tools/dump-ast.php [--alias from=to ...] <file.md|.mdx>
//       Prints the AST of a source file as readable JSON (frontmatter, blocks, headings).
//
//   php tools/dump-ast.php [--alias from=to ...] --snapshot <source-dir> <target-dir>
//       Writes exactly that output for every .md/.mdx file under <source-dir> to
//       <target-dir>/<relative-path-with-_-instead-of-/>.json. Each file is read as
//       "./<relative>" with the source directory as working directory (that is what the
//       "file" field shows).
//
//   php tools/dump-ast.php [--alias from=to ...] --check <source-dir> <snapshot-dir>
//       Compares every file byte for byte with its snapshot; exit 0 only when all are equal.
//
//   When the target ends in ".json", the snapshot is a single fixture file: an object
//   { "<relative-path>": {file, frontmatter, headings, blocks}, … }. Each entry is compared
//   with the single-file output above, after encoding the entry again with the same flags.
//
//   --alias maps a frontmatter key onto an allowed one (content.frontmatter_aliases), e.g.
//   --alias date=updated. It may be repeated.

require_once dirname(__DIR__) . '/src/lib/Markdown.php';

use Pholio\Markdown;
use Pholio\MarkdownException;

/**
 * AST output of one file, identical for a single call and a snapshot.
 *
 * @param array<string,string> $aliases
 */
function dump_ast_render(string $path, array $aliases): string
{
    $document = Markdown::parse((string) file_get_contents($path), $path, $aliases);

    return json_encode(
        [
            'file' => $document->file,
            'frontmatter' => $document->frontmatter,
            'headings' => $document->headings(),
            'blocks' => $document->blocks,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n";
}

/** Encode a fixture entry the same way as the single-file output. */
function dump_ast_encode_entry(mixed $entry): string
{
    return json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

/** @return list<string> relative paths, sorted */
function dump_ast_sources(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        /** @var SplFileInfo $entry */
        $extension = strtolower($entry->getExtension());
        if ($extension === 'md' || $extension === 'mdx') {
            $files[] = substr($entry->getPathname(), strlen($root) + 1);
        }
    }
    sort($files, SORT_STRING);

    return $files;
}

$argvList = $argv ?? [];
$script = array_shift($argvList) ?? 'dump-ast.php';
$aliases = [];
while (($argvList[0] ?? '') === '--alias') {
    $pair = $argvList[1] ?? '';
    if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=([A-Za-z_][A-Za-z0-9_]*)$/', $pair, $m) !== 1) {
        fwrite(STDERR, "--alias expects from=to, got: {$pair}\n");
        exit(2);
    }
    $aliases[$m[1]] = $m[2];
    array_splice($argvList, 0, 2);
}
$mode = $argvList[0] ?? '';

if ($mode === '--snapshot' || $mode === '--check') {
    if (count($argvList) !== 3) {
        fwrite(STDERR, "usage: php {$script} [--alias from=to] {$mode} <source-dir> <snapshot>\n");
        exit(2);
    }
    $root = realpath($argvList[1]);
    $target = $argvList[2];
    if ($root === false || !is_dir($root)) {
        fwrite(STDERR, "source directory not found: {$argvList[1]}\n");
        exit(2);
    }
    $fixtureMode = str_ends_with($target, '.json');
    $fixture = [];
    if ($fixtureMode) {
        if ($mode === '--check') {
            if (!is_file($target)) {
                fwrite(STDERR, "fixture not found: {$target}\n");
                exit(2);
            }
            $fixture = json_decode((string) file_get_contents($target), true, 4096, JSON_THROW_ON_ERROR);
        }
        $target = (string) (realpath(dirname($target)) ?: dirname($target)) . '/' . basename($target);
    } else {
        if ($mode === '--snapshot' && !is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
            fwrite(STDERR, "cannot create target directory: {$target}\n");
            exit(2);
        }
        $target = (string) realpath($target);
    }

    $previous = (string) getcwd();
    chdir($root);
    $differences = [];
    $sources = dump_ast_sources($root);
    foreach ($sources as $relative) {
        $snapshot = $target . '/' . str_replace('/', '_', $relative) . '.json';
        try {
            $output = dump_ast_render('./' . $relative, $aliases);
        } catch (MarkdownException $e) {
            $differences[] = $relative . ': MarkdownException ' . $e->describe();
            continue;
        }
        if ($fixtureMode) {
            if ($mode === '--snapshot') {
                $fixture[$relative] = json_decode($output, true, 4096, JSON_THROW_ON_ERROR);
            } elseif (!array_key_exists($relative, $fixture)) {
                $differences[] = $relative . ': no entry in the fixture';
            } elseif (dump_ast_encode_entry($fixture[$relative]) !== $output) {
                $differences[] = $relative . ': AST differs from the fixture';
            }
            continue;
        }
        if ($mode === '--snapshot') {
            file_put_contents($snapshot, $output);
            continue;
        }
        if (!is_file($snapshot)) {
            $differences[] = $relative . ': no snapshot ' . $snapshot;
        } elseif ((string) file_get_contents($snapshot) !== $output) {
            $differences[] = $relative . ': AST differs from the snapshot';
        }
    }
    chdir($previous);

    if ($fixtureMode && $mode === '--snapshot') {
        file_put_contents($target, dump_ast_encode_entry($fixture));
    }
    if ($fixtureMode && $mode === '--check') {
        foreach (array_diff(array_keys($fixture), $sources) as $orphan) {
            $differences[] = $orphan . ': in the fixture but missing from the source directory';
        }
    }

    foreach ($differences as $difference) {
        fwrite(STDERR, $difference . "\n");
    }
    $verb = $mode === '--snapshot' ? 'written' : 'compared';
    echo count($sources) . " files {$verb}, " . count($differences) . " differences.\n";
    exit($differences === [] ? 0 : 1);
}

if (count($argvList) !== 1) {
    fwrite(STDERR, "usage: php {$script} [--alias from=to] <file.md> | --snapshot <source> <target> | --check <source> <snapshot>\n");
    exit(2);
}

$path = $argvList[0];
if (!is_file($path)) {
    fwrite(STDERR, "file not found: {$path}\n");
    exit(2);
}

try {
    echo dump_ast_render($path, $aliases);
} catch (MarkdownException $e) {
    fwrite(STDERR, 'MarkdownException: ' . $e->describe() . "\n");
    exit(1);
}
