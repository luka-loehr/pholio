// Minimal PNG reader and writer for the pixel comparison.
//
// No PNG package is needed for this job: PNG is zlib plus five scanline filters,
// and zlib ships with Node. Reading covers every non-interlaced image with 8 bits
// per channel (greyscale, greyscale+alpha, RGB, RGBA, palette); Playwright produces
// RGBA or RGB. Writing is RGBA with filter 0 only.
//
// Images are always held as { width, height, data }, where data is a Uint8Array
// with 4 bytes per pixel in R, G, B, A order.

import zlib from 'node:zlib';

const SIGNATURE = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a];

// ---- CRC32 ----------------------------------------------------------------

const CRC_TABLE = (() => {
  const table = new Uint32Array(256);
  for (let n = 0; n < 256; n += 1) {
    let c = n;
    for (let k = 0; k < 8; k += 1) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c >>> 0;
  }
  return table;
})();

function crc32(buf) {
  let c = 0xffffffff;
  for (let i = 0; i < buf.length; i += 1) c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}

// ---- Reading --------------------------------------------------------------

const CHANNELS = { 0: 1, 2: 3, 3: 1, 4: 2, 6: 4 };

function unfilter(raw, width, height, bpp) {
  const stride = width * bpp;
  const out = Buffer.alloc(height * stride);
  let pos = 0;
  for (let y = 0; y < height; y += 1) {
    const filter = raw[pos];
    pos += 1;
    const line = raw.subarray(pos, pos + stride);
    pos += stride;
    const cur = out.subarray(y * stride, (y + 1) * stride);
    const prev = y > 0 ? out.subarray((y - 1) * stride, y * stride) : null;
    for (let x = 0; x < stride; x += 1) {
      const a = x >= bpp ? cur[x - bpp] : 0;
      const b = prev ? prev[x] : 0;
      const c = prev && x >= bpp ? prev[x - bpp] : 0;
      const v = line[x];
      switch (filter) {
        case 0: cur[x] = v; break;
        case 1: cur[x] = (v + a) & 0xff; break;
        case 2: cur[x] = (v + b) & 0xff; break;
        case 3: cur[x] = (v + ((a + b) >> 1)) & 0xff; break;
        case 4: {
          const p = a + b - c;
          const pa = Math.abs(p - a);
          const pb = Math.abs(p - b);
          const pc = Math.abs(p - c);
          const pred = pa <= pb && pa <= pc ? a : pb <= pc ? b : c;
          cur[x] = (v + pred) & 0xff;
          break;
        }
        default:
          throw new Error(`Unknown PNG scanline filter ${filter} in row ${y}`);
      }
    }
  }
  return out;
}

export function decodePng(buffer) {
  const buf = Buffer.isBuffer(buffer) ? buffer : Buffer.from(buffer);
  for (let i = 0; i < 8; i += 1) if (buf[i] !== SIGNATURE[i]) throw new Error('Not a PNG file (signature missing)');

  let pos = 8;
  let header = null;
  let palette = null;
  let transparency = null;
  const idat = [];

  while (pos < buf.length) {
    const length = buf.readUInt32BE(pos);
    const type = buf.toString('ascii', pos + 4, pos + 8);
    const data = buf.subarray(pos + 8, pos + 8 + length);
    pos += 12 + length;
    if (type === 'IHDR') {
      header = {
        width: data.readUInt32BE(0),
        height: data.readUInt32BE(4),
        depth: data[8],
        colorType: data[9],
        interlace: data[12],
      };
    } else if (type === 'PLTE') palette = Buffer.from(data);
    else if (type === 'tRNS') transparency = Buffer.from(data);
    else if (type === 'IDAT') idat.push(Buffer.from(data));
    else if (type === 'IEND') break;
  }

  if (!header) throw new Error('PNG without IHDR');
  if (header.depth !== 8) throw new Error(`Only 8 bits per channel are supported, found: ${header.depth}`);
  if (header.interlace !== 0) throw new Error('Interlaced PNGs (Adam7) are not supported');
  const channels = CHANNELS[header.colorType];
  if (!channels) throw new Error(`Unknown PNG colour type ${header.colorType}`);

  const raw = zlib.inflateSync(Buffer.concat(idat));
  const planes = unfilter(raw, header.width, header.height, channels);

  const { width, height, colorType } = header;
  const data = new Uint8Array(width * height * 4);
  for (let i = 0, n = width * height; i < n; i += 1) {
    const s = i * channels;
    const d = i * 4;
    if (colorType === 6) {
      data[d] = planes[s]; data[d + 1] = planes[s + 1]; data[d + 2] = planes[s + 2]; data[d + 3] = planes[s + 3];
    } else if (colorType === 2) {
      data[d] = planes[s]; data[d + 1] = planes[s + 1]; data[d + 2] = planes[s + 2]; data[d + 3] = 255;
    } else if (colorType === 0) {
      data[d] = data[d + 1] = data[d + 2] = planes[s]; data[d + 3] = 255;
    } else if (colorType === 4) {
      data[d] = data[d + 1] = data[d + 2] = planes[s]; data[d + 3] = planes[s + 1];
    } else {
      const idx = planes[s];
      if (!palette) throw new Error('Palette image without PLTE');
      data[d] = palette[idx * 3]; data[d + 1] = palette[idx * 3 + 1]; data[d + 2] = palette[idx * 3 + 2];
      data[d + 3] = transparency && idx < transparency.length ? transparency[idx] : 255;
    }
  }
  return { width, height, data };
}

// ---- Writing --------------------------------------------------------------

function chunk(type, data) {
  const head = Buffer.alloc(8);
  head.writeUInt32BE(data.length, 0);
  head.write(type, 4, 'ascii');
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(Buffer.concat([head.subarray(4), data])), 0);
  return Buffer.concat([head, data, crc]);
}

export function encodePng({ width, height, data }) {
  const stride = width * 4;
  const raw = Buffer.alloc(height * (stride + 1));
  for (let y = 0; y < height; y += 1) {
    raw[y * (stride + 1)] = 0;
    Buffer.from(data.buffer, data.byteOffset + y * stride, stride).copy(raw, y * (stride + 1) + 1);
  }
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0);
  ihdr.writeUInt32BE(height, 4);
  ihdr[8] = 8;
  ihdr[9] = 6;
  return Buffer.concat([
    Buffer.from(SIGNATURE),
    chunk('IHDR', ihdr),
    chunk('IDAT', zlib.deflateSync(raw, { level: 6 })),
    chunk('IEND', Buffer.alloc(0)),
  ]);
}

// Blank single-colour image, for size mismatches and placeholders.
export function blankImage(width, height, [r, g, b, a] = [0, 0, 0, 255]) {
  const data = new Uint8Array(width * height * 4);
  for (let i = 0; i < data.length; i += 4) {
    data[i] = r; data[i + 1] = g; data[i + 2] = b; data[i + 3] = a;
  }
  return { width, height, data };
}
