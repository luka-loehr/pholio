<?php

declare(strict_types=1);

namespace Pholio\Card;

/**
 * Lines of text in one TrueType font: measuring with kerning and tracking,
 * wrapping into a number of lines, and painting onto a canvas.
 *
 * Glyphs are rasterized once per size and quarter-pixel offset and kept, so a
 * build that sets the same font on a hundred cards rasterizes each letter a
 * few times, not a few thousand. Positions are quantized to a quarter pixel
 * horizontally and to whole pixels for the baseline, which keeps the result
 * independent of where on the canvas a word lands.
 */
final class Text
{
    public const ELLIPSIS = '…';

    /** @var array<string, array{x:int, y:int, w:int, h:int, alpha:string}|null> glyph masks by "glyph size quarter" */
    private array $masks = [];

    /** @var array<int, int> code point => glyph */
    private array $glyphs = [];

    private ?float $capHeight = null;

    public function __construct(public readonly TrueType $font)
    {
    }

    /**
     * Code points of $text the font has no glyph for, in order of appearance, each once.
     *
     * @return list<int>
     */
    public function missing(string $text): array
    {
        $out = [];
        foreach (self::codePoints($text) as $codePoint) {
            if ($this->glyph($codePoint) === 0 && !in_array($codePoint, $out, true)) {
                $out[] = $codePoint;
            }
        }

        return $out;
    }

    /** Height of a capital letter (the top of "H") in pixels at $size. */
    public function capHeight(float $size): float
    {
        if ($this->capHeight === null) {
            $top = 0;
            foreach ($this->font->outline($this->glyph(0x48)) as $contour) {
                foreach ($contour as $op) {
                    for ($i = 2; $i < count($op); $i += 2) {
                        $top = max($top, $op[$i]);
                    }
                }
            }
            $this->capHeight = $top > 0 ? $top / $this->font->unitsPerEm : 0.7;
        }

        return $this->capHeight * $size;
    }

    /** Depth below the baseline in pixels at $size (hhea descender, positive). */
    public function descent(float $size): float
    {
        return -$this->font->descender / $this->font->unitsPerEm * $size;
    }

    /** Advance width of a line in pixels; $tracking is added between letters, in em. */
    public function width(string $text, float $size, float $tracking = 0.0): float
    {
        $units = 0;
        $previous = null;
        $count = 0;
        foreach (self::codePoints($text) as $codePoint) {
            $glyph = $this->glyph($codePoint);
            if ($previous !== null) {
                $units += $this->font->kern($previous, $glyph);
            }
            $units += $this->font->advance($glyph);
            $previous = $glyph;
            $count++;
        }

        return $units * $size / $this->font->unitsPerEm + max(0, $count - 1) * $tracking * $size;
    }

    /**
     * Break $text into lines no wider than $maxWidth, at spaces; a word wider
     * than a line on its own is broken between letters (`broken`). More than
     * $maxLines lines are cut to $maxLines, the last one ending in an ellipsis
     * (`truncated`).
     *
     * @return array{lines: list<string>, broken: bool, truncated: bool}
     */
    public function wrap(string $text, float $size, float $maxWidth, int $maxLines, float $tracking = 0.0): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $fits = fn(string $line): bool => $this->width($line, $size, $tracking) <= $maxWidth;
        $lines = [];
        $current = '';
        $broken = false;
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($fits($candidate)) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $word;
            if (!$fits($word)) {
                $broken = true;
                $current = '';
                foreach (mb_str_split($word) as $letter) {
                    if ($current !== '' && !$fits($current . $letter)) {
                        $lines[] = $current;
                        $current = '';
                    }
                    $current .= $letter;
                }
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        $truncated = count($lines) > $maxLines;
        if ($truncated) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = $this->ellipsize($lines[$maxLines - 1], $size, $maxWidth, $tracking);
        }

        return ['lines' => $lines, 'broken' => $broken, 'truncated' => $truncated];
    }

    /** $line shortened until it fits with an ellipsis after it: by words while there are several, then by letters. */
    public function ellipsize(string $line, float $size, float $maxWidth, float $tracking = 0.0): string
    {
        $trim = static fn(string $s): string => rtrim($s, " \t,;:.-–—");
        $line = $trim($line);
        while ($line !== '' && $this->width($line . self::ELLIPSIS, $size, $tracking) > $maxWidth) {
            $space = mb_strrpos($line, ' ');
            $line = $trim($space !== false ? mb_substr($line, 0, $space) : mb_substr($line, 0, -1));
        }

        return $line . self::ELLIPSIS;
    }

    /**
     * Paint one line with its left edge at $x and its baseline at $baseline.
     *
     * @param array{0:int, 1:int, 2:int} $color
     */
    public function paint(Canvas $canvas, string $text, float $size, float $x, int $baseline, array $color, float $tracking = 0.0): void
    {
        $scale = $size / $this->font->unitsPerEm;
        $sizeKey = sprintf('%.3f', $size);
        $pen = $x;
        $previous = null;
        foreach (self::codePoints($text) as $codePoint) {
            $glyph = $this->glyph($codePoint);
            if ($previous !== null) {
                $pen += $this->font->kern($previous, $glyph) * $scale + $tracking * $size;
            }
            $left = (int) floor($pen);
            $quarter = (int) floor(($pen - $left) * 4 + 0.5);
            if ($quarter === 4) {
                $left++;
                $quarter = 0;
            }
            $key = $glyph . ' ' . $sizeKey . ' ' . $quarter;
            if (!array_key_exists($key, $this->masks)) {
                $this->masks[$key] = Raster::coverage(Raster::flatten($this->font->outline($glyph), $scale, -$scale, $quarter / 4, 0.0));
            }
            if ($this->masks[$key] !== null) {
                $canvas->paint($this->masks[$key], $color, $left, $baseline);
            }
            $pen += $this->font->advance($glyph) * $scale;
            $previous = $glyph;
        }
    }

    private function glyph(int $codePoint): int
    {
        return $this->glyphs[$codePoint] ??= $this->font->glyph($codePoint);
    }

    /** @return list<int> */
    private static function codePoints(string $text): array
    {
        return array_map('mb_ord', mb_str_split($text));
    }
}
