<?php

declare(strict_types=1);

namespace Pholio\Card;

/**
 * Opaque CSS colours as RGB bytes: `#rgb`, `#rrggbb`, `rgb(…)` and `hsl(…)` in
 * the comma and the space syntax. A colour with an alpha channel (`#rrggbbaa`,
 * `rgb(… / .5)`) and every other form, such as `oklch()` or `var()`, is refused:
 * a card is painted onto its own background, so nothing could show through.
 */
final class Color
{
    /**
     * @return array{0:int, 1:int, 2:int}
     * @throws \InvalidArgumentException with a message naming the accepted forms
     */
    public static function parse(string $value): array
    {
        $css = strtolower(trim($value));
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $css, $m) === 1) {
            $hex = strlen($m[1]) === 3 ? $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2] : $m[1];

            return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        }
        $number = '([+-]?(?:\d+\.?\d*|\.\d+))';
        if (preg_match('/^(rgb|hsl)a?\(\s*' . $number . '(deg|%)?\s*,?\s*' . $number . '(%)?\s*,?\s*' . $number . '(%)?\s*\)$/', $css, $m) === 1) {
            $m += array_fill(0, 8, '');
            if ($m[1] === 'rgb') {
                $channel = static fn(string $n, string $unit): int => (int) round(max(0.0, min(255.0, $unit === '%' ? (float) $n * 255 / 100 : (float) $n)));

                return [$channel($m[2], $m[3]), $channel($m[4], $m[5]), $channel($m[6], $m[7])];
            }
            if ($m[3] === '%' || $m[5] !== '%' || $m[7] !== '%') {
                throw new \InvalidArgumentException('expected hsl(<hue> <saturation>% <lightness>%), got "' . $value . '"');
            }

            return self::hsl((float) $m[2], (float) $m[4] / 100, (float) $m[6] / 100);
        }

        throw new \InvalidArgumentException('expected an opaque colour as #rgb, #rrggbb, rgb() or hsl(), got "' . $value . '"');
    }

    /** "#rrggbb" of an RGB triple. */
    public static function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }

    /** @return array{0:int, 1:int, 2:int} */
    private static function hsl(float $hue, float $saturation, float $lightness): array
    {
        $hue = fmod(fmod($hue, 360.0) + 360.0, 360.0);
        $saturation = max(0.0, min(1.0, $saturation));
        $lightness = max(0.0, min(1.0, $lightness));
        // CSS Color 4, hslToRgb.
        $channel = static function (float $n) use ($hue, $saturation, $lightness): int {
            $k = fmod($n + $hue / 30, 12.0);
            $a = $saturation * min($lightness, 1 - $lightness);

            return (int) round(255 * ($lightness - $a * max(-1.0, min($k - 3, 9 - $k, 1.0))));
        };

        return [$channel(0.0), $channel(8.0), $channel(4.0)];
    }
}
