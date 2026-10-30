<?php

declare(strict_types=1);

namespace Pholio\Card;

/**
 * PNG files: the encoder of the share cards and the comparison `pholio check`
 * uses for PNG files.
 *
 * encode() writes 8-bit RGB, no interlacing, filter type 0 on every row, and
 * exactly the chunks IHDR, one IDAT and IEND, compressed with zlib
 * (`gzcompress`, level 9). The pixel data is the same on every machine; the
 * compressed bytes are only as stable as the zlib library: a different zlib
 * (or zlib-ng) may choose other matches for the same data. That is why
 * sameImage() compares the decompressed stream instead of the file bytes.
 */
final class Png
{
    public const SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** Whether this PHP can write PNG files: zlib's gzcompress. */
    public static function available(): bool
    {
        return function_exists('gzcompress') && function_exists('gzuncompress');
    }

    /** @param string $rgb width × height × 3 bytes, row by row */
    public static function encode(int $width, int $height, string $rgb): string
    {
        if (strlen($rgb) !== $width * $height * 3) {
            throw new \LogicException('pixel data does not match ' . $width . '×' . $height);
        }
        if (!self::available()) {
            throw new \LogicException('PNG files need the PHP extension zlib');
        }
        // Every row starts with its filter type, 0 (none).
        $raw = "\x00" . implode("\x00", str_split($rgb, $width * 3));
        $compressed = gzcompress($raw, 9);
        if ($compressed === false) {
            throw new \RuntimeException('zlib failed to compress the image');
        }

        return self::SIGNATURE
            . self::chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . self::chunk('IDAT', $compressed)
            . self::chunk('IEND', '');
    }

    /**
     * Whether two files hold the same PNG: the same chunks in the same order,
     * with the image data compared after decompression. Files that are not
     * PNG, or not readable as one, are the same only when their bytes are.
     */
    public static function sameImage(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $left = self::canonical($a);

        return $left !== null && $left === self::canonical($b);
    }

    /**
     * The chunks of a PNG with the IDAT data joined and decompressed, or null
     * when the file is not a well-formed PNG or zlib is missing.
     */
    public static function canonical(string $png): ?string
    {
        if (!str_starts_with($png, self::SIGNATURE) || !self::available()) {
            return null;
        }
        $offset = strlen(self::SIGNATURE);
        $length = strlen($png);
        $chunks = [];
        $idat = null;
        while ($offset + 12 <= $length) {
            $size = unpack('N', $png, $offset)[1];
            $type = substr($png, $offset + 4, 4);
            if ($offset + 12 + $size > $length) {
                return null;
            }
            $data = substr($png, $offset + 8, $size);
            if (unpack('N', $png, $offset + 8 + $size)[1] !== crc32($type . $data)) {
                return null;
            }
            if ($type === 'IDAT') {
                if ($idat === null) {
                    $idat = '';
                    $chunks[] = 'IDAT';
                }
                $idat .= $data;
            } else {
                $chunks[] = $type . $data;
            }
            $offset += 12 + $size;
            if ($type === 'IEND') {
                break;
            }
        }
        if ($idat === null || $offset !== $length) {
            return null;
        }
        $inflated = @gzuncompress($idat);
        if ($inflated === false) {
            return null;
        }

        return implode("\x00", array_map(static fn(string $c): string => $c === 'IDAT' ? 'IDAT' . $inflated : $c, $chunks));
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
