<?php

declare(strict_types=1);

namespace Pholio\Card;

/**
 * The SVG subset a logo on a share card may use, read without an XML
 * extension: filled shapes in solid colours, which is what a logo mark is.
 *
 *   <svg>       viewBox (or width and height), shape-rendering, fill
 *   <g>         fill for its children, no transform
 *   <rect>      x, y, width, height, rx, ry
 *   <circle>, <ellipse>   cx, cy, r / rx, ry
 *   <polygon>   points
 *   <path>      d with every command but arcs (M L H V C S Q T Z, absolute and relative)
 *   <title>, <desc>, <metadata>   skipped with their contents
 *
 * Fills are opaque CSS colours (Color::parse()), `none` or `currentColor`, which
 * paints in the card's foreground colour; without a fill a shape is black, as
 * in SVG. The fill rule is nonzero. Any other element or attribute that changes
 * what is painted (stroke, transform, opacity, style, gradients, text, images,
 * use, clip paths, masks, arcs, evenodd) throws a
 * CardException with the line, so a logo never renders differently on the card
 * than in a browser without anyone noticing.
 *
 * `shape-rendering="crispEdges"` on the root snaps the scale to whole pixels per
 * user unit, so a pixel-art mark stays sharp.
 */
final class Svg
{
    /** Control point distance of a cubic Bézier quarter circle, per unit of radius. */
    private const KAPPA = 0.5522847498307936;

    /** Attributes that describe or identify but never change what is painted. */
    private const NEUTRAL = ['id', 'class', 'role', 'aria-label', 'aria-hidden', 'aria-labelledby', 'focusable', 'xmlns', 'xmlns:xlink', 'version', 'xml:space', 'data-name'];

    /** Painting attributes each element may carry, besides the neutral ones. */
    private const ALLOWED = [
        'svg' => ['viewbox', 'width', 'height', 'shape-rendering', 'fill', 'fill-rule', 'stroke', 'x', 'y', 'preserveaspectratio'],
        'g' => ['fill', 'fill-rule', 'stroke', 'shape-rendering'],
        'rect' => ['x', 'y', 'width', 'height', 'rx', 'ry', 'fill', 'fill-rule', 'stroke', 'shape-rendering'],
        'circle' => ['cx', 'cy', 'r', 'fill', 'fill-rule', 'stroke', 'shape-rendering'],
        'ellipse' => ['cx', 'cy', 'rx', 'ry', 'fill', 'fill-rule', 'stroke', 'shape-rendering'],
        'polygon' => ['points', 'fill', 'fill-rule', 'stroke', 'shape-rendering'],
        'path' => ['d', 'fill', 'fill-rule', 'stroke', 'shape-rendering'],
    ];

    /**
     * @param array{0:float, 1:float, 2:float, 3:float} $viewBox min-x, min-y, width, height
     * @param list<array{color: array{0:int,1:int,2:int}|null, contours: list<list<array<int, int|float>>>}> $shapes
     *        in user units; color null is currentColor
     */
    private function __construct(
        public readonly array $viewBox,
        public readonly bool $crisp,
        public readonly array $shapes,
    ) {
    }

    /** @throws CardException */
    public static function load(string $file): self
    {
        $source = @file_get_contents($file);
        if ($source === false) {
            throw new CardException('logo file not readable', $file);
        }

        return self::parse($source, $file);
    }

    /** @throws CardException */
    public static function parse(string $source, string $file = 'logo'): self
    {
        $fail = static fn(int $offset, string $message): CardException
            => new CardException('line ' . (substr_count($source, "\n", 0, $offset) + 1) . ': ' . $message, $file);

        // Comments, the XML declaration, a doctype and CDATA paint nothing.
        $text = (string) preg_replace_callback(
            '/<!--.*?-->|<\?.*?\?>|<!DOCTYPE[^>]*>/s',
            static fn(array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]),
            $source,
        );
        if (preg_match_all('/<(\/?)([A-Za-z][\w:.-]*)((?:\s+[\w:.-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'))*)\s*(\/?)>/s', $text, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            throw new CardException('no SVG element', $file);
        }

        $viewBox = null;
        $crisp = false;
        $shapes = [];
        // Inherited fill per open element; null is currentColor, false is none.
        $fills = [];
        $stack = [];
        $skipping = 0;
        $seenRoot = false;

        foreach ($tags as $tag) {
            $offset = $tag[0][1];
            $closing = $tag[1][0] === '/';
            $name = strtolower($tag[2][0]);
            $selfClosing = $tag[4][0] === '/';

            if ($closing) {
                if ($stack === [] || array_pop($stack) !== $name) {
                    throw $fail($offset, 'unexpected </' . $name . '>');
                }
                if (in_array($name, ['title', 'desc', 'metadata'], true)) {
                    $skipping--;
                } elseif ($skipping === 0) {
                    array_pop($fills);
                }
                continue;
            }
            if ($skipping > 0 || in_array($name, ['title', 'desc', 'metadata'], true)) {
                if (!$selfClosing) {
                    $stack[] = $name;
                    if (in_array($name, ['title', 'desc', 'metadata'], true)) {
                        $skipping++;
                    }
                }
                continue;
            }
            if (!isset(self::ALLOWED[$name])) {
                throw $fail($offset, '<' . $name . '> is not supported in a share card logo; use rect, circle, ellipse, polygon and path with solid fills');
            }
            if ($name === 'svg' && $seenRoot) {
                throw $fail($offset, 'nested <svg> is not supported');
            }
            if ($name !== 'svg' && !$seenRoot) {
                throw $fail($offset, 'expected <svg> as the root element');
            }

            $attrs = self::attributes($tag[3][0]);
            foreach ($attrs as $attr => $value) {
                if (!in_array($attr, self::NEUTRAL, true) && !in_array($attr, self::ALLOWED[$name], true)) {
                    throw $fail($offset, 'attribute ' . $attr . ' on <' . $name . '> is not supported in a share card logo');
                }
            }
            if (isset($attrs['stroke']) && $attrs['stroke'] !== 'none') {
                throw $fail($offset, 'strokes are not supported in a share card logo; convert them to filled outlines');
            }
            if (isset($attrs['fill-rule']) && $attrs['fill-rule'] !== 'nonzero') {
                throw $fail($offset, 'fill-rule="' . $attrs['fill-rule'] . '" is not supported; only nonzero');
            }

            $fill = $fills === [] ? [0, 0, 0] : $fills[count($fills) - 1];
            if (isset($attrs['fill'])) {
                $fill = self::fill($attrs['fill'], static fn(string $message): CardException => $fail($offset, $message));
            }

            if ($name === 'svg') {
                $seenRoot = true;
                $viewBox = self::viewBox($attrs, static fn(string $message): CardException => $fail($offset, $message));
                $crisp = ($attrs['shape-rendering'] ?? '') === 'crispEdges';
            } elseif ($name !== 'g') {
                $contours = match ($name) {
                    'rect' => self::rect($attrs, static fn(string $message): CardException => $fail($offset, $message)),
                    'circle', 'ellipse' => self::ellipse($name, $attrs, static fn(string $message): CardException => $fail($offset, $message)),
                    'polygon' => self::polygon($attrs['points'] ?? '', static fn(string $message): CardException => $fail($offset, $message)),
                    'path' => self::path($attrs['d'] ?? '', static fn(string $message): CardException => $fail($offset, $message)),
                };
                if ($fill !== false && $contours !== []) {
                    $shapes[] = ['color' => $fill, 'contours' => $contours];
                }
            }
            if (!$selfClosing) {
                $stack[] = $name;
                $fills[] = $fill;
            }
        }
        if ($stack !== []) {
            throw new CardException('unclosed <' . end($stack) . '>', $file);
        }
        if ($viewBox === null) {
            throw new CardException('no <svg> root element', $file);
        }
        if ($shapes === []) {
            throw new CardException('the logo paints nothing', $file);
        }

        return new self($viewBox, $crisp, $shapes);
    }

    /**
     * Size in pixels at a target height: width, height and the scale from user
     * units. A crisp logo snaps to whole pixels per unit, never below one.
     *
     * @return array{0:int, 1:int, 2:float}
     */
    public function size(float $height): array
    {
        $scale = $height / $this->viewBox[3];
        if ($this->crisp) {
            $scale = max(1.0, floor($scale));
        }

        return [(int) round($this->viewBox[2] * $scale), (int) round($this->viewBox[3] * $scale), $scale];
    }

    /**
     * Paint the logo with its top left corner at whole pixels ($x, $y), at a height of about $height pixels.
     *
     * @param array{0:int, 1:int, 2:int} $currentColor the colour of currentColor fills
     * @return array{0:int, 1:int} the painted width and height
     */
    public function paint(Canvas $canvas, int $x, int $y, float $height, array $currentColor): array
    {
        [$width, $painted, $scale] = $this->size($height);
        foreach ($this->shapes as $shape) {
            $mask = Raster::coverage(Raster::flatten(
                $shape['contours'],
                $scale,
                $scale,
                $x - $this->viewBox[0] * $scale,
                $y - $this->viewBox[1] * $scale,
            ));
            if ($mask !== null) {
                $canvas->paint($mask, $shape['color'] ?? $currentColor);
            }
        }

        return [$width, $painted];
    }

    // ------------------------------------------------------------------ parts

    /** @return array<string, string> lowercase attribute name => value with entities decoded */
    private static function attributes(string $source): array
    {
        preg_match_all('/([\w:.-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $source, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $attr) {
            $name = strtolower($attr[1]);
            $value = html_entity_decode($attr[2] !== '' ? $attr[2] : ($attr[3] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
            $out[$name] = $name === 'shape-rendering' && strtolower($value) === 'crispedges' ? 'crispEdges' : trim($value);
        }

        return $out;
    }

    /**
     * @param \Closure(string):CardException $fail
     * @return array{0:int,1:int,2:int}|null|false a colour, null for currentColor, false for none
     */
    private static function fill(string $value, \Closure $fail): array|null|false
    {
        $lower = strtolower($value);
        if ($lower === 'none' || $lower === 'transparent') {
            return false;
        }
        if ($lower === 'currentcolor') {
            return null;
        }
        $named = ['black' => '#000000', 'white' => '#ffffff'];
        try {
            return Color::parse($named[$lower] ?? $value);
        } catch (\InvalidArgumentException $e) {
            throw $fail('fill: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, string> $attrs
     * @param \Closure(string):CardException $fail
     * @return array{0:float, 1:float, 2:float, 3:float}
     */
    private static function viewBox(array $attrs, \Closure $fail): array
    {
        if (isset($attrs['viewbox'])) {
            $numbers = self::numbers($attrs['viewbox']);
            if (count($numbers) !== 4 || $numbers[2] <= 0 || $numbers[3] <= 0) {
                throw $fail('viewBox: expected "min-x min-y width height" with a positive size');
            }

            return [$numbers[0], $numbers[1], $numbers[2], $numbers[3]];
        }
        $width = self::length($attrs['width'] ?? '');
        $height = self::length($attrs['height'] ?? '');
        if ($width === null || $height === null || $width <= 0 || $height <= 0) {
            throw $fail('the root element needs a viewBox, or a width and a height in user units');
        }

        return [0.0, 0.0, $width, $height];
    }

    /**
     * @param array<string, string> $attrs
     * @param \Closure(string):CardException $fail
     * @return list<list<array<int, int|float>>>
     */
    private static function rect(array $attrs, \Closure $fail): array
    {
        $value = self::lengths('rect', $attrs, $fail);
        $x = $value('x', false);
        $y = $value('y', false);
        $w = $value('width', true);
        $h = $value('height', true);
        if ($w <= 0 || $h <= 0) {
            return [];
        }
        // As in SVG: a missing radius takes the other one, both are at most half the side.
        $rx = isset($attrs['rx']) ? $value('rx', false) : null;
        $ry = isset($attrs['ry']) ? $value('ry', false) : null;
        $rx = min(max(0.0, $rx ?? $ry ?? 0.0), $w / 2);
        $ry = min(max(0.0, $ry ?? $rx), $h / 2);
        if ($rx <= 0 || $ry <= 0) {
            return [[[0, $x, $y], [1, $x + $w, $y], [1, $x + $w, $y + $h], [1, $x, $y + $h]]];
        }
        // Quarter ellipses as cubic Béziers, clockwise from the top edge.
        $kx = $rx * self::KAPPA;
        $ky = $ry * self::KAPPA;
        $r = $x + $w;
        $b = $y + $h;

        return [[
            [0, $x + $rx, $y],
            [1, $r - $rx, $y],
            [3, $r - $rx + $kx, $y, $r, $y + $ry - $ky, $r, $y + $ry],
            [1, $r, $b - $ry],
            [3, $r, $b - $ry + $ky, $r - $rx + $kx, $b, $r - $rx, $b],
            [1, $x + $rx, $b],
            [3, $x + $rx - $kx, $b, $x, $b - $ry + $ky, $x, $b - $ry],
            [1, $x, $y + $ry],
            [3, $x, $y + $ry - $ky, $x + $rx - $kx, $y, $x + $rx, $y],
        ]];
    }

    /**
     * @param array<string, string> $attrs
     * @param \Closure(string):CardException $fail
     * @return list<list<array<int, int|float>>>
     */
    private static function ellipse(string $name, array $attrs, \Closure $fail): array
    {
        $value = self::lengths($name, $attrs, $fail);
        $cx = $value('cx', false);
        $cy = $value('cy', false);
        [$rx, $ry] = $name === 'circle' ? [$value('r', true), $value('r', true)] : [$value('rx', true), $value('ry', true)];
        if ($rx <= 0 || $ry <= 0) {
            return [];
        }
        $kx = $rx * self::KAPPA;
        $ky = $ry * self::KAPPA;

        return [[
            [0, $cx, $cy - $ry],
            [3, $cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy],
            [3, $cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry],
            [3, $cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy],
            [3, $cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry],
        ]];
    }

    /**
     * Reader of an element's length attributes in user units.
     *
     * @param array<string, string> $attrs
     * @param \Closure(string):CardException $fail
     * @return \Closure(string, bool):float key, required => value (0 when absent)
     */
    private static function lengths(string $element, array $attrs, \Closure $fail): \Closure
    {
        return static function (string $key, bool $required) use ($element, $attrs, $fail): float {
            if (!isset($attrs[$key])) {
                if ($required) {
                    throw $fail('<' . $element . '> needs ' . $key);
                }

                return 0.0;
            }
            $length = self::length($attrs[$key]);
            if ($length === null) {
                throw $fail($key . ': expected a number in user units, got "' . $attrs[$key] . '"');
            }

            return $length;
        };
    }

    /**
     * @param \Closure(string):CardException $fail
     * @return list<list<array<int, int|float>>>
     */
    private static function polygon(string $points, \Closure $fail): array
    {
        $numbers = self::numbers($points);
        if (count($numbers) < 6 || count($numbers) % 2 !== 0) {
            throw $fail('points: expected at least three x,y pairs');
        }
        $contour = [[0, $numbers[0], $numbers[1]]];
        for ($i = 2; $i < count($numbers); $i += 2) {
            $contour[] = [1, $numbers[$i], $numbers[$i + 1]];
        }

        return [$contour];
    }

    /**
     * Path data as contours, every subpath closed.
     *
     * @param \Closure(string):CardException $fail
     * @return list<list<array<int, int|float>>>
     */
    private static function path(string $d, \Closure $fail): array
    {
        if (preg_match_all('/([A-Za-z])|([+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?)|([\s,]+)|(.)/', $d, $tokens, PREG_SET_ORDER) === false) {
            throw $fail('d: unreadable path data');
        }
        $items = [];
        foreach ($tokens as $token) {
            if (($token[4] ?? '') !== '') {
                throw $fail('d: unexpected "' . $token[4] . '"');
            }
            if ($token[1] !== '') {
                if (stripos('MLHVCSQTZ', $token[1]) === false) {
                    throw $fail('d: the path command ' . $token[1] . ' is not supported' . (strtoupper($token[1]) === 'A' ? ' (arcs); convert arcs to curves' : ''));
                }
                $items[] = $token[1];
            } elseif (($token[2] ?? '') !== '') {
                $items[] = (float) $token[2];
            }
        }

        $arity = ['M' => 2, 'L' => 2, 'H' => 1, 'V' => 1, 'C' => 6, 'S' => 4, 'Q' => 4, 'T' => 2, 'Z' => 0];
        $contours = [];
        $contour = [];
        $x = $y = $startX = $startY = 0.0;
        // The last control point, for S and T.
        $lastCubic = $lastQuad = null;
        $command = null;
        $i = 0;
        $count = count($items);
        while ($i < $count) {
            if (is_string($items[$i])) {
                $command = $items[$i++];
            } elseif ($command === null) {
                throw $fail('d: path data must start with a moveto');
            }
            $upper = strtoupper($command);
            $relative = $command !== $upper;
            $n = $arity[$upper];
            if ($upper === 'Z') {
                if ($contour !== []) {
                    $contours[] = $contour;
                    $contour = [];
                }
                $x = $startX;
                $y = $startY;
                $lastCubic = $lastQuad = null;
                $command = null;
                continue;
            }
            $args = array_slice($items, $i, $n);
            if (count($args) !== $n || array_filter($args, 'is_string') !== []) {
                throw $fail('d: ' . $command . ' needs ' . $n . ' numbers');
            }
            $i += $n;
            $dx = $relative ? $x : 0.0;
            $dy = $relative ? $y : 0.0;
            $cubic = $quad = null;
            switch ($upper) {
                case 'M':
                    if ($contour !== []) {
                        $contours[] = $contour;
                    }
                    $x = $startX = $args[0] + $dx;
                    $y = $startY = $args[1] + $dy;
                    $contour = [[0, $x, $y]];
                    // Further pairs after a moveto are linetos.
                    $command = $relative ? 'l' : 'L';
                    break;
                case 'L':
                    $x = $args[0] + $dx;
                    $y = $args[1] + $dy;
                    $contour[] = [1, $x, $y];
                    break;
                case 'H':
                    $x = $args[0] + $dx;
                    $contour[] = [1, $x, $y];
                    break;
                case 'V':
                    $y = $args[0] + $dy;
                    $contour[] = [1, $x, $y];
                    break;
                case 'C':
                case 'S':
                    if ($upper === 'C') {
                        [$c1x, $c1y] = [$args[0] + $dx, $args[1] + $dy];
                        $rest = array_slice($args, 2);
                    } else {
                        [$c1x, $c1y] = $lastCubic === null ? [$x, $y] : [2 * $x - $lastCubic[0], 2 * $y - $lastCubic[1]];
                        $rest = $args;
                    }
                    $cubic = [$rest[0] + $dx, $rest[1] + $dy];
                    $x = $rest[2] + $dx;
                    $y = $rest[3] + $dy;
                    $contour[] = [3, $c1x, $c1y, $cubic[0], $cubic[1], $x, $y];
                    break;
                case 'Q':
                case 'T':
                    if ($upper === 'Q') {
                        $quad = [$args[0] + $dx, $args[1] + $dy];
                        [$ex, $ey] = [$args[2] + $dx, $args[3] + $dy];
                    } else {
                        $quad = $lastQuad === null ? [$x, $y] : [2 * $x - $lastQuad[0], 2 * $y - $lastQuad[1]];
                        [$ex, $ey] = [$args[0] + $dx, $args[1] + $dy];
                    }
                    $x = $ex;
                    $y = $ey;
                    $contour[] = [2, $quad[0], $quad[1], $x, $y];
                    break;
            }
            if ($contour === []) {
                throw $fail('d: path data must start with a moveto');
            }
            $lastCubic = $cubic;
            $lastQuad = $quad;
        }
        if ($contour !== []) {
            $contours[] = $contour;
        }

        return array_values(array_filter($contours, static fn(array $c): bool => count($c) > 1));
    }

    /** @return list<float> */
    private static function numbers(string $list): array
    {
        preg_match_all('/[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/', $list, $m);

        return array_map('floatval', $m[0]);
    }

    /** A length in user units: a plain number or a number with "px". */
    private static function length(string $value): ?float
    {
        return preg_match('/^\s*([+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?)(?:px)?\s*$/', $value, $m) === 1 ? (float) $m[1] : null;
    }
}
