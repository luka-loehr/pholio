// The arithmetic of the pixel comparison, kept apart from the tool so the selftest
// can check it without a browser.
//
// Colour distance and edge detection follow pixelmatch (YIQ distance, 3×3
// neighbourhood); the algorithm is short enough to write here, and the verify
// tooling doesn't need an extra dependency for it.

export const MAX_DELTA = 35215; // largest possible YIQ distance

export function rgb2y(r, g, b) { return r * 0.29889531 + g * 0.58662247 + b * 0.11448223; }
export function rgb2i(r, g, b) { return r * 0.59597799 - g * 0.2741761 - b * 0.32180189; }
export function rgb2q(r, g, b) { return r * 0.21147017 - g * 0.52261711 + b * 0.31114694; }

// Colour distance of two pixels. Semi-transparent pixels are blended onto white first.
// yOnly returns only the signed brightness difference; edge detection needs it to tell
// "darker" from "lighter".
export function colorDelta(a, b, pa, pb, yOnly) {
  let r1 = a[pa]; let g1 = a[pa + 1]; let b1 = a[pa + 2]; const a1 = a[pa + 3];
  let r2 = b[pb]; let g2 = b[pb + 1]; let b2 = b[pb + 2]; const a2 = b[pb + 3];
  if (r1 === r2 && g1 === g2 && b1 === b2 && a1 === a2) return 0;
  if (a1 < 255) {
    const f = a1 / 255;
    r1 = 255 + (r1 - 255) * f; g1 = 255 + (g1 - 255) * f; b1 = 255 + (b1 - 255) * f;
  }
  if (a2 < 255) {
    const f = a2 / 255;
    r2 = 255 + (r2 - 255) * f; g2 = 255 + (g2 - 255) * f; b2 = 255 + (b2 - 255) * f;
  }
  const y = rgb2y(r1, g1, b1) - rgb2y(r2, g2, b2);
  if (yOnly) return y;
  const i = rgb2i(r1, g1, b1) - rgb2i(r2, g2, b2);
  const q = rgb2q(r1, g1, b1) - rgb2q(r2, g2, b2);
  return 0.5053 * y * y + 0.299 * i * i + 0.1957 * q * q;
}

// Does the pixel have more than two identical neighbours in its 3×3 neighbourhood?
export function hasManySiblings(img, x1, y1, width, height) {
  const x0 = Math.max(x1 - 1, 0);
  const y0 = Math.max(y1 - 1, 0);
  const x2 = Math.min(x1 + 1, width - 1);
  const y2 = Math.min(y1 + 1, height - 1);
  const pos = (y1 * width + x1) * 4;
  let zeroes = x1 === x0 || x1 === x2 || y1 === y0 || y1 === y2 ? 1 : 0;
  for (let x = x0; x <= x2; x += 1) {
    for (let y = y0; y <= y2; y += 1) {
      if (x === x1 && y === y1) continue;
      const p = (y * width + x) * 4;
      if (img[pos] === img[p] && img[pos + 1] === img[p + 1] && img[pos + 2] === img[p + 2] && img[pos + 3] === img[p + 3]) {
        zeroes += 1;
      }
      if (zeroes > 2) return true;
    }
  }
  return false;
}

// A pixel counts as antialiasing when its 3×3 neighbourhood has both clearly darker
// and clearly lighter neighbours (so it lies on an edge) and the most extreme neighbour
// on either side has many identical neighbours in both images (so it is the inside of
// an area). At most two identical neighbours are allowed; otherwise the pixel itself is
// inside an area and not an edge.
export function antialiased(img, x1, y1, width, height, other) {
  const x0 = Math.max(x1 - 1, 0);
  const y0 = Math.max(y1 - 1, 0);
  const x2 = Math.min(x1 + 1, width - 1);
  const y2 = Math.min(y1 + 1, height - 1);
  const pos = (y1 * width + x1) * 4;
  let zeroes = x1 === x0 || x1 === x2 || y1 === y0 || y1 === y2 ? 1 : 0;
  let min = 0;
  let max = 0;
  let minX = 0; let minY = 0; let maxX = 0; let maxY = 0;

  for (let x = x0; x <= x2; x += 1) {
    for (let y = y0; y <= y2; y += 1) {
      if (x === x1 && y === y1) continue;
      const delta = colorDelta(img, img, pos, (y * width + x) * 4, true);
      if (delta === 0) {
        zeroes += 1;
        if (zeroes > 2) return false;
      } else if (delta < min) {
        min = delta; minX = x; minY = y;
      } else if (delta > max) {
        max = delta; maxX = x; maxY = y;
      }
    }
  }
  if (min === 0 || max === 0) return false;
  return (
    (hasManySiblings(img, minX, minY, width, height) && hasManySiblings(other, minX, minY, width, height)) ||
    (hasManySiblings(img, maxX, maxY, width, height) && hasManySiblings(other, maxX, maxY, width, height))
  );
}

// Compare two images. Different dimensions don't abort: the common area is compared,
// everything beyond it counts as different.
// In the diff image, differing pixels are red, pixels judged antialiasing yellow, equal
// ones a pale greyscale of the reference.
// maxChannelDelta: pixels that differ by no more than this value in every channel count
// as rounding, not as a difference. Chromium occasionally rasterises the same page in two
// contexts open at the same time one step out of 255 apart, at rounded corners, borders
// and glyph edges. That isn't a difference between the two sides but rasteriser noise.
// It still stays visible in the output: such pixels are counted and marked blue, so a
// real colour error in the candidate (which would produce thousands of them) doesn't get
// lost in it.
export function diffImages(ref, cand, { pixelThreshold = 0, antialias = false, maxChannelDelta = 0 } = {}) {
  const width = Math.max(ref.width, cand.width);
  const height = Math.max(ref.height, cand.height);
  const out = new Uint8Array(width * height * 4);
  const limit = pixelThreshold * MAX_DELTA;
  let different = 0;
  let antialiasedCount = 0;
  let roundingCount = 0;

  for (let y = 0; y < height; y += 1) {
    for (let x = 0; x < width; x += 1) {
      const o = (y * width + x) * 4;
      if (x >= ref.width || y >= ref.height || x >= cand.width || y >= cand.height) {
        out[o] = 255; out[o + 1] = 0; out[o + 2] = 0; out[o + 3] = 255;
        different += 1;
        continue;
      }

      const pr = (y * ref.width + x) * 4;
      const pc = (y * cand.width + x) * 4;
      const delta = colorDelta(ref.data, cand.data, pr, pc, false);

      if (delta > limit) {
        if (
          maxChannelDelta > 0 &&
          Math.abs(ref.data[pr] - cand.data[pc]) <= maxChannelDelta &&
          Math.abs(ref.data[pr + 1] - cand.data[pc + 1]) <= maxChannelDelta &&
          Math.abs(ref.data[pr + 2] - cand.data[pc + 2]) <= maxChannelDelta &&
          Math.abs(ref.data[pr + 3] - cand.data[pc + 3]) <= maxChannelDelta
        ) {
          roundingCount += 1;
          out[o] = 0; out[o + 1] = 80; out[o + 2] = 255; out[o + 3] = 255;
          continue;
        }
        if (
          antialias &&
          (antialiased(ref.data, x, y, ref.width, ref.height, cand.data) ||
            antialiased(cand.data, x, y, cand.width, cand.height, ref.data))
        ) {
          antialiasedCount += 1;
          out[o] = 255; out[o + 1] = 255; out[o + 2] = 0; out[o + 3] = 255;
          continue;
        }
        different += 1;
        out[o] = 255; out[o + 1] = 0; out[o + 2] = 0; out[o + 3] = 255;
        continue;
      }

      const grey = 255 - (255 - rgb2y(ref.data[pr], ref.data[pr + 1], ref.data[pr + 2])) * 0.1;
      out[o] = grey; out[o + 1] = grey; out[o + 2] = grey; out[o + 3] = 255;
    }
  }

  return {
    width,
    height,
    diff: { width, height, data: out },
    different,
    antialiased: antialiasedCount,
    rounding: roundingCount,
    total: width * height,
    ratio: width * height ? different / (width * height) : 0,
    sameSize: ref.width === cand.width && ref.height === cand.height,
  };
}
