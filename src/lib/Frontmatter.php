<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../Exceptions.php';

/**
 * Minimal frontmatter reader for the page tree.
 *
 * It reads only the YAML block between the two `---` lines at the start of the
 * file, and there only flat `key: value` pairs (optionally in double or single
 * quotes, `true`/`false`). The tree needs nothing more; the Markdown parser
 * reads the content itself.
 */
final class Frontmatter
{
    /** @return array<string, string|bool> */
    public static function read(string $file): array
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            throw new IoException('file is not readable', $file);
        }

        $first = fgets($handle);
        if ($first === false || rtrim($first, "\r\n") !== '---') {
            fclose($handle);
            return [];
        }

        $data = [];
        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '---') {
                break;
            }
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            $data[$key] = self::scalar($value);
        }

        fclose($handle);

        return $data;
    }

    private static function scalar(string $value): string|bool
    {
        $length = strlen($value);
        if ($length >= 2) {
            $quote = $value[0];
            if (($quote === '"' || $quote === "'") && $value[$length - 1] === $quote) {
                $inner = substr($value, 1, -1);
                return $quote === '"' ? str_replace(['\\"', '\\\\'], ['"', '\\'], $inner) : $inner;
            }
        }

        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }

        return $value;
    }
}
