<?php

declare(strict_types=1);

namespace Pholio\Card;

/**
 * A TrueType font with glyf outlines, read without any extension: the tables
 * the share cards need to set a line of text.
 *
 *   head   unitsPerEm, the loca format
 *   hhea   ascender, descender, line gap, number of horizontal metrics
 *   maxp   number of glyphs
 *   cmap   Unicode code point => glyph, subtable format 4 (BMP) or 12 (full range)
 *   hmtx   advance widths
 *   loca, glyf   quadratic outlines, simple and composite glyphs
 *   GPOS   pair kerning: lookup type 2 (formats 1 and 2), also behind extension lookups
 *          (type 9), from every feature tagged `kern`
 *
 * No hinting, no shaping beyond kerning (no ligatures, no marks), horizontal
 * text only. A font without glyf outlines (CFF `.otf`, `.woff`, `.woff2`) is
 * refused with a CardException that says what the file is.
 *
 * Outlines come back in font units with y pointing up, as a list of contours of
 * path operations (see Raster::flatten()): [0, x, y] move, [1, x, y] line,
 * [2, cx, cy, x, y] quadratic curve.
 */
final class TrueType
{
    public readonly int $unitsPerEm;
    public readonly int $ascender;
    public readonly int $descender;
    public readonly int $lineGap;
    public readonly int $numGlyphs;

    /** @var array<string, array{0:int, 1:int}> table tag => offset, length */
    private array $tables = [];

    private int $numHMetrics;
    private bool $longLoca;

    /** @var array{format:int, offset:int} the Unicode cmap subtable */
    private array $cmap;

    /** @var array<int, int> code point => glyph id (0: missing) */
    private array $glyphIds = [];

    /** @var array<int, list<list<array<int, int|float>>>> glyph id => contours */
    private array $outlines = [];

    /** @var list<list<array{format:int, offset:int, valueFormat1:int, valueFormat2:int}>>|null the pair subtables of each kern lookup, in lookup order */
    private ?array $kernLookups = null;

    /** @var array<int, array<int, int>> parsed coverage tables by offset: glyph => coverage index */
    private array $coverages = [];

    /** @var array<int, array<int, int>> parsed class definitions by offset: glyph => class */
    private array $classDefs = [];

    /** @var array<string, int> "first,second" => kerning in font units */
    private array $kerning = [];

    private function __construct(private readonly string $data, private readonly string $file)
    {
        if (strlen($data) < 12) {
            throw new CardException('not a font file (too short)', $file);
        }
        $tag = substr($data, 0, 4);
        if ($tag === 'wOF2' || $tag === 'wOFF') {
            throw new CardException('a ' . ($tag === 'wOF2' ? 'woff2' : 'woff') . ' web font; give the TrueType file (.ttf) of the font', $file);
        }
        if ($tag === 'OTTO') {
            throw new CardException('an OpenType font with CFF outlines (.otf); give a TrueType file (.ttf) with glyf outlines', $file);
        }
        if ($tag === 'ttcf') {
            throw new CardException('a font collection (.ttc); give a single TrueType file (.ttf)', $file);
        }
        if ($tag !== "\x00\x01\x00\x00" && $tag !== 'true') {
            throw new CardException('not a TrueType font file', $file);
        }

        $count = $this->u16(4);
        for ($i = 0; $i < $count; $i++) {
            $record = 12 + 16 * $i;
            $offset = $this->u32($record + 8);
            $length = $this->u32($record + 12);
            if ($offset + $length > strlen($data)) {
                throw new CardException('table ' . trim(substr($data, $record, 4)) . ' runs past the end of the file', $file);
            }
            $this->tables[substr($data, $record, 4)] = [$offset, $length];
        }
        foreach (['head', 'hhea', 'maxp', 'cmap', 'hmtx'] as $required) {
            if (!isset($this->tables[$required])) {
                throw new CardException("no {$required} table", $file);
            }
        }
        if (!isset($this->tables['glyf'], $this->tables['loca'])) {
            throw new CardException('no glyf outlines (glyf and loca tables); give a TrueType file (.ttf) with glyf outlines', $file);
        }

        $head = $this->tables['head'][0];
        $this->unitsPerEm = $this->u16($head + 18);
        if ($this->unitsPerEm < 16) {
            throw new CardException('invalid unitsPerEm ' . $this->unitsPerEm, $file);
        }
        $this->longLoca = $this->i16($head + 50) === 1;

        $hhea = $this->tables['hhea'][0];
        $this->ascender = $this->i16($hhea + 4);
        $this->descender = $this->i16($hhea + 6);
        $this->lineGap = $this->i16($hhea + 8);
        $this->numHMetrics = $this->u16($hhea + 34);
        $this->numGlyphs = $this->u16($this->tables['maxp'][0] + 4);
        if ($this->numHMetrics < 1 || $this->numHMetrics > $this->numGlyphs) {
            throw new CardException('invalid number of horizontal metrics', $file);
        }

        $this->cmap = $this->findCmap();
    }

    /** @throws CardException */
    public static function load(string $file): self
    {
        $data = @file_get_contents($file);
        if ($data === false) {
            throw new CardException('font file not readable', $file);
        }

        return new self($data, $file);
    }

    /** @throws CardException */
    public static function fromString(string $data, string $name = 'font'): self
    {
        return new self($data, $name);
    }

    public function file(): string
    {
        return $this->file;
    }

    /** Glyph of a code point, or 0 (.notdef) when the font has none. */
    public function glyph(int $codePoint): int
    {
        return $this->glyphIds[$codePoint] ??= $this->lookupCmap($codePoint);
    }

    /** Advance width in font units. */
    public function advance(int $glyph): int
    {
        $hmtx = $this->tables['hmtx'][0];

        return $this->u16($hmtx + 4 * min($glyph, $this->numHMetrics - 1));
    }

    /**
     * Pair kerning in font units from GPOS `kern`: the first glyph's XAdvance plus
     * the second glyph's XPlacement, summed over every kern lookup.
     */
    public function kern(int $first, int $second): int
    {
        $key = $first . ',' . $second;
        if (isset($this->kerning[$key])) {
            return $this->kerning[$key];
        }
        $this->kernLookups ??= $this->readKernLookups();
        $total = 0;
        foreach ($this->kernLookups as $subtables) {
            foreach ($subtables as $subtable) {
                $value = $this->pairValue($subtable, $first, $second);
                if ($value !== null) {
                    $total += $value;
                    break;
                }
            }
        }

        return $this->kerning[$key] = $total;
    }

    /**
     * Outline of a glyph in font units, y up: contours of path operations.
     *
     * @return list<list<array<int, int|float>>>
     */
    public function outline(int $glyph): array
    {
        return $this->outlines[$glyph] ??= $this->readOutline($glyph, 0);
    }

    // ------------------------------------------------------------------ cmap

    /** @return array{format:int, offset:int} */
    private function findCmap(): array
    {
        $cmap = $this->tables['cmap'][0];
        $count = $this->u16($cmap + 2);
        $found = [];
        for ($i = 0; $i < $count; $i++) {
            $platform = $this->u16($cmap + 4 + 8 * $i);
            $encoding = $this->u16($cmap + 6 + 8 * $i);
            $offset = $cmap + $this->u32($cmap + 8 + 8 * $i);
            $format = $this->u16($offset);
            $found[$platform . '/' . $encoding . '/' . $format] = $offset;
        }
        // Full range first, then the BMP.
        foreach (['3/10/12', '0/6/12', '0/4/12', '3/1/4', '0/3/4', '0/2/4', '0/1/4', '0/0/4'] as $key) {
            if (isset($found[$key])) {
                return ['format' => (int) explode('/', $key)[2], 'offset' => $found[$key]];
            }
        }

        throw new CardException('no Unicode cmap subtable of format 4 or 12', $this->file);
    }

    private function lookupCmap(int $codePoint): int
    {
        $offset = $this->cmap['offset'];
        if ($this->cmap['format'] === 12) {
            $groups = $this->u32($offset + 12);
            // Binary search over the sorted groups.
            $low = 0;
            $high = $groups - 1;
            while ($low <= $high) {
                $mid = ($low + $high) >> 1;
                $group = $offset + 16 + 12 * $mid;
                $start = $this->u32($group);
                if ($codePoint < $start) {
                    $high = $mid - 1;
                } elseif ($codePoint > $this->u32($group + 4)) {
                    $low = $mid + 1;
                } else {
                    return $this->checkedGlyph($this->u32($group + 8) + $codePoint - $start);
                }
            }

            return 0;
        }

        if ($codePoint > 0xFFFF) {
            return 0;
        }
        $segments = $this->u16($offset + 6) >> 1;
        $ends = $offset + 14;
        $starts = $ends + 2 * $segments + 2;
        $deltas = $starts + 2 * $segments;
        $rangeOffsets = $deltas + 2 * $segments;
        $low = 0;
        $high = $segments - 1;
        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
            if ($this->u16($ends + 2 * $mid) < $codePoint) {
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }
        if ($low >= $segments) {
            return 0;
        }
        $start = $this->u16($starts + 2 * $low);
        if ($codePoint < $start) {
            return 0;
        }
        $delta = $this->u16($deltas + 2 * $low);
        $rangeOffset = $this->u16($rangeOffsets + 2 * $low);
        if ($rangeOffset === 0) {
            return $this->checkedGlyph(($codePoint + $delta) & 0xFFFF);
        }
        $glyph = $this->u16($rangeOffsets + 2 * $low + $rangeOffset + 2 * ($codePoint - $start));

        return $glyph === 0 ? 0 : $this->checkedGlyph(($glyph + $delta) & 0xFFFF);
    }

    private function checkedGlyph(int $glyph): int
    {
        return $glyph < $this->numGlyphs ? $glyph : 0;
    }

    // ------------------------------------------------------------------ glyf

    /** @return list<list<array<int, int|float>>> */
    private function readOutline(int $glyph, int $depth): array
    {
        if ($glyph < 0 || $glyph >= $this->numGlyphs) {
            throw new CardException('glyph ' . $glyph . ' out of range', $this->file);
        }
        if ($depth > 8) {
            throw new CardException('composite glyphs nested too deeply', $this->file);
        }
        $loca = $this->tables['loca'][0];
        [$start, $end] = $this->longLoca
            ? [$this->u32($loca + 4 * $glyph), $this->u32($loca + 4 * $glyph + 4)]
            : [2 * $this->u16($loca + 2 * $glyph), 2 * $this->u16($loca + 2 * $glyph + 2)];
        if ($end <= $start) {
            return [];
        }
        $offset = $this->tables['glyf'][0] + $start;
        $contours = $this->i16($offset);

        return $contours >= 0 ? $this->simpleOutline($offset, $contours) : $this->compositeOutline($offset, $depth);
    }

    /** @return list<list<array<int, int|float>>> */
    private function simpleOutline(int $offset, int $contourCount): array
    {
        $p = $offset + 10;
        $ends = [];
        for ($i = 0; $i < $contourCount; $i++) {
            $ends[] = $this->u16($p);
            $p += 2;
        }
        if ($contourCount === 0) {
            return [];
        }
        $points = $ends[$contourCount - 1] + 1;
        $p += 2 + $this->u16($p);

        $flags = [];
        while (count($flags) < $points) {
            $flag = ord($this->data[$p++]);
            $flags[] = $flag;
            if (($flag & 8) !== 0) {
                $repeat = ord($this->data[$p++]);
                for ($r = 0; $r < $repeat; $r++) {
                    $flags[] = $flag;
                }
            }
        }

        $xs = [];
        $value = 0;
        for ($i = 0; $i < $points; $i++) {
            $flag = $flags[$i];
            if (($flag & 2) !== 0) {
                $delta = ord($this->data[$p++]);
                $value += ($flag & 16) !== 0 ? $delta : -$delta;
            } elseif (($flag & 16) === 0) {
                $value += $this->i16($p);
                $p += 2;
            }
            $xs[] = $value;
        }
        $ys = [];
        $value = 0;
        for ($i = 0; $i < $points; $i++) {
            $flag = $flags[$i];
            if (($flag & 4) !== 0) {
                $delta = ord($this->data[$p++]);
                $value += ($flag & 32) !== 0 ? $delta : -$delta;
            } elseif (($flag & 32) === 0) {
                $value += $this->i16($p);
                $p += 2;
            }
            $ys[] = $value;
        }

        $out = [];
        $first = 0;
        foreach ($ends as $last) {
            $contour = [];
            for ($i = $first; $i <= $last; $i++) {
                $contour[] = [$xs[$i], $ys[$i], ($flags[$i] & 1) !== 0];
            }
            $first = $last + 1;
            $path = self::quadraticPath($contour);
            if ($path !== []) {
                $out[] = $path;
            }
        }

        return $out;
    }

    /**
     * On- and off-curve points of a closed contour as path operations: two
     * off-curve points in a row imply an on-curve point halfway between them.
     *
     * @param list<array{0:int|float, 1:int|float, 2:bool}> $points
     * @return list<array<int, int|float>>
     */
    private static function quadraticPath(array $points): array
    {
        $n = count($points);
        if ($n < 2) {
            return [];
        }
        // Start at an on-curve point; without one, halfway between the first two.
        $startIndex = null;
        foreach ($points as $i => $point) {
            if ($point[2]) {
                $startIndex = $i;
                break;
            }
        }
        if ($startIndex === null) {
            // From halfway between the first two points, around to the first point and back.
            $start = [($points[0][0] + $points[1][0]) / 2, ($points[0][1] + $points[1][1]) / 2];
            $path = [[0, $start[0], $start[1]]];
            $control = null;
            foreach ([...array_slice($points, 1), $points[0]] as $point) {
                self::appendPoint($path, $control, $point);
            }
            $path[] = [2, $control[0], $control[1], $start[0], $start[1]];

            return $path;
        }

        $start = $points[$startIndex];
        $path = [[0, $start[0], $start[1]]];
        $control = null;
        for ($k = 1; $k <= $n; $k++) {
            $point = $points[($startIndex + $k) % $n];
            if ($k === $n) {
                // Back at the start point.
                if ($control !== null) {
                    $path[] = [2, $control[0], $control[1], $start[0], $start[1]];
                } else {
                    $path[] = [1, $start[0], $start[1]];
                }
                break;
            }
            self::appendPoint($path, $control, $point);
        }

        return $path;
    }

    /**
     * @param list<array<int, int|float>> $path
     * @param array{0:int|float, 1:int|float, 2:bool}|null $control the pending off-curve point
     * @param array{0:int|float, 1:int|float, 2:bool} $point
     */
    private static function appendPoint(array &$path, ?array &$control, array $point): void
    {
        if ($point[2]) {
            $path[] = $control === null ? [1, $point[0], $point[1]] : [2, $control[0], $control[1], $point[0], $point[1]];
            $control = null;

            return;
        }
        if ($control !== null) {
            $path[] = [2, $control[0], $control[1], ($control[0] + $point[0]) / 2, ($control[1] + $point[1]) / 2];
        }
        $control = $point;
    }

    /** @return list<list<array<int, int|float>>> */
    private function compositeOutline(int $offset, int $depth): array
    {
        $p = $offset + 10;
        $out = [];
        do {
            $flags = $this->u16($p);
            $component = $this->u16($p + 2);
            $p += 4;
            $xy = ($flags & 0x0002) !== 0;
            if (($flags & 0x0001) !== 0) {
                $arg1 = $xy ? $this->i16($p) : $this->u16($p);
                $arg2 = $xy ? $this->i16($p + 2) : $this->u16($p + 2);
                $p += 4;
            } else {
                $arg1 = $xy ? self::signedByte(ord($this->data[$p])) : ord($this->data[$p]);
                $arg2 = $xy ? self::signedByte(ord($this->data[$p + 1])) : ord($this->data[$p + 1]);
                $p += 2;
            }
            if (!$xy) {
                throw new CardException('composite glyph positioned by matching points, which is not supported', $this->file);
            }
            [$a, $b, $c, $d] = [1.0, 0.0, 0.0, 1.0];
            if (($flags & 0x0008) !== 0) {
                $a = $d = $this->f2dot14($p);
                $p += 2;
            } elseif (($flags & 0x0040) !== 0) {
                $a = $this->f2dot14($p);
                $d = $this->f2dot14($p + 2);
                $p += 4;
            } elseif (($flags & 0x0080) !== 0) {
                $a = $this->f2dot14($p);
                $b = $this->f2dot14($p + 2);
                $c = $this->f2dot14($p + 4);
                $d = $this->f2dot14($p + 6);
                $p += 8;
            }
            $scaled = ($flags & 0x0800) !== 0;
            $dx = $scaled ? $a * $arg1 + $c * $arg2 : $arg1;
            $dy = $scaled ? $b * $arg1 + $d * $arg2 : $arg2;
            $identity = $a === 1.0 && $b === 0.0 && $c === 0.0 && $d === 1.0;

            foreach ($this->readOutline($component, $depth + 1) as $contour) {
                $moved = [];
                foreach ($contour as $op) {
                    $copy = [$op[0]];
                    for ($i = 1; $i < count($op); $i += 2) {
                        $x = $op[$i];
                        $y = $op[$i + 1];
                        $copy[] = $identity ? $x + $dx : $a * $x + $c * $y + $dx;
                        $copy[] = $identity ? $y + $dy : $b * $x + $d * $y + $dy;
                    }
                    $moved[] = $copy;
                }
                $out[] = $moved;
            }
        } while (($flags & 0x0020) !== 0);

        return $out;
    }

    // ------------------------------------------------------------------ GPOS

    /** @return list<list<array{format:int, offset:int, valueFormat1:int, valueFormat2:int}>> */
    private function readKernLookups(): array
    {
        if (!isset($this->tables['GPOS'])) {
            return [];
        }
        $gpos = $this->tables['GPOS'][0];
        $features = $gpos + $this->u16($gpos + 6);
        $lookups = $gpos + $this->u16($gpos + 8);

        $indices = [];
        $featureCount = $this->u16($features);
        for ($i = 0; $i < $featureCount; $i++) {
            $record = $features + 2 + 6 * $i;
            if (substr($this->data, $record, 4) !== 'kern') {
                continue;
            }
            $feature = $features + $this->u16($record + 4);
            $count = $this->u16($feature + 2);
            for ($j = 0; $j < $count; $j++) {
                $indices[$this->u16($feature + 4 + 2 * $j)] = true;
            }
        }
        $indices = array_keys($indices);
        sort($indices);

        $out = [];
        $lookupCount = $this->u16($lookups);
        foreach ($indices as $index) {
            if ($index >= $lookupCount) {
                continue;
            }
            $lookup = $lookups + $this->u16($lookups + 2 + 2 * $index);
            $type = $this->u16($lookup);
            $subtableCount = $this->u16($lookup + 4);
            $subtables = [];
            for ($s = 0; $s < $subtableCount; $s++) {
                $subtable = $lookup + $this->u16($lookup + 6 + 2 * $s);
                $subtableType = $type;
                if ($type === 9) {
                    $subtableType = $this->u16($subtable + 2);
                    $subtable += $this->u32($subtable + 4);
                }
                if ($subtableType !== 2) {
                    continue;
                }
                $format = $this->u16($subtable);
                if ($format !== 1 && $format !== 2) {
                    continue;
                }
                $subtables[] = [
                    'format' => $format,
                    'offset' => $subtable,
                    'valueFormat1' => $this->u16($subtable + 4),
                    'valueFormat2' => $this->u16($subtable + 6),
                ];
            }
            if ($subtables !== []) {
                $out[] = $subtables;
            }
        }

        return $out;
    }

    /**
     * Kerning of one pair by one subtable, or null when the subtable does not
     * apply (the first glyph is not covered, or a format 1 subtable lacks the pair).
     *
     * @param array{format:int, offset:int, valueFormat1:int, valueFormat2:int} $subtable
     */
    private function pairValue(array $subtable, int $first, int $second): ?int
    {
        $offset = $subtable['offset'];
        $coverage = $this->coverage($offset + $this->u16($offset + 2));
        if (!isset($coverage[$first])) {
            return null;
        }
        $format1 = $subtable['valueFormat1'];
        $format2 = $subtable['valueFormat2'];
        $size1 = 2 * self::bits($format1 & 0xFF);
        $size2 = 2 * self::bits($format2 & 0xFF);

        if ($subtable['format'] === 1) {
            $pairSet = $offset + $this->u16($offset + 10 + 2 * $coverage[$first]);
            $count = $this->u16($pairSet);
            $recordSize = 2 + $size1 + $size2;
            $low = 0;
            $high = $count - 1;
            while ($low <= $high) {
                $mid = ($low + $high) >> 1;
                $record = $pairSet + 2 + $recordSize * $mid;
                $glyph = $this->u16($record);
                if ($glyph < $second) {
                    $low = $mid + 1;
                } elseif ($glyph > $second) {
                    $high = $mid - 1;
                } else {
                    return $this->xAdvance($record + 2, $format1) + $this->xPlacement($record + 2 + $size1, $format2);
                }
            }

            return null;
        }

        $class1 = $this->classDef($offset + $this->u16($offset + 8))[$first] ?? 0;
        $class2 = $this->classDef($offset + $this->u16($offset + 10))[$second] ?? 0;
        $class1Count = $this->u16($offset + 12);
        $class2Count = $this->u16($offset + 14);
        if ($class1 >= $class1Count || $class2 >= $class2Count) {
            return 0;
        }
        $record = $offset + 16 + ($class1 * $class2Count + $class2) * ($size1 + $size2);

        return $this->xAdvance($record, $format1) + $this->xPlacement($record + $size1, $format2);
    }

    /** XAdvance (bit 0x0004) of a value record, after XPlacement and YPlacement when present. */
    private function xAdvance(int $record, int $format): int
    {
        return ($format & 0x0004) === 0 ? 0 : $this->i16($record + 2 * self::bits($format & 0x0003));
    }

    /** XPlacement (bit 0x0001), the first field of a value record. */
    private function xPlacement(int $record, int $format): int
    {
        return ($format & 0x0001) === 0 ? 0 : $this->i16($record);
    }

    /** @return array<int, int> glyph => coverage index */
    private function coverage(int $offset): array
    {
        if (isset($this->coverages[$offset])) {
            return $this->coverages[$offset];
        }
        $map = [];
        $format = $this->u16($offset);
        $count = $this->u16($offset + 2);
        if ($format === 1) {
            for ($i = 0; $i < $count; $i++) {
                $map[$this->u16($offset + 4 + 2 * $i)] = $i;
            }
        } elseif ($format === 2) {
            for ($i = 0; $i < $count; $i++) {
                $range = $offset + 4 + 6 * $i;
                $start = $this->u16($range);
                $end = $this->u16($range + 2);
                $index = $this->u16($range + 4);
                for ($glyph = $start; $glyph <= $end; $glyph++) {
                    $map[$glyph] = $index + $glyph - $start;
                }
            }
        }

        return $this->coverages[$offset] = $map;
    }

    /** @return array<int, int> glyph => class; glyphs not listed are class 0 */
    private function classDef(int $offset): array
    {
        if (isset($this->classDefs[$offset])) {
            return $this->classDefs[$offset];
        }
        $map = [];
        $format = $this->u16($offset);
        if ($format === 1) {
            $start = $this->u16($offset + 2);
            $count = $this->u16($offset + 4);
            for ($i = 0; $i < $count; $i++) {
                $map[$start + $i] = $this->u16($offset + 6 + 2 * $i);
            }
        } elseif ($format === 2) {
            $count = $this->u16($offset + 2);
            for ($i = 0; $i < $count; $i++) {
                $range = $offset + 4 + 6 * $i;
                $class = $this->u16($range + 4);
                for ($glyph = $this->u16($range), $end = $this->u16($range + 2); $glyph <= $end; $glyph++) {
                    $map[$glyph] = $class;
                }
            }
        }

        return $this->classDefs[$offset] = $map;
    }

    // ------------------------------------------------------------------ bytes

    private function u16(int $offset): int
    {
        if ($offset < 0 || $offset + 2 > strlen($this->data)) {
            throw new CardException('read past the end of the file at offset ' . $offset, $this->file);
        }

        return (ord($this->data[$offset]) << 8) | ord($this->data[$offset + 1]);
    }

    private function i16(int $offset): int
    {
        $value = $this->u16($offset);

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private function u32(int $offset): int
    {
        return ($this->u16($offset) << 16) | $this->u16($offset + 2);
    }

    private function f2dot14(int $offset): float
    {
        return $this->i16($offset) / 16384;
    }

    private static function signedByte(int $value): int
    {
        return $value >= 0x80 ? $value - 0x100 : $value;
    }

    private static function bits(int $value): int
    {
        $count = 0;
        for (; $value !== 0; $value >>= 1) {
            $count += $value & 1;
        }

        return $count;
    }
}
