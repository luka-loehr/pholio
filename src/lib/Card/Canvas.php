<?php

declare(strict_types=1);

namespace Pholio\Card;

/**
 * An opaque RGB image in memory, 8 bits per channel, row by row: the share
 * card being drawn. Coverage masks from Raster are composited onto it in one
 * colour each, in integer arithmetic, so the result is the same on every machine.
 */
final class Canvas
{
    /** @var string width × height × 3 bytes */
    private string $pixels;

    /** @param array{0:int, 1:int, 2:int} $background */
    public function __construct(public readonly int $width, public readonly int $height, array $background)
    {
        if ($width < 1 || $height < 1) {
            throw new \LogicException('canvas size must be positive');
        }
        $this->pixels = str_repeat(chr($background[0]) . chr($background[1]) . chr($background[2]), $width * $height);
    }

    /**
     * Composite a coverage mask in $color: each pixel moves from its colour
     * towards $color by the mask's coverage. Parts outside the canvas are cut off.
     *
     * @param array{x:int, y:int, w:int, h:int, alpha:string} $mask
     * @param array{0:int, 1:int, 2:int} $color
     */
    public function paint(array $mask, array $color, int $dx = 0, int $dy = 0): void
    {
        [$r, $g, $b] = $color;
        $solid = chr($r) . chr($g) . chr($b);
        $x0 = $mask['x'] + $dx;
        $y0 = $mask['y'] + $dy;
        $w = $mask['w'];
        $alpha = $mask['alpha'];
        $colStart = max(0, -$x0);
        $colEnd = min($w, $this->width - $x0);
        $rowStart = max(0, -$y0);
        $rowEnd = min($mask['h'], $this->height - $y0);
        $pixels = &$this->pixels;

        for ($row = $rowStart; $row < $rowEnd; $row++) {
            $maskRow = $row * $w;
            $out = (($y0 + $row) * $this->width + $x0) * 3;
            for ($col = $colStart; $col < $colEnd; $col++) {
                $a = ord($alpha[$maskRow + $col]);
                if ($a === 0) {
                    continue;
                }
                $p = $out + 3 * $col;
                if ($a === 255) {
                    $pixels[$p] = $solid[0];
                    $pixels[$p + 1] = $solid[1];
                    $pixels[$p + 2] = $solid[2];
                    continue;
                }
                $keep = 255 - $a;
                $pixels[$p] = chr(intdiv(ord($pixels[$p]) * $keep + $r * $a + 127, 255));
                $pixels[$p + 1] = chr(intdiv(ord($pixels[$p + 1]) * $keep + $g * $a + 127, 255));
                $pixels[$p + 2] = chr(intdiv(ord($pixels[$p + 2]) * $keep + $b * $a + 127, 255));
            }
        }
    }

    /** @return array{0:int, 1:int, 2:int} */
    public function pixel(int $x, int $y): array
    {
        $p = ($y * $this->width + $x) * 3;

        return [ord($this->pixels[$p]), ord($this->pixels[$p + 1]), ord($this->pixels[$p + 2])];
    }

    /** The image as a PNG file. */
    public function png(): string
    {
        return Png::encode($this->width, $this->height, $this->pixels);
    }
}
