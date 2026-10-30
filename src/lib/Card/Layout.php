<?php

declare(strict_types=1);

namespace Pholio\Card;

/**
 * The two share card designs, 1200 × 630 pixels, the size Open Graph and X
 * (`summary_large_image`) show without cropping.
 *
 * Page card:
 *
 *   [logo] Brand                                     top: logo and brand name
 *
 *   The page title, big and semibold, up to three    shrinks from 72 to 40 px until it
 *   lines                                            fits; a single word that doesn't
 *   The description in the muted colour, up to       fit on a line breaks between letters
 *   two lines, cut with an ellipsis…
 *
 *   docs.example.org/guide/install                   bottom: host and path, muted
 *   ════════════════════════════════════════════     a bar in the accent colour
 *
 * Title and description sit centred between header and footer.
 *
 * Site card: the logo big on the left with the brand name next to it, the site
 * description above the host (up to three lines, shrinking), the accent bar.
 *
 * Every text is checked against the font before anything is drawn (Text::missing()),
 * so the layout never paints a .notdef box.
 */
final class Layout
{
    public const WIDTH = 1200;
    public const HEIGHT = 630;

    private const MARGIN_X = 80;
    private const MARGIN_TOP = 72;
    private const MARGIN_BOTTOM = 72;

    /**
     * @param array{brand: string, logo: ?Svg, heading: Text, text: Text,
     *   colors: array{background: array{0:int,1:int,2:int}, foreground: array{0:int,1:int,2:int},
     *   muted: array{0:int,1:int,2:int}, accent: array{0:int,1:int,2:int}}} $style
     */
    public static function page(array $style, string $title, ?string $description, string $location): Canvas
    {
        $colors = $style['colors'];
        $canvas = new Canvas(self::WIDTH, self::HEIGHT, $colors['background']);
        $heading = $style['heading'];
        $text = $style['text'];
        $width = self::WIDTH - 2 * self::MARGIN_X;

        // Header: logo and brand, the brand's capitals centred on the logo.
        $headerBottom = self::header($canvas, $style, self::MARGIN_X, self::MARGIN_TOP, 56.0, 36.0);
        self::bar($canvas, $colors['accent']);

        // Footer: host and path on one line.
        $footerSize = 28.0;
        $footerBaseline = self::HEIGHT - self::MARGIN_BOTTOM;
        $footer = $text->wrap($location, $footerSize, $width, 1)['lines'][0] ?? '';
        $text->paint($canvas, $footer, $footerSize, self::MARGIN_X, $footerBaseline, $colors['muted']);

        // Title and description between them.
        $top = $headerBottom + 64;
        $bottom = $footerBaseline - (int) ceil($text->capHeight($footerSize)) - 48;
        $descriptionSize = 30.0;
        $descriptionLeading = round($descriptionSize * 1.4);
        $descriptionLines = $description === null || trim($description) === ''
            ? [] : $text->wrap($description, $descriptionSize, $width, 2)['lines'];
        $tracking = -0.02;

        $chosen = null;
        for ($size = 72.0; $size >= 40.0; $size -= 2.0) {
            $wrapped = $heading->wrap($title, $size, $width, 3, $tracking);
            if ($wrapped['broken'] || $wrapped['truncated']) {
                continue;
            }
            if (self::blockHeight($heading, $text, $size, count($wrapped['lines']), $descriptionSize, count($descriptionLines)) <= $bottom - $top) {
                $chosen = [$size, $wrapped['lines']];
                break;
            }
        }
        if ($chosen === null) {
            // The smallest size: long words break between letters, a fourth line becomes an ellipsis.
            $chosen = [40.0, $heading->wrap($title, 40.0, $width, 3, $tracking)['lines']];
            while ($descriptionLines !== [] && self::blockHeight($heading, $text, 40.0, count($chosen[1]), $descriptionSize, count($descriptionLines)) > $bottom - $top) {
                array_pop($descriptionLines);
                if ($descriptionLines !== []) {
                    $descriptionLines[0] = $text->ellipsize($descriptionLines[0], $descriptionSize, $width);
                }
            }
        }
        [$size, $lines] = $chosen;

        // Centred between header and footer.
        $block = self::blockHeight($heading, $text, $size, count($lines), $descriptionSize, count($descriptionLines));
        $top += (int) max(0, floor(($bottom - $top - $block) / 2));
        $leading = round($size * 1.12);
        $baseline = $top + (int) round($heading->capHeight($size));
        foreach ($lines as $i => $line) {
            $heading->paint($canvas, $line, $size, self::MARGIN_X, (int) ($baseline + $i * $leading), $colors['foreground'], $tracking);
        }
        $baseline = (int) ($baseline + (count($lines) - 1) * $leading + round($size * 0.32) + 24 + round($text->capHeight($descriptionSize)));
        foreach ($descriptionLines as $i => $line) {
            $text->paint($canvas, $line, $descriptionSize, self::MARGIN_X, (int) ($baseline + $i * $descriptionLeading), $colors['muted']);
        }

        return $canvas;
    }

    /**
     * @param array{brand: string, logo: ?Svg, heading: Text, text: Text,
     *   colors: array{background: array{0:int,1:int,2:int}, foreground: array{0:int,1:int,2:int},
     *   muted: array{0:int,1:int,2:int}, accent: array{0:int,1:int,2:int}}} $style
     */
    public static function site(array $style, ?string $description, string $location): Canvas
    {
        $colors = $style['colors'];
        $canvas = new Canvas(self::WIDTH, self::HEIGHT, $colors['background']);
        $heading = $style['heading'];
        $text = $style['text'];
        $left = 112;
        $width = self::WIDTH - $left - self::MARGIN_X;

        $headerBottom = self::header($canvas, $style, $left, 100, 176.0, 104.0, 64);
        self::bar($canvas, $colors['accent']);

        $footerSize = 28.0;
        $footerBaseline = self::HEIGHT - 88;
        $footer = $text->wrap($location, $footerSize, $width, 1)['lines'][0] ?? '';
        $text->paint($canvas, $footer, $footerSize, $left, $footerBaseline, $colors['muted']);

        if ($description === null || trim($description) === '') {
            return $canvas;
        }
        // The description sits on the footer, growing upwards, at the largest size that fits three lines.
        $room = $footerBaseline - 64 - ($headerBottom + 48);
        $lines = [];
        $size = 40.0;
        for (; $size >= 30.0; $size -= 2.0) {
            $wrapped = $text->wrap($description, $size, $width, 3);
            $lines = $wrapped['lines'];
            $height = (count($lines) - 1) * round($size * 1.3) + $text->capHeight($size);
            if (!$wrapped['truncated'] && !$wrapped['broken'] && $height <= $room) {
                break;
            }
        }
        $size = max($size, 30.0);
        $leading = round($size * 1.3);
        $last = $footerBaseline - 64;
        foreach ($lines as $i => $line) {
            $text->paint($canvas, $line, $size, $left, (int) ($last - (count($lines) - 1 - $i) * $leading), $colors['foreground']);
        }

        return $canvas;
    }

    /**
     * Logo and brand name in a row from ($x, $y); returns the row's bottom edge.
     *
     * @param array{brand: string, logo: ?Svg, heading: Text, text: Text, colors: array<string, array{0:int,1:int,2:int}>} $style
     */
    private static function header(Canvas $canvas, array $style, int $x, int $y, float $logoHeight, float $brandSize, int $gap = 20): int
    {
        $heading = $style['heading'];
        $maxWidth = self::WIDTH - self::MARGIN_X - $x;
        $height = (int) round($logoHeight);
        $textX = $x;
        if ($style['logo'] !== null) {
            [$logoWidth, $height] = $style['logo']->paint($canvas, $x, $y, $logoHeight, $style['colors']['foreground']);
            $textX = $x + $logoWidth + $gap;
            $maxWidth -= $logoWidth + $gap;
        }
        $tracking = -0.02;
        while ($brandSize > 12 && $heading->width($style['brand'], $brandSize, $tracking) > $maxWidth) {
            $brandSize -= 2;
        }
        $baseline = $y + (int) round(($height + $heading->capHeight($brandSize)) / 2);
        $brand = $heading->wrap($style['brand'], $brandSize, $maxWidth, 1, $tracking)['lines'][0] ?? '';
        $heading->paint($canvas, $brand, $brandSize, $textX, $baseline, $style['colors']['foreground'], $tracking);

        return $y + $height;
    }

    /**
     * The accent colour as a bar along the bottom edge.
     *
     * @param array{0:int, 1:int, 2:int} $color
     */
    private static function bar(Canvas $canvas, array $color): void
    {
        $height = 8;
        $canvas->paint(['x' => 0, 'y' => self::HEIGHT - $height, 'w' => self::WIDTH, 'h' => $height, 'alpha' => str_repeat("\xff", self::WIDTH * $height)], $color);
    }

    private static function blockHeight(Text $heading, Text $text, float $size, int $lines, float $descriptionSize, int $descriptionLines): float
    {
        $height = $heading->capHeight($size) + ($lines - 1) * round($size * 1.12) + round($size * 0.32);
        if ($descriptionLines > 0) {
            $height += 24 + $text->capHeight($descriptionSize) + ($descriptionLines - 1) * round($descriptionSize * 1.4) + $text->descent($descriptionSize);
        }

        return $height;
    }
}
