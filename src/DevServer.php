<?php

declare(strict_types=1);

/**
 * Router script for PHP's built-in server, started by `pholio dev`:
 *
 *   PHOLIO_HOME_URL=/docs php -S 127.0.0.1:8080 -t <site root> src/DevServer.php
 *
 * Mirrors the generated .htaccess: existing files are served as they are, a
 * URL with or without trailing slash whose directory holds index.html serves
 * that page, anything else is 404. Hidden files are never served, except below
 * `.well-known/`.
 *
 * Agents, as in production (AgentHeaders): when the build wrote `_headers` into
 * the start page's directory (PHOLIO_HOME_URL, default "/"), its headers go on
 * every response, 404s included, and a page is answered with its Markdown twin
 * (`x.md` next to `x/index.html`) for `Accept: text/markdown` or an AI
 * assistant's user agent, and as text/plain for `Accept: text/plain`.
 */

require_once __DIR__ . '/AgentHeaders.php';

use Pholio\AgentHeaders;

$root = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/');
$path = rawurldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));
$home = rtrim((string) (getenv('PHOLIO_HOME_URL') ?: '/'), '/');

$rules = pholio_dev_header_rules($root . $home . '/' . AgentHeaders::FILE);
foreach ($rules as [$pattern, $headers]) {
    if (preg_match($pattern, $path) === 1) {
        foreach ($headers as [$name, $value]) {
            header($name . ': ' . $value, strcasecmp($name, 'Vary') !== 0);
        }
    }
}

$segments = array_values(array_filter(explode('/', $path), 'strlen'));
foreach ($segments as $segment) {
    if ($segment === '..' || ($segment[0] === '.' && $segment !== '.well-known')) {
        pholio_dev_not_found($path);

        return true;
    }
}

$file = $root . '/' . implode('/', $segments);
$twin = $rules === [] ? null : AgentHeaders::negotiate((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
if ($twin !== null && !is_file($file)) {
    $base = rtrim($file, '/');
    foreach ($segments === [] ? [$base . '/index.md'] : [$base . '.md', $base . '/index.md'] as $candidate) {
        if (is_file($candidate)) {
            pholio_dev_send($candidate, $twin === 'plain' ? 'text/plain; charset=utf-8' : 'text/markdown; charset=utf-8');

            return true;
        }
    }
}

if (is_file($file)) {
    $types = [
        'html' => 'text/html; charset=utf-8',
        'md' => 'text/markdown; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'json' => 'application/json',
        'xml' => 'application/xml; charset=utf-8',
    ];
    $type = $types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
    if ($type === null) {
        // Let the built-in server send the file with its MIME type.
        return false;
    }
    pholio_dev_send($file, $type);

    return true;
}

$index = rtrim($file, '/') . '/index.html';
if (is_file($index)) {
    pholio_dev_send($index, 'text/html; charset=utf-8');

    return true;
}

pholio_dev_not_found($path);

return true;

function pholio_dev_send(string $file, string $type): void
{
    header('Content-Type: ' . $type);
    header('Cache-Control: no-cache');
    readfile($file);
}

function pholio_dev_not_found(string $path): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found: {$path}\n";
}

/**
 * The rules of a `_headers` file: a URL pattern line (`*` matches anything) followed
 * by indented `Name: value` lines.
 *
 * @return list<array{0:string, 1:list<array{0:string, 1:string}>}> regular expression, headers
 */
function pholio_dev_header_rules(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $rules = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
            continue;
        }
        if ($line[0] !== ' ' && $line[0] !== "\t") {
            $rules[] = ['#^' . str_replace('\*', '.*', preg_quote(trim($line), '#')) . '$#', []];
            continue;
        }
        if ($rules !== [] && str_contains($line, ':')) {
            [$name, $value] = explode(':', trim($line), 2);
            $rules[count($rules) - 1][1][] = [trim($name), trim($value)];
        }
    }

    return $rules;
}
