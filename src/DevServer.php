<?php

declare(strict_types=1);

/**
 * Router script for PHP's built-in server, started by `pholio dev`:
 *
 *   php -S 127.0.0.1:8080 -t <site root> src/DevServer.php
 *
 * Mirrors the generated .htaccess: existing files are served as they are, a
 * URL with or without trailing slash whose directory holds index.html serves
 * that page, anything else is 404. Hidden files and *.md are never served.
 */

$root = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/');
$path = rawurldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));

$segments = array_filter(explode('/', $path), 'strlen');
foreach ($segments as $segment) {
    if ($segment === '..' || $segment[0] === '.') {
        pholio_dev_not_found($path);

        return true;
    }
}
if (str_ends_with(strtolower($path), '.md')) {
    pholio_dev_not_found($path);

    return true;
}

$file = $root . '/' . implode('/', $segments);
if (is_file($file)) {
    // Let the built-in server send the file with its MIME type.
    return false;
}

$index = rtrim($file, '/') . '/index.html';
if (is_file($index)) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    readfile($index);

    return true;
}

pholio_dev_not_found($path);

return true;

function pholio_dev_not_found(string $path): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found: {$path}\n";
}
