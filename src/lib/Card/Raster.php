<?php

declare(strict_types=1);

namespace Pholio\Card;

/**
 * Anti-aliased scanline rasterizer: closed paths in, a coverage mask out.
 *
 * Paths are lists of contours of operations, in any unit:
 *   [0, x, y]                      move to
 *   [1, x, y]                      line to
 *   [2, cx, cy, x, y]              quadratic curve
 *   [3, c1x, c1y, c2x, c2y, x, y]  cubic curve
 * Every contour is closed. flatten() maps them through `x * sx + tx`,
 * `y * sy + ty` into pixels (y down) and replaces curves by line segments;
 * coverage() turns the segments into exact area coverage with the signed-area
 * accumulation of font-rs and stb_truetype: each segment adds its signed area
 * to the cells it crosses, and a running sum along every row gives each
 * pixel's coverage. The fill rule is non-zero, with overlapping contours of the
 * same direction clamped to full coverage.
 *
 * The mask covers the path's pixel bounding box only, so the cost follows the
 * size of the shape, not of the canvas. The arithmetic is plain IEEE doubles in
 * a fixed order and rounds once at the end, so a mask is the same on every
 * machine.
 */
final class Raster
{
    /** Largest deviation of a flattened curve from the true curve, in pixels. */
    private const TOLERANCE = 0.1;

    /**
     * Contours as closed polygons in pixels.
     *
     * @param list<list<array<int, int|float>>> $contours
     * @return list<list<array{0:float, 1:float}>>
     */
    public static function flatten(array $contours, float $sx, float $sy, float $tx, float $ty): array
    {
        $out = [];
        foreach ($contours as $contour) {
            $polygon = [];
            $x = $y = 0.0;
            foreach ($contour as $op) {
                switch ($op[0]) {
                    case 0:
                    case 1:
                        $x = $op[1] * $sx + $tx;
                        $y = $op[2] * $sy + $ty;
                        $polygon[] = [$x, $y];
                        break;
                    case 2:
                        $cx = $op[1] * $sx + $tx;
                        $cy = $op[2] * $sy + $ty;
                        $ex = $op[3] * $sx + $tx;
                        $ey = $op[4] * $sy + $ty;
                        // Deviation of the chord from a quadratic: |p0 - 2c + p1| / 4, shrinking with n².
                        $dev = sqrt(($x - 2 * $cx + $ex) ** 2 + ($y - 2 * $cy + $ey) ** 2) / 4;
                        $n = max(1, (int) ceil(sqrt($dev / self::TOLERANCE)));
                        for ($i = 1; $i <= $n; $i++) {
                            $t = $i / $n;
                            $u = 1 - $t;
                            $polygon[] = [$u * $u * $x + 2 * $u * $t * $cx + $t * $t * $ex, $u * $u * $y + 2 * $u * $t * $cy + $t * $t * $ey];
                        }
                        $x = $ex;
                        $y = $ey;
                        break;
                    case 3:
                        $c1x = $op[1] * $sx + $tx;
                        $c1y = $op[2] * $sy + $ty;
                        $c2x = $op[3] * $sx + $tx;
                        $c2y = $op[4] * $sy + $ty;
                        $ex = $op[5] * $sx + $tx;
                        $ey = $op[6] * $sy + $ty;
                        $dev = 0.75 * max(
                            sqrt(($x - 2 * $c1x + $c2x) ** 2 + ($y - 2 * $c1y + $c2y) ** 2),
                            sqrt(($c1x - 2 * $c2x + $ex) ** 2 + ($c1y - 2 * $c2y + $ey) ** 2),
                        );
                        $n = max(1, (int) ceil(sqrt($dev / self::TOLERANCE)));
                        for ($i = 1; $i <= $n; $i++) {
                            $t = $i / $n;
                            $u = 1 - $t;
                            $polygon[] = [
                                $u * $u * $u * $x + 3 * $u * $u * $t * $c1x + 3 * $u * $t * $t * $c2x + $t * $t * $t * $ex,
                                $u * $u * $u * $y + 3 * $u * $u * $t * $c1y + 3 * $u * $t * $t * $c2y + $t * $t * $t * $ey,
                            ];
                        }
                        $x = $ex;
                        $y = $ey;
                        break;
                    default:
                        throw new \LogicException('unknown path operation ' . $op[0]);
                }
            }
            if (count($polygon) > 2) {
                $out[] = $polygon;
            }
        }

        return $out;
    }

    /**
     * Coverage of closed polygons in pixels: the mask of their bounding box,
     * one byte per pixel (0 empty, 255 covered), or null when nothing is covered.
     *
     * @param list<list<array{0:float, 1:float}>> $polygons
     * @return array{x:int, y:int, w:int, h:int, alpha:string}|null
     */
    public static function coverage(array $polygons): ?array
    {
        $minX = $minY = INF;
        $maxX = $maxY = -INF;
        foreach ($polygons as $polygon) {
            foreach ($polygon as [$x, $y]) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }
        if ($minX === INF) {
            return null;
        }
        $left = (int) floor($minX);
        $top = (int) floor($minY);
        $w = (int) ceil($maxX) - $left;
        $h = (int) ceil($maxY) - $top;
        if ($w <= 0 || $h <= 0) {
            return null;
        }

        // Two cells of padding per row take the area that spills right of the last pixel.
        $stride = $w + 2;
        $acc = array_fill(0, $stride * $h, 0.0);
        foreach ($polygons as $polygon) {
            $count = count($polygon);
            for ($i = 0; $i < $count; $i++) {
                [$x0, $y0] = $polygon[$i];
                [$x1, $y1] = $polygon[($i + 1) % $count];
                self::line($acc, $stride, $h, $x0 - $left, $y0 - $top, $x1 - $left, $y1 - $top);
            }
        }

        $alpha = '';
        $any = false;
        for ($row = 0; $row < $h; $row++) {
            $sum = 0.0;
            $base = $row * $stride;
            $line = '';
            for ($col = 0; $col < $w; $col++) {
                $sum += $acc[$base + $col];
                $value = $sum < 0 ? -$sum : $sum;
                $byte = $value >= 1.0 ? 255 : (int) ($value * 255 + 0.5);
                if ($byte !== 0) {
                    $any = true;
                }
                $line .= chr($byte);
            }
            $alpha .= $line;
        }

        return $any ? ['x' => $left, 'y' => $top, 'w' => $w, 'h' => $h, 'alpha' => $alpha] : null;
    }

    /**
     * Add the signed area of one segment to the accumulation buffer (font-rs's
     * draw_line): per row the segment crosses, the area left of it, split over
     * the cells between its two x positions.
     *
     * @param list<float> $acc
     */
    private static function line(array &$acc, int $stride, int $h, float $x0, float $y0, float $x1, float $y1): void
    {
        if ($y0 === $y1) {
            return;
        }
        if ($y0 < $y1) {
            $dir = 1.0;
        } else {
            $dir = -1.0;
            [$x0, $y0, $x1, $y1] = [$x1, $y1, $x0, $y0];
        }
        $dxdy = ($x1 - $x0) / ($y1 - $y0);
        $x = $x0;
        if ($y0 < 0) {
            $x -= $y0 * $dxdy;
        }
        $rowEnd = min($h, (int) ceil($y1));
        for ($y = max(0, (int) $y0); $y < $rowEnd; $y++) {
            $base = $y * $stride;
            $dy = min($y + 1.0, $y1) - max((float) $y, $y0);
            $xNext = $x + $dxdy * $dy;
            $d = $dy * $dir;
            [$xa, $xb] = $x < $xNext ? [$x, $xNext] : [$xNext, $x];
            $xaFloor = floor($xa);
            $xai = (int) $xaFloor;
            $xbCeil = ceil($xb);
            $xbi = (int) $xbCeil;
            if ($xai < 0) {
                // Outside the mask's bounding box only by rounding; clamp.
                $xai = 0;
                $xaFloor = 0.0;
            }
            if ($xbi <= $xai + 1) {
                $xmf = 0.5 * ($x + $xNext) - $xaFloor;
                $acc[$base + $xai] += $d - $d * $xmf;
                $acc[$base + $xai + 1] += $d * $xmf;
            } else {
                $s = 1.0 / ($xb - $xa);
                $xaf = $xa - $xaFloor;
                $a0 = 0.5 * $s * (1.0 - $xaf) * (1.0 - $xaf);
                $xbf = $xb - $xbCeil + 1.0;
                $am = 0.5 * $s * $xbf * $xbf;
                $acc[$base + $xai] += $d * $a0;
                if ($xbi === $xai + 2) {
                    $acc[$base + $xai + 1] += $d * (1.0 - $a0 - $am);
                } else {
                    $a1 = $s * (1.5 - $xaf);
                    $acc[$base + $xai + 1] += $d * ($a1 - $a0);
                    for ($xi = $xai + 2; $xi < $xbi - 1; $xi++) {
                        $acc[$base + $xi] += $d * $s;
                    }
                    $a2 = $a1 + ($xbi - $xai - 3) * $s;
                    $acc[$base + $xbi - 1] += $d * (1.0 - $a2 - $am);
                }
                $acc[$base + $xbi] += $d * $am;
            }
            $x = $xNext;
        }
    }
}
