<?php

declare(strict_types=1);

require __DIR__ . '/run.php';
require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Fs.php';
foreach (['CardException', 'TrueType', 'Raster', 'Canvas', 'Png', 'Color', 'Svg', 'Text', 'Layout'] as $part) {
    require_once __DIR__ . '/../src/lib/Card/' . $part . '.php';
}

use Pholio\Card\Canvas;
use Pholio\Card\CardException;
use Pholio\Card\Color;
use Pholio\Card\Layout;
use Pholio\Card\Png;
use Pholio\Card\Raster;
use Pholio\Card\Svg;
use Pholio\Card\Text;
use Pholio\Card\TrueType;
use Pholio\Config;
use Pholio\ConfigException;
use Pholio\Fs;

/**
 * Share cards: the TrueType reader, the rasterizer, the PNG writer, colours,
 * the SVG logo subset, the layout, the `share_card` configuration, and full
 * builds with their Open Graph tags and card files.
 */

const CARD_INTER = __DIR__ . '/../vendor-data/inter';

// ------------------------------------------------------------------ helpers

/**
 * A TrueType font of three glyphs, built byte by byte: .notdef, "A" a 500-unit
 * square, "B" a composite of "A" moved 100 units right. cmap format 4 maps
 * A and B; GPOS kerns A followed by B by -80 (pair format 1), and B followed
 * by A by -30 through a class pair (format 2) behind an extension lookup.
 */
function card_font(): string
{
    $u16 = static fn(int ...$v): string => pack('n*', ...array_map(static fn(int $x): int => $x & 0xffff, $v));
    $u32 = static fn(int $v): string => pack('N', $v);

    $head = str_repeat("\0", 54);
    $head = substr_replace($head, $u16(1000), 18, 2);          // unitsPerEm
    $head = substr_replace($head, $u16(0), 50, 2);             // short loca
    $hhea = substr_replace(substr_replace(substr_replace(substr_replace(str_repeat("\0", 36), $u16(800), 4, 2), $u16(-200), 6, 2), $u16(0), 8, 2), $u16(3), 34, 2);
    $maxp = $u32(0x00005000) . $u16(3);

    // cmap: one format 4 subtable, segments A–B and the 0xFFFF terminator.
    $format4 = $u16(4, 32, 0, 4, 4, 1, 0)
        . $u16(0x42, 0xffff) . $u16(0) . $u16(0x41, 0xffff) . $u16(1 - 0x41, 1) . $u16(0, 0);
    $cmap = $u16(0, 1) . $u16(3, 1) . $u32(12) . $format4;

    $hmtx = $u16(500, 0) . $u16(600, 0) . $u16(700, 100);

    // glyf: A is four on-curve points; B is A with offset (100, 0).
    $simple = $u16(1, 0, 0, 500, 500) . $u16(3) . $u16(0) . "\x01\x01\x01\x01"
        . $u16(0, 500, 0, -500) . $u16(0, 0, 500, 0);
    $composite = $u16(-1, 100, 0, 600, 500) . $u16(0x0003, 1) . $u16(100, 0);
    $glyf = $simple . $composite;
    $loca = $u16(0, 0, strlen($simple) / 2, strlen($glyf) / 2);

    // GPOS: ScriptList (empty), FeatureList with one `kern` feature on lookups 0 and 1, LookupList.
    $scriptList = $u16(0);
    $feature = $u16(0, 2, 0, 1);
    $featureList = $u16(1) . 'kern' . $u16(8) . $feature;
    // Lookup 0: PairPos format 1, A (glyph 1) then B (glyph 2): XAdvance -80.
    $coverageA = $u16(1, 1, 1);
    $pairSet = $u16(1, 2, -80);
    $pair1 = $u16(1, 12, 0x0004, 0, 1, 12 + strlen($coverageA)) . $coverageA . $pairSet;
    $lookup0 = $u16(2, 0, 1, 8) . $pair1;
    // Lookup 1: extension (type 9) to PairPos format 2, class 1 = {B}, class 1 = {A}: XAdvance -30.
    $coverageB = $u16(1, 1, 2);
    $classDef1 = $u16(1, 2, 1, 1);      // format 1, start glyph 2, one glyph, class 1
    $classDef2 = $u16(1, 1, 1, 1);      // format 1, start glyph 1, one glyph, class 1
    $records = $u16(0, 0, 0, -30);      // class1 0: [0, 0]; class1 1: [0, -30]
    $pair2Head = 16;
    $pair2 = $u16(2, $pair2Head + strlen($records), 0x0004, 0, $pair2Head + strlen($records) + strlen($coverageB), $pair2Head + strlen($records) + strlen($coverageB) + strlen($classDef1), 2, 2)
        . $records . $coverageB . $classDef1 . $classDef2;
    $extension = $u16(1, 2) . $u32(8) . $pair2;
    $lookup1 = $u16(9, 0, 1, 8) . $extension;
    $lookupList = $u16(2, 6, 6 + strlen($lookup0)) . $lookup0 . $lookup1;
    $gpos = $u16(1, 0, 10, 10 + strlen($scriptList), 10 + strlen($scriptList) + strlen($featureList))
        . $scriptList . $featureList . $lookupList;

    $tables = ['GPOS' => $gpos, 'cmap' => $cmap, 'glyf' => $glyf, 'head' => $head, 'hhea' => $hhea, 'hmtx' => $hmtx, 'loca' => $loca, 'maxp' => $maxp];
    $offset = 12 + 16 * count($tables);
    $directory = '';
    $body = '';
    foreach ($tables as $tag => $data) {
        $data .= str_repeat("\0", (4 - strlen($data) % 4) % 4);
        $directory .= $tag . $u32(0) . $u32($offset + strlen($body)) . $u32(strlen($data));
        $body .= $data;
    }

    return $u32(0x00010000) . $u16(count($tables), 128, 3, 0) . $directory . $body;
}

/** @return array{0:int, 1:int} width and height from a PNG's IHDR */
function card_png_size(string $png): array
{
    assert_same("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
    assert_same('IHDR', substr($png, 12, 4));
    $ihdr = unpack('Nwidth/Nheight/Cdepth/Ctype', substr($png, 16, 10));

    return [$ihdr['width'], $ihdr['height']];
}

function card_temp(): string
{
    $dir = Fs::tempDir('pholio-card-test-');
    register_shutdown_function(static fn() => Fs::removeDir($dir));

    return $dir;
}

/** @return array{0:int, 1:string} exit code, stdout and stderr */
function card_cli(array $args): array
{
    $log = tempnam(sys_get_temp_dir(), 'pholio-card-cli-');
    $process = proc_open(
        array_merge([PHP_BINARY, __DIR__ . '/../bin/pholio'], $args),
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
        $pipes,
    );
    $code = proc_close($process);
    $output = (string) file_get_contents($log);
    unlink($log);

    return [$code, $output];
}

/**
 * A site with the given pages (path => Markdown) and config (PHP source of the
 * array's extra entries), built; returns the project directory.
 *
 * @param array<string, string> $pages
 * @param array<string, string> $files other files, path => contents
 */
function card_site(array $pages, string $config = '', array $files = [], int $expect = 0, ?string &$err = null): string
{
    $dir = card_temp();
    foreach ($pages + $files as $path => $contents) {
        Fs::writeBytes($dir, $path, $contents);
    }
    file_put_contents($dir . '/pholio.config.php', "<?php\nreturn ['title' => 'Lanternfly', 'content_dir' => 'content', 'output_dir' => 'out', " . $config . "];\n");
    [$code, $err] = card_cli(['build', '--config', $dir . '/pholio.config.php', '--quiet']);
    assert_same($expect, $code, $err);

    return $dir;
}

/** @return array<string, string> meta property/name => content */
function card_meta(string $html): array
{
    preg_match_all('/<meta (?:property|name)="((?:og|twitter):[^"]+)" content="([^"]*)">/', $html, $matches, PREG_SET_ORDER);
    $out = [];
    foreach ($matches as [, $name, $content]) {
        $out[$name] = html_entity_decode($content, ENT_QUOTES | ENT_HTML5);
    }

    return $out;
}

/** @return array<string, mixed> */
function card_config(array $raw): array
{
    return Config::fromArray($raw + ['title' => 'Lanternfly', 'content_dir' => 'content', 'output_dir' => 'out'], '/site', '/site/pholio.config.php');
}

// ------------------------------------------------------------------ TrueType

test('TrueType: cmap format 4, metrics, simple and composite outlines', function (): void {
    $font = TrueType::fromString(card_font(), 'test.ttf');
    assert_same(1000, $font->unitsPerEm);
    assert_same(800, $font->ascender);
    assert_same(-200, $font->descender);
    assert_same(3, $font->numGlyphs);
    assert_same(1, $font->glyph(0x41));
    assert_same(2, $font->glyph(0x42));
    assert_same(0, $font->glyph(0x43));
    assert_same(0, $font->glyph(0x1F600));
    assert_same(600, $font->advance(1));
    assert_same(700, $font->advance(2));
    assert_same([[[0, 0, 0], [1, 500, 0], [1, 500, 500], [1, 0, 500], [1, 0, 0]]], $font->outline(1));
    assert_same([[[0, 100, 0], [1, 600, 0], [1, 600, 500], [1, 100, 500], [1, 100, 0]]], $font->outline(2));
    assert_same([], $font->outline(0));
    assert_throws(CardException::class, fn() => $font->outline(3), 'glyph 3');
});

test('TrueType: GPOS pair kerning, format 1 and format 2 behind an extension', function (): void {
    $font = TrueType::fromString(card_font(), 'test.ttf');
    assert_same(-80, $font->kern(1, 2));
    assert_same(-30, $font->kern(2, 1));
    assert_same(0, $font->kern(1, 1));
    assert_same(0, $font->kern(0, 2));
});

test('TrueType: what is not a TrueType font with glyf outlines is refused', function (): void {
    $cases = [
        'wOF2' . str_repeat("\0", 40) => 'woff2 web font',
        'wOFF' . str_repeat("\0", 40) => 'woff web font',
        'OTTO' . str_repeat("\0", 40) => 'CFF outlines',
        'ttcf' . str_repeat("\0", 40) => 'font collection',
        '<svg xmlns="http://www.w3.org/2000/svg"/>' => 'not a TrueType font',
        'abc' => 'too short',
    ];
    foreach ($cases as $data => $message) {
        $e = assert_throws(CardException::class, fn() => TrueType::fromString($data, 'x.ttf'), $message);
        assert_same('x.ttf', $e->source);
    }
    // Without glyf and loca: the directory names only the other tables.
    $font = card_font();
    $font = str_replace(['glyf', 'loca'], ['gly_', 'loc_'], substr($font, 0, 12 + 16 * 8)) . substr($font, 12 + 16 * 8);
    assert_throws(CardException::class, fn() => TrueType::fromString($font, 'x.ttf'), 'no glyf outlines');
    // A table that claims to run past the end of the file.
    assert_throws(CardException::class, fn() => TrueType::fromString(substr(card_font(), 0, 400), 'x.ttf'), 'runs past the end');
    assert_throws(CardException::class, fn() => TrueType::load('/nonexistent/font.ttf'), 'not readable');
});

test('TrueType: the bundled Inter has cmap 12, kerning, composites and German letters', function (): void {
    $font = TrueType::load(CARD_INTER . '/Inter-Regular.ttf');
    assert_same(2048, $font->unitsPerEm);
    foreach (['A', 'V', 'ä', 'Ö', 'ß', '…', '–', '€'] as $letter) {
        assert_true($font->glyph(mb_ord($letter)) !== 0, $letter);
    }
    assert_same(0, $font->glyph(0x6F22));
    assert_true($font->kern($font->glyph(0x41), $font->glyph(0x56)) < 0, 'A V kerns closer');
    assert_true(count($font->outline($font->glyph(mb_ord('ä')))) > count($font->outline($font->glyph(mb_ord('a')))), 'ä has the dots of a composite');
    TrueType::load(CARD_INTER . '/Inter-SemiBold.ttf');
});

// ------------------------------------------------------------------ rasterizer and canvas

test('Raster: a pixel-aligned square covers whole pixels, a half-shifted one half', function (): void {
    $square = [[[0, 0, 0], [1, 10, 0], [1, 10, 10], [1, 0, 10]]];
    $mask = Raster::coverage(Raster::flatten($square, 1.0, 1.0, 0.0, 0.0));
    assert_same(['x' => 0, 'y' => 0, 'w' => 10, 'h' => 10], array_diff_key($mask, ['alpha' => 0]));
    assert_same(str_repeat("\xff", 100), $mask['alpha']);

    $shifted = Raster::coverage(Raster::flatten($square, 1.0, 1.0, 0.5, 0.0));
    assert_same(11, $shifted['w']);
    $row = array_values(unpack('C*', substr($shifted['alpha'], 0, 11)));
    assert_true(abs($row[0] - 128) <= 1 && abs($row[10] - 128) <= 1, 'half-covered edges: ' . implode(',', $row));
    assert_same(array_fill(1, 9, 255), array_slice($row, 1, 9, true));

    // A triangle covers half its bounding box; winding direction does not matter (nonzero, absolute).
    $triangle = [[[0, 0, 0], [1, 20, 0], [1, 0, 20]]];
    foreach ([$triangle, [array_reverse($triangle[0])]] as $shape) {
        $shape[0][0][0] = 0;
        foreach (array_slice($shape[0], 1) as $i => $op) {
            $shape[0][$i + 1][0] = 1;
        }
        $m = Raster::coverage(Raster::flatten($shape, 1.0, 1.0, 0.0, 0.0));
        $sum = array_sum(unpack('C*', $m['alpha']));
        assert_true(abs($sum / 255 - 200) < 2, 'area ' . $sum / 255);
    }
    assert_same(null, Raster::coverage([]));
});

test('Raster: curves are flattened within a tenth of a pixel', function (): void {
    // A quadratic and a cubic from (0, 0) to (100, 0) bulging up, closed by the baseline.
    $quad = Raster::flatten([[[0, 0, 0], [2, 50, 100, 100, 0]]], 1.0, 1.0, 0.0, 0.0);
    $cubic = Raster::flatten([[[0, 0, 0], [3, 0, 100, 100, 100, 100, 0]]], 1.0, 1.0, 0.0, 0.0);
    $area = static function (array $polygons): float {
        $sum = 0.0;
        foreach ($polygons as $points) {
            for ($i = 0, $n = count($points); $i < $n; $i++) {
                [$x1, $y1] = $points[$i];
                [$x2, $y2] = $points[($i + 1) % $n];
                $sum += $x1 * $y2 - $x2 * $y1;
            }
        }

        return abs($sum) / 2;
    };
    // Exact areas: 2/3 · 100 · 50 for the quadratic, 3/5 · 100 · 100 for the cubic. Chords
    // within 0.1 px of a 250 px curve lose at most about 25 square pixels.
    assert_true(abs($area($quad) - 10000 / 3) < 25, 'quadratic area ' . $area($quad));
    assert_true(abs($area($cubic) - 6000) < 25, 'cubic area ' . $area($cubic));
});

test('Canvas: painting blends, clips at the edges and encodes to PNG', function (): void {
    $canvas = new Canvas(4, 2, [255, 255, 255]);
    $canvas->paint(['x' => 0, 'y' => 0, 'w' => 2, 'h' => 1, 'alpha' => "\xff\x80"], [0, 0, 0], 1, 0);
    assert_same([255, 255, 255], $canvas->pixel(0, 0));
    assert_same([0, 0, 0], $canvas->pixel(1, 0));
    assert_same([127, 127, 127], $canvas->pixel(2, 0));
    // Clipped: a mask hanging over every edge paints only the inside.
    $canvas->paint(['x' => -1, 'y' => -1, 'w' => 6, 'h' => 4, 'alpha' => str_repeat("\xff", 24)], [10, 20, 30]);
    assert_same([10, 20, 30], $canvas->pixel(3, 1));
    assert_same([4, 2], card_png_size($canvas->png()));
});

// ------------------------------------------------------------------ PNG

test('Png: valid chunks, CRCs and image data, compared by pixels', function (): void {
    $rgb = "\xff\x00\x00" . "\x00\xff\x00" . "\x00\x00\xff" . "\xff\xff\xff";
    $png = Png::encode(2, 2, $rgb);
    assert_same([2, 2], card_png_size($png));
    $ihdr = unpack('Cdepth/Ctype/Ccompression/Cfilter/Cinterlace', substr($png, 24, 5));
    assert_same(['depth' => 8, 'type' => 2, 'compression' => 0, 'filter' => 0, 'interlace' => 0], $ihdr);

    // Every chunk's CRC covers its type and data; IDAT inflates to filter byte 0 plus the row.
    $offset = 8;
    $types = [];
    while ($offset < strlen($png)) {
        $length = unpack('N', substr($png, $offset, 4))[1];
        $type = substr($png, $offset + 4, 4);
        $data = substr($png, $offset + 8, $length);
        assert_same(crc32($type . $data), unpack('N', substr($png, $offset + 8 + $length, 4))[1], $type);
        if ($type === 'IDAT') {
            assert_same("\0" . substr($rgb, 0, 6) . "\0" . substr($rgb, 6), gzuncompress($data));
        }
        $types[] = $type;
        $offset += 12 + $length;
    }
    assert_same(['IHDR', 'IDAT', 'IEND'], $types);
    assert_same($png, Png::encode(2, 2, $rgb), 'deterministic');

    // The same pixels compressed differently are the same image; one other pixel is not.
    $recompressed = substr($png, 0, 33) . (static function () use ($rgb): string {
        $data = gzcompress("\0" . substr($rgb, 0, 6) . "\0" . substr($rgb, 6), 1);
        $split = intdiv(strlen($data), 2);
        $chunk = static fn(string $d): string => pack('N', strlen($d)) . 'IDAT' . $d . pack('N', crc32('IDAT' . $d));

        return $chunk(substr($data, 0, $split)) . $chunk(substr($data, $split));
    })() . substr($png, -12);
    assert_true($recompressed !== $png);
    assert_true(Png::sameImage($png, $recompressed), 'split and recompressed IDAT');
    assert_true(!Png::sameImage($png, Png::encode(2, 2, "\xfe" . substr($rgb, 1))), 'one channel differs');
    assert_true(!Png::sameImage($png, Png::encode(4, 1, $rgb)), 'other dimensions');

    // A broken CRC or a file that is not a PNG is the same only byte for byte.
    $broken = substr($png, 0, 29) . "\0\0\0\0" . substr($png, 33);
    assert_same(null, Png::canonical($broken));
    assert_true(Png::sameImage($broken, $broken));
    assert_true(!Png::sameImage($broken, $png));
    assert_same(null, Png::canonical('GIF89a'));
});

// ------------------------------------------------------------------ colours

test('Color: hex, rgb() and hsl() parse, alpha and other forms are refused', function (): void {
    assert_same([242, 84, 27], Color::parse('#f2541b'));
    assert_same([255, 0, 170], Color::parse('#F0A'));
    assert_same([10, 20, 30], Color::parse('rgb(10, 20, 30)'));
    assert_same([10, 20, 30], Color::parse('rgb(10 20 30)'));
    assert_same([255, 128, 0], Color::parse('rgb(100% 50% 0%)'));
    assert_same([255, 0, 0], Color::parse('hsl(0 100% 50%)'));
    assert_same([0, 0, 255], Color::parse('hsl(240, 100%, 50%)'));
    assert_same([128, 128, 128], Color::parse('hsl(0deg 0% 50.2%)'));
    assert_same('#f2541b', Color::hex([242, 84, 27]));
    foreach (['#f2541b80', 'rgb(1 2 3 / .5)', 'oklch(0.7 0.2 40)', 'var(--x)', 'orange', '', 'hsl(10% 50% 50%)'] as $bad) {
        assert_throws(\InvalidArgumentException::class, fn() => Color::parse($bad), 'expected');
    }
});

// ------------------------------------------------------------------ SVG logos

test('Svg: filled rects, circles, polygons and paths; crisp logos snap to whole pixels', function (): void {
    $svg = Svg::parse('<svg viewBox="0 0 16 16" shape-rendering="crispEdges"><title>Mark</title>'
        . '<rect x="0" y="0" width="8" height="16" fill="#f2541b"/><rect x="8" width="8" height="16" fill="currentColor"/></svg>');
    assert_same([48, 48, 3.0], $svg->size(50.0));
    assert_same([16, 16, 1.0], $svg->size(10.0), 'never below one pixel per unit');
    $canvas = new Canvas(60, 60, [255, 255, 255]);
    assert_same([48, 48], $svg->paint($canvas, 4, 4, 50.0, [1, 2, 3]));
    assert_same([242, 84, 27], $canvas->pixel(4, 4));
    assert_same([242, 84, 27], $canvas->pixel(27, 51));
    assert_same([1, 2, 3], $canvas->pixel(28, 4));
    assert_same([255, 255, 255], $canvas->pixel(52, 4));

    $round = Svg::parse('<svg width="64" height="64"><g fill="#00f"><rect width="64" height="64" rx="16"/>'
        . '<circle cx="32" cy="32" r="6" fill="#fff"/><ellipse cx="10" cy="10" rx="2" ry="1" fill="none"/>'
        . '<polygon points="0,0 4,0 4,4"/><path d="M1 1h2v2H1zm10 0l2 2q1 1 2 0t2 0c1 1 2 1 3 0s1 -1 2 0Z"/></g></svg>');
    $canvas = new Canvas(64, 64, [255, 0, 0]);
    $round->paint($canvas, 0, 0, 64.0, [0, 0, 0]);
    assert_same([255, 0, 0], $canvas->pixel(0, 63), 'rounded corner stays background');
    assert_same([0, 0, 255], $canvas->pixel(32, 2));
    assert_same([255, 255, 255], $canvas->pixel(32, 32));
});

test('Svg: anything that would paint differently fails with its line', function (): void {
    $cases = [
        '<svg viewBox="0 0 1 1">' . "\n" . '<rect width="1" height="1" stroke="#000"/></svg>' => 'line 2: strokes are not supported',
        '<svg viewBox="0 0 1 1"><rect width="1" height="1" transform="scale(2)"/></svg>' => 'attribute transform on <rect>',
        '<svg viewBox="0 0 1 1"><rect width="1" height="1" style="fill:red"/></svg>' => 'attribute style',
        '<svg viewBox="0 0 1 1"><rect width="1" height="1" opacity=".5"/></svg>' => 'attribute opacity',
        '<svg viewBox="0 0 1 1"><text>x</text></svg>' => '<text> is not supported',
        '<svg viewBox="0 0 1 1"><linearGradient/></svg>' => 'not supported',
        '<svg viewBox="0 0 1 1"><path d="M0 0A1 1 0 0 1 1 1z"/></svg>' => 'arc',
        '<svg viewBox="0 0 1 1"><path d="M0 0L1 1z" fill-rule="evenodd"/></svg>' => 'fill-rule="evenodd"',
        '<svg viewBox="0 0 1 1"><rect width="1" height="1" fill="url(#g)"/></svg>' => 'url(#g)',
        '<svg viewBox="0 0 1 1"><rect width="1" height="1" fill="#0008"/></svg>' => '#0008',
        '<svg viewBox="0 0 1 1"><rect width="1" height="1" fill="none"/></svg>' => 'paints nothing',
        '<svg viewBox="0 0 1 1"><rect width="1"/></svg>' => '<rect> needs height',
        '<svg viewBox="0 0 1 1"><g><rect width="1" height="1"/></svg>' => 'line 1: unexpected </svg>',
        '<svg viewBox="0 0 1 1"><g><rect width="1" height="1"/>' => 'unclosed <g>',
        '<svg><rect width="1" height="1"/></svg>' => 'needs a viewBox',
        '<rect width="1" height="1"/>' => 'expected <svg> as the root',
    ];
    foreach ($cases as $source => $message) {
        assert_throws(CardException::class, fn() => Svg::parse($source, 'logo.svg'), $message);
    }
});

// ------------------------------------------------------------------ layout

test('Layout: titles shrink to fit three lines, descriptions end in an ellipsis', function (): void {
    $style = [
        'brand' => 'Lanternfly',
        'logo' => Svg::parse('<svg viewBox="0 0 2 2"><rect width="2" height="2" fill="#4f5bd5"/></svg>'),
        'heading' => new Text(TrueType::load(CARD_INTER . '/Inter-SemiBold.ttf')),
        'text' => new Text(TrueType::load(CARD_INTER . '/Inter-Regular.ttf')),
        'colors' => ['background' => [255, 255, 255], 'foreground' => [10, 10, 10], 'muted' => [115, 115, 115], 'accent' => [79, 91, 213]],
    ];
    $heading = $style['heading'];
    $width = Layout::WIDTH - 160;
    $short = $heading->wrap('Installation', 72.0, $width, 3, -0.02);
    assert_same(['lines' => ['Installation'], 'broken' => false, 'truncated' => false], $short);

    $long = str_repeat('Configuring the share cards of a documentation site ', 4);
    $wrapped = $heading->wrap($long, 40.0, $width, 3, -0.02);
    assert_same(3, count($wrapped['lines']));
    assert_true($wrapped['truncated'] && str_ends_with($wrapped['lines'][2], Text::ELLIPSIS));
    foreach ($wrapped['lines'] as $line) {
        assert_true($heading->width($line, 40.0, -0.02) <= $width, $line);
    }
    $word = $heading->wrap(str_repeat('Donaudampfschifffahrt', 4), 72.0, $width, 3, -0.02);
    assert_true($word['broken'], 'an overlong word breaks between letters');

    foreach ([
        Layout::page($style, 'Installation', 'Install it and check that it runs.', 'lanternfly.example/guide/installation'),
        Layout::page($style, $long, str_repeat('A very long description. ', 20), 'lanternfly.example/a/b/c'),
        Layout::page($style, 'Übergrößenträger', null, 'lanternfly.example/de'),
        Layout::site($style, 'Lanternfly makes local notes searchable in a second.', 'lanternfly.example'),
        Layout::site($style, null, 'lanternfly.example'),
    ] as $canvas) {
        assert_same([1200, 630], [$canvas->width, $canvas->height]);
        assert_same([79, 91, 213], $canvas->pixel(600, 629), 'accent bar');
        assert_same([255, 255, 255], $canvas->pixel(1199, 0));
        assert_same([1200, 630], card_png_size($canvas->png()));
    }
    // The logo sits at the top left, inside the margin.
    $page = Layout::page($style, 'Installation', null, 'lanternfly.example/x');
    assert_same([79, 91, 213], $page->pixel(100, 90));
    assert_same([255, 255, 255], $page->pixel(40, 40));
});

// ------------------------------------------------------------------ configuration

test('config: cards are on with site.url and resolve logo, brand, fonts and colours', function (): void {
    assert_same(null, card_config([])['shareCard']);
    assert_same(null, card_config(['site' => ['url' => 'https://example.org'], 'share_card' => ['enabled' => false]])['shareCard']);

    $c = card_config(['site' => ['url' => 'https://example.org'], 'logo' => '/assets/logo.svg', 'theme' => ['light' => ['primary' => 'hsl(240 100% 50%)']]])['shareCard'];
    assert_same('/site/assets/logo.svg', $c['logo']);
    assert_same('logo', $c['logoKey']);
    assert_same('Lanternfly', $c['brand']);
    assert_same(realpath(CARD_INTER . '/Inter-Regular.ttf'), realpath($c['fonts']['text']));
    assert_same(realpath(CARD_INTER . '/Inter-SemiBold.ttf'), realpath($c['fonts']['heading']));
    assert_same(['background' => [245, 245, 245], 'foreground' => [10, 10, 10], 'muted' => [115, 115, 115], 'accent' => [0, 0, 255]], $c['colors']);

    $c = card_config([
        'site' => ['url' => 'https://example.org'],
        'logo' => '/assets/logo.png',
        'share_card' => [
            'logo' => 'brand/mark.svg', 'brand' => '{site} Docs',
            'fonts' => ['text' => 'fonts/Text.ttf', 'heading' => '/abs/Heading.ttf'],
            'colors' => ['background' => '#fff', 'foreground' => 'rgb(0 0 0)', 'muted' => '#666', 'accent' => '#f2541b'],
        ],
    ])['shareCard'];
    assert_same('/site/brand/mark.svg', $c['logo']);
    assert_same('share_card.logo', $c['logoKey']);
    assert_same('Lanternfly Docs', $c['brand']);
    assert_same(['text' => '/site/fonts/Text.ttf', 'heading' => '/abs/Heading.ttf'], $c['fonts']);
    assert_same([242, 84, 27], $c['colors']['accent']);

    assert_same(null, card_config(['site' => ['url' => 'https://example.org'], 'logo' => '/assets/logo.png', 'share_card' => ['logo' => false]])['shareCard']['logo']);
    assert_same(null, card_config(['site' => ['url' => 'https://example.org']])['shareCard']['logo'], 'no logo at all');
});

test('config: share_card errors name the key', function (): void {
    $url = ['site' => ['url' => 'https://example.org']];
    $cases = [
        [['share_card' => ['enabled' => true]], 'share_card.enabled: share cards need site.url'],
        [['share_card' => ['enabled' => 'yes']], 'share_card.enabled: expected true, false or null'],
        [$url + ['share_card' => ['logo' => true]], 'share_card.logo: expected a string, false or null'],
        [$url + ['share_card' => ['logo' => 'mark.png']], 'share_card.logo: "/site/mark.png" is not an SVG file'],
        [$url + ['logo' => '/assets/logo.png'], 'logo: "/site/assets/logo.png" is not an SVG file'],
        [$url + ['logo' => 'https://cdn.example.org/logo.svg'], 'share_card.logo: the header logo "https://cdn.example.org/logo.svg" is not in a copy directory'],
        [$url + ['share_card' => ['colors' => ['accent' => 'orange']]], 'share_card.colors.accent: expected an opaque colour'],
        [$url + ['theme' => ['light' => ['background' => 'oklch(1 0 0)']]], 'share_card.colors.background: taken from theme.light.background'],
        [$url + ['share_card' => ['fonts' => ['serif' => 'x.ttf']]], 'unknown key: share_card.fonts.serif'],
    ];
    foreach ($cases as [$raw, $message]) {
        $e = assert_throws(ConfigException::class, fn() => card_config($raw), $message);
        assert_same(2, $e->exitCode());
    }
});

test('config: the default colours are the light scheme of theme/css/tokens.css', function (): void {
    $css = (string) file_get_contents(__DIR__ . '/../theme/css/tokens.css');
    foreach (Config::LIGHT_DEFAULTS as $token => $value) {
        preg_match('/^\s*--color-fd-' . preg_quote($token, '/') . ':\s*([^;]+);/m', $css, $m);
        assert_true(isset($m[1]), '--' . $token . ' in tokens.css');
        assert_same(Color::parse($value), Color::parse(trim($m[1])), '--' . $token);
    }
});

// ------------------------------------------------------------------ builds

test('build: one card per page, the site card, and the tags that point to them', function (): void {
    $dir = card_site([
        'content/index.md' => "---\ntitle: Overview\ndescription: What Lanternfly is.\n---\n\nText\n",
        'content/guide/install.md' => "---\ntitle: Install \"Lanternfly\" & run it\ndescription: Install it.\n---\n\nText\n",
        'content/guide/no-description.md' => "---\ntitle: Bare\n---\n\nText\n",
    ], "'site' => ['url' => 'https://docs.example.org', 'description' => 'Lanternfly makes notes searchable.'], 'logo' => '/assets/logo.svg'", [
        'assets/logo.svg' => '<svg viewBox="0 0 2 2"><rect width="2" height="2" fill="#4f5bd5"/></svg>',
    ]);
    $out = $dir . '/out';
    foreach (['og.png', 'og/guide/install.png', 'og/guide/no-description.png'] as $png) {
        assert_same([1200, 630], card_png_size((string) file_get_contents($out . '/' . $png)), $png);
    }
    assert_true(!is_dir($out . '/og/index'), 'the docs root at the start page uses og.png');

    $root = card_meta((string) file_get_contents($out . '/index.html'));
    assert_same('website', $root['og:type']);
    assert_same('Lanternfly', $root['og:title'], 'the site name, not the page title "Overview"');
    assert_same('What Lanternfly is.', $root['og:description']);
    assert_same('https://docs.example.org/og.png', $root['og:image']);
    assert_same('https://docs.example.org/', $root['og:url']);

    $meta = card_meta((string) file_get_contents($out . '/guide/install/index.html'));
    assert_same([
        'og:type' => 'article',
        'og:site_name' => 'Lanternfly',
        'og:title' => 'Install "Lanternfly" & run it',
        'og:description' => 'Install it.',
        'og:url' => 'https://docs.example.org/guide/install',
        'og:image' => 'https://docs.example.org/og/guide/install.png',
        'og:image:type' => 'image/png',
        'og:image:width' => '1200',
        'og:image:height' => '630',
        'og:image:alt' => 'Lanternfly: Install "Lanternfly" & run it',
        'twitter:card' => 'summary_large_image',
        'twitter:title' => 'Install "Lanternfly" & run it',
        'twitter:description' => 'Install it.',
        'twitter:image' => 'https://docs.example.org/og/guide/install.png',
        'twitter:image:alt' => 'Lanternfly: Install "Lanternfly" & run it',
    ], $meta);
    $bare = card_meta((string) file_get_contents($out . '/guide/no-description/index.html'));
    assert_true(!isset($bare['og:description']) && !isset($bare['twitter:description']));
    assert_contains('"image":"https://docs.example.org/og/guide/install.png"', (string) file_get_contents($out . '/guide/install/index.html'));

    // pholio check compares cards by their pixels: recompressed passes, a changed pixel is stale.
    $card = $out . '/og/guide/install.png';
    $png = (string) file_get_contents($card);
    $pixels = Png::canonical($png);
    assert_true($pixels !== null);
    $raw = '';
    $offset = 8;
    while ($offset < strlen($png)) {
        $length = unpack('N', substr($png, $offset, 4))[1];
        if (substr($png, $offset + 4, 4) === 'IDAT') {
            $raw .= substr($png, $offset + 8, $length);
        }
        $offset += 12 + $length;
    }
    $data = gzuncompress($raw);
    $chunk = static fn(string $type, string $d): string => pack('N', strlen($d)) . $type . $d . pack('N', crc32($type . $d));
    file_put_contents($card, substr($png, 0, 33) . $chunk('IDAT', gzcompress($data, 1)) . $chunk('IEND', ''));
    [$code, $err] = card_cli(['check', '--config', $dir . '/pholio.config.php', '--against', $out]);
    assert_same(0, $code, $err);
    file_put_contents($card, substr($png, 0, 33) . $chunk('IDAT', gzcompress(substr_replace($data, "\x00", 1000, 1))) . $chunk('IEND', ''));
    [$code, $err] = card_cli(['check', '--config', $dir . '/pholio.config.php', '--against', $out]);
    assert_same(1, $code, $err);
    assert_contains('stale: og/guide/install.png', $err);
});

test('build: under a base path with a start page, and docs_root_suffix', function (): void {
    $dir = card_site([
        'content/index.md' => "---\ntitle: Docs\n---\n\nText\n",
        'content/start.md' => "---\ntitle: Start\n---\n\nText\n",
    ], "'site' => ['url' => 'https://example.org'], 'base_path' => '/docs/', 'docs_root_suffix' => '/overview', 'home' => ['hero' => ['headline' => 'Lanternfly', 'lead' => 'Notes, found.']]");
    $out = $dir . '/out';
    assert_true(is_file($out . '/og.png'));
    assert_true(is_file($out . '/og/overview.png'));
    assert_true(is_file($out . '/og/start.png'));
    $home = card_meta((string) file_get_contents($out . '/index.html'));
    assert_same('https://example.org/docs/og.png', $home['og:image']);
    assert_same('https://example.org/docs', $home['og:url']);
    assert_same('Notes, found.', $home['og:description']);
    $docs = card_meta((string) file_get_contents($out . '/overview/index.html'));
    assert_same('https://example.org/docs/og/overview.png', $docs['og:image']);
    assert_same('https://example.org/docs/overview', $docs['og:url']);
});

test('build: without cards the tags stay, with twitter:card summary; without site.url there are none', function (): void {
    $page = ['content/index.md' => "---\ntitle: Overview\n---\n\nText\n", 'content/a.md' => "---\ntitle: A\n---\n\nText\n"];
    $dir = card_site($page, "'site' => ['url' => 'https://example.org'], 'share_card' => ['enabled' => false]");
    assert_true(!file_exists($dir . '/out/og.png') && !is_dir($dir . '/out/og'));
    $meta = card_meta((string) file_get_contents($dir . '/out/a/index.html'));
    assert_same('summary', $meta['twitter:card']);
    assert_same('A', $meta['og:title']);
    assert_true(!isset($meta['og:image']) && !isset($meta['twitter:image']));

    $dir = card_site($page);
    assert_same([], card_meta((string) file_get_contents($dir . '/out/a/index.html')));
    assert_true(!file_exists($dir . '/out/og.png'));
});

test('build: a card the site provides itself is kept', function (): void {
    $dir = card_site(
        ['content/index.md' => "---\ntitle: Overview\n---\n\nText\n", 'content/a.md' => "---\ntitle: A\n---\n\nText\n"],
        "'site' => ['url' => 'https://example.org'], 'copy' => ['public' => '/']",
        ['public/og.png' => 'hand-made', 'public/og/a.png' => 'hand-made too'],
    );
    assert_same('hand-made', file_get_contents($dir . '/out/og.png'));
    assert_same('hand-made too', file_get_contents($dir . '/out/og/a.png'));
});

test('build: a character the font lacks stops the build with the page file', function (): void {
    $err = '';
    card_site(
        ['content/index.md' => "---\ntitle: Overview\n---\n\nText\n", 'content/kanji.md' => "---\ntitle: 漢字 notes\n---\n\nText\n"],
        "'site' => ['url' => 'https://example.org']",
        [],
        3,
        $err,
    );
    assert_contains('kanji.md', $err);
    assert_contains('"漢" (U+6F22), "字" (U+5B57)', $err);
    assert_contains('Inter-SemiBold.ttf has no glyph for', $err);
});

test('build: unusable fonts and logos fail before anything is drawn', function (): void {
    $page = ['content/index.md' => "---\ntitle: Overview\n---\n\nText\n"];
    $cases = [
        ["'share_card' => ['fonts' => ['text' => 'font.woff2']]", ['font.woff2' => 'wOF2' . str_repeat("\0", 40)], 'share_card.fonts.text: ' . '%s/font.woff2: a woff2 web font'],
        ["'share_card' => ['fonts' => ['heading' => 'missing.ttf']]", [], 'share_card.fonts.heading: %s/missing.ttf: font file not readable'],
        ["'share_card' => ['logo' => 'logo.svg']", ['logo.svg' => '<svg viewBox="0 0 1 1"><circle r="1" stroke="red"/></svg>'], 'share_card.logo: %s/logo.svg: line 1: strokes are not supported'],
        ["'share_card' => ['brand' => 'Lanternfly 漢']", [], 'share_card.brand: '],
    ];
    foreach ($cases as [$config, $files, $message]) {
        $err = '';
        $dir = card_site($page, "'site' => ['url' => 'https://example.org'], " . $config, $files, 2, $err);
        assert_contains(sprintf($message, $dir), $err, $config);
    }
});

test('build: cards are byte-identical between builds and fast', function (): void {
    $pages = ['content/index.md' => "---\ntitle: Overview\n---\n\nText\n"];
    for ($i = 0; $i < 40; $i++) {
        $pages["content/page-{$i}.md"] = "---\ntitle: Page {$i} about something rather specific\ndescription: A sentence about page {$i}.\n---\n\nText\n";
    }
    $start = microtime(true);
    $first = card_site($pages, "'site' => ['url' => 'https://example.org']");
    $seconds = microtime(true) - $start;
    $second = card_site($pages, "'site' => ['url' => 'https://example.org']");
    foreach (['og.png', 'og/page-0.png', 'og/page-39.png'] as $png) {
        assert_same(md5_file($first . '/out/' . $png), md5_file($second . '/out/' . $png), $png);
    }
    assert_true($seconds < 30, sprintf('41 cards took %.1f s', $seconds));
});
