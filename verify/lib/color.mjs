// Colour normalisation for the computed-style comparison.
//
// Chromium returns computed colours in the colour space they were written in:
// `rgb(…)`, `rgba(…)`, `color(srgb …)`, `oklch(…)`, `oklab(…)`, `lab(…)`. The reference
// and the candidate don't necessarily write the same colour the same way: Tailwind
// output uses `oklch`, a hand-written stylesheet may use `#rrggbb`. A plain string
// comparison would therefore raise false alarms.
//
// So every colour expression in a property value is converted to the canonical form
// `rgba(r, g, b, a)`: r/g/b as 8-bit sRGB integers (0–255), a rounded to three
// decimal places. The conversion follows CSS Color 4 (including the matrices and the
// Bradford D50 → D65 adaptation), not an approximation.
//
// normaliseColorsInValue() replaces colours only and leaves the rest of the value
// untouched, so `box-shadow: rgba(0,0,0,.1) 0 1px 2px` is still compared in full.

// ---- Matrices and transfer functions --------------------------------------

const D50 = [0.3457 / 0.3585, 1, (1 - 0.3457 - 0.3585) / 0.3585];

function mul(m, v) {
  return [
    m[0][0] * v[0] + m[0][1] * v[1] + m[0][2] * v[2],
    m[1][0] * v[0] + m[1][1] * v[1] + m[1][2] * v[2],
    m[2][0] * v[0] + m[2][1] * v[1] + m[2][2] * v[2],
  ];
}

const XYZ_TO_LIN_SRGB = [
  [3.2409699419045226, -1.537383177570094, -0.4986107602930034],
  [-0.9692436362808796, 1.8759675015077202, 0.04155505740717559],
  [0.05563007969699366, -0.20397695888897652, 1.0569715142428786],
];
const LIN_SRGB_TO_XYZ = [
  [0.41239079926595934, 0.357584339383878, 0.1804807884018343],
  [0.21263900587151027, 0.715168678767756, 0.07219231536073371],
  [0.01933081871559182, 0.11919477979462598, 0.9505321522496607],
];
const D50_TO_D65 = [
  [0.955473421488075, -0.02309845494876471, 0.06325924320057072],
  [-0.0283697093338637, 1.0099953980813041, 0.021041441191917323],
  [0.012314014864481998, -0.020507649298898964, 1.330365926242124],
];
const LIN_P3_TO_XYZ = [
  [0.4865709486482162, 0.26566769316909306, 0.1982172852343625],
  [0.2289745640697488, 0.6917385218365064, 0.079286914093745],
  [0, 0.04511338185890264, 1.043944368900976],
];
const LIN_A98_TO_XYZ = [
  [0.5766690429101305, 0.1855582379065463, 0.1882286462349947],
  [0.2973449752505361, 0.6273635662554661, 0.0752914584939978],
  [0.0270313613864123, 0.0706888525358272, 0.9913375368376388],
];
const LIN_PROPHOTO_TO_XYZ_D50 = [
  [0.7977604896723027, 0.13518583717574031, 0.0313493495815248],
  [0.2880711282292934, 0.7118432178101014, 0.00008565396060525902],
  [0, 0, 0.8251046025104601],
];
const LIN_REC2020_TO_XYZ = [
  [0.6369580483012914, 0.14461690358620832, 0.1688809751641721],
  [0.2627002120112671, 0.6779980715188708, 0.05930171646986196],
  [0, 0.028072693049087428, 1.060985057710791],
];

function srgbToLinear(c) {
  const abs = Math.abs(c);
  const sign = c < 0 ? -1 : 1;
  return abs <= 0.04045 ? c / 12.92 : sign * ((abs + 0.055) / 1.055) ** 2.4;
}
function linearToSrgb(c) {
  const abs = Math.abs(c);
  const sign = c < 0 ? -1 : 1;
  return abs <= 0.0031308 ? c * 12.92 : sign * (1.055 * abs ** (1 / 2.4) - 0.055);
}
function a98ToLinear(c) {
  const sign = c < 0 ? -1 : 1;
  return sign * Math.abs(c) ** (563 / 256);
}
function prophotoToLinear(c) {
  const sign = c < 0 ? -1 : 1;
  const abs = Math.abs(c);
  return abs <= 16 / 512 ? c / 16 : sign * abs ** 1.8;
}
function rec2020ToLinear(c) {
  const a = 1.09929682680944;
  const b = 0.018053968510807;
  const sign = c < 0 ? -1 : 1;
  const abs = Math.abs(c);
  return abs < b * 4.5 ? c / 4.5 : sign * ((abs + a - 1) / a) ** (1 / 0.45);
}

// ---- Colour spaces → sRGB (0–1, unclamped) --------------------------------

function xyzD65ToSrgb(xyz) {
  return mul(XYZ_TO_LIN_SRGB, xyz).map(linearToSrgb);
}

function oklabToSrgb([L, a, b]) {
  const l = (L + 0.3963377773761749 * a + 0.2158037573099136 * b) ** 3;
  const m = (L - 0.1055613458156586 * a - 0.0638541728258133 * b) ** 3;
  const s = (L - 0.0894841775298119 * a - 1.2914855480194092 * b) ** 3;
  const lin = [
    4.076741661347994 * l - 3.307711590408193 * m + 0.230969928729428 * s,
    -1.2684380040921763 * l + 2.6097574006633715 * m - 0.3413193963102197 * s,
    -0.004196086541837188 * l - 0.7034186144594493 * m + 1.7076147009309444 * s,
  ];
  return lin.map(linearToSrgb);
}

function labToSrgb([L, a, b]) {
  const k = 24389 / 27;
  const e = 216 / 24389;
  const fy = (L + 16) / 116;
  const fx = a / 500 + fy;
  const fz = fy - b / 200;
  const x = fx ** 3 > e ? fx ** 3 : (116 * fx - 16) / k;
  const y = L > k * e ? ((L + 16) / 116) ** 3 : L / k;
  const z = fz ** 3 > e ? fz ** 3 : (116 * fz - 16) / k;
  const xyzD50 = [x * D50[0], y * D50[1], z * D50[2]];
  return xyzD65ToSrgb(mul(D50_TO_D65, xyzD50));
}

function polarToRect([l, c, h]) {
  const rad = (h * Math.PI) / 180;
  return [l, c * Math.cos(rad), c * Math.sin(rad)];
}

function hslToSrgb([h, s, l]) {
  const hue = ((h % 360) + 360) % 360;
  const f = (n) => {
    const k = (n + hue / 30) % 12;
    const a = s * Math.min(l, 1 - l);
    return l - a * Math.max(-1, Math.min(k - 3, 9 - k, 1));
  };
  return [f(0), f(8), f(4)];
}

function hwbToSrgb([h, w, b]) {
  if (w + b >= 1) {
    const grey = w / (w + b);
    return [grey, grey, grey];
  }
  return hslToSrgb([h, 1, 0.5]).map((c) => c * (1 - w - b) + w);
}

// ---- Named colours --------------------------------------------------------

const NAMED = {
  aliceblue: 'f0f8ff', antiquewhite: 'faebd7', aqua: '00ffff', aquamarine: '7fffd4', azure: 'f0ffff',
  beige: 'f5f5dc', bisque: 'ffe4c4', black: '000000', blanchedalmond: 'ffebcd', blue: '0000ff',
  blueviolet: '8a2be2', brown: 'a52a2a', burlywood: 'deb887', cadetblue: '5f9ea0', chartreuse: '7fff00',
  chocolate: 'd2691e', coral: 'ff7f50', cornflowerblue: '6495ed', cornsilk: 'fff8dc', crimson: 'dc143c',
  cyan: '00ffff', darkblue: '00008b', darkcyan: '008b8b', darkgoldenrod: 'b8860b', darkgray: 'a9a9a9',
  darkgreen: '006400', darkgrey: 'a9a9a9', darkkhaki: 'bdb76b', darkmagenta: '8b008b', darkolivegreen: '556b2f',
  darkorange: 'ff8c00', darkorchid: '9932cc', darkred: '8b0000', darksalmon: 'e9967a', darkseagreen: '8fbc8f',
  darkslateblue: '483d8b', darkslategray: '2f4f4f', darkslategrey: '2f4f4f', darkturquoise: '00ced1',
  darkviolet: '9400d3', deeppink: 'ff1493', deepskyblue: '00bfff', dimgray: '696969', dimgrey: '696969',
  dodgerblue: '1e90ff', firebrick: 'b22222', floralwhite: 'fffaf0', forestgreen: '228b22', fuchsia: 'ff00ff',
  gainsboro: 'dcdcdc', ghostwhite: 'f8f8ff', gold: 'ffd700', goldenrod: 'daa520', gray: '808080',
  green: '008000', greenyellow: 'adff2f', grey: '808080', honeydew: 'f0fff0', hotpink: 'ff69b4',
  indianred: 'cd5c5c', indigo: '4b0082', ivory: 'fffff0', khaki: 'f0e68c', lavender: 'e6e6fa',
  lavenderblush: 'fff0f5', lawngreen: '7cfc00', lemonchiffon: 'fffacd', lightblue: 'add8e6', lightcoral: 'f08080',
  lightcyan: 'e0ffff', lightgoldenrodyellow: 'fafad2', lightgray: 'd3d3d3', lightgreen: '90ee90',
  lightgrey: 'd3d3d3', lightpink: 'ffb6c1', lightsalmon: 'ffa07a', lightseagreen: '20b2aa', lightskyblue: '87cefa',
  lightslategray: '778899', lightslategrey: '778899', lightsteelblue: 'b0c4de', lightyellow: 'ffffe0',
  lime: '00ff00', limegreen: '32cd32', linen: 'faf0e6', magenta: 'ff00ff', maroon: '800000',
  mediumaquamarine: '66cdaa', mediumblue: '0000cd', mediumorchid: 'ba55d3', mediumpurple: '9370db',
  mediumseagreen: '3cb371', mediumslateblue: '7b68ee', mediumspringgreen: '00fa9a', mediumturquoise: '48d1cc',
  mediumvioletred: 'c71585', midnightblue: '191970', mintcream: 'f5fffa', mistyrose: 'ffe4e1', moccasin: 'ffe4b5',
  navajowhite: 'ffdead', navy: '000080', oldlace: 'fdf5e6', olive: '808000', olivedrab: '6b8e23',
  orange: 'ffa500', orangered: 'ff4500', orchid: 'da70d6', palegoldenrod: 'eee8aa', palegreen: '98fb98',
  paleturquoise: 'afeeee', palevioletred: 'db7093', papayawhip: 'ffefd5', peachpuff: 'ffdab9', peru: 'cd853f',
  pink: 'ffc0cb', plum: 'dda0dd', powderblue: 'b0e0e6', purple: '800080', rebeccapurple: '663399',
  red: 'ff0000', rosybrown: 'bc8f8f', royalblue: '4169e1', saddlebrown: '8b4513', salmon: 'fa8072',
  sandybrown: 'f4a460', seagreen: '2e8b57', seashell: 'fff5ee', sienna: 'a0522d', silver: 'c0c0c0',
  skyblue: '87ceeb', slateblue: '6a5acd', slategray: '708090', slategrey: '708090', snow: 'fffafa',
  springgreen: '00ff7f', steelblue: '4682b4', tan: 'd2b48c', teal: '008080', thistle: 'd8bfd8',
  tomato: 'ff6347', turquoise: '40e0d0', violet: 'ee82ee', wheat: 'f5deb3', white: 'ffffff',
  whitesmoke: 'f5f5f5', yellow: 'ffff00', yellowgreen: '9acd32',
};

// ---- Numbers and arguments ------------------------------------------------

// Split the argument list of a colour function: commas and whitespace separate,
// a `/` introduces the alpha value.
function splitArgs(inner) {
  const parts = inner
    .replace(/,/g, ' ')
    .split('/')
    .map((s) => s.trim());
  const main = parts[0].split(/\s+/).filter(Boolean);
  const alpha = parts.length > 1 ? parts.slice(1).join('/').trim() : null;
  return { main, alpha };
}

// `50%` → 0.5·ref, `none` → 0, `0.4` → 0.4. Angles accept deg/rad/grad/turn.
function num(token, { percent = 1, angle = false } = {}) {
  if (token === undefined || token === null) return null;
  const t = token.trim().toLowerCase();
  if (t === 'none') return 0;
  if (t.endsWith('%')) return (parseFloat(t) / 100) * percent;
  if (angle) {
    if (t.endsWith('deg')) return parseFloat(t);
    if (t.endsWith('rad')) return (parseFloat(t) * 180) / Math.PI;
    if (t.endsWith('grad')) return parseFloat(t) * 0.9;
    if (t.endsWith('turn')) return parseFloat(t) * 360;
  }
  const n = parseFloat(t);
  return Number.isFinite(n) ? n : null;
}

function alphaOf(token) {
  if (token === null || token === undefined) return 1;
  const a = num(token, { percent: 1 });
  return a === null ? 1 : Math.min(1, Math.max(0, a));
}

function format([r, g, b], a) {
  const to8 = (c) => Math.max(0, Math.min(255, Math.round(c * 255)));
  const alpha = Math.round(a * 1000) / 1000;
  return `rgba(${to8(r)}, ${to8(g)}, ${to8(b)}, ${alpha})`;
}

// ---- A single colour ------------------------------------------------------

function fromHex(hex) {
  let h = hex;
  if (h.length === 3 || h.length === 4) h = [...h].map((c) => c + c).join('');
  if (h.length !== 6 && h.length !== 8) return null;
  const v = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16) / 255);
  const a = h.length === 8 ? parseInt(h.slice(6, 8), 16) / 255 : 1;
  return format(v, a);
}

function fromFunction(name, inner) {
  const { main, alpha } = splitArgs(inner);
  const a = alphaOf(alpha);
  switch (name) {
    case 'rgb':
    case 'rgba': {
      // Without `/`, a fourth value may be the alpha value (legacy comma syntax).
      const av = alpha === null && main.length === 4 ? alphaOf(main[3]) : a;
      const rgb = main.slice(0, 3).map((t) => (t.trim().endsWith('%') ? num(t, { percent: 1 }) : (num(t) ?? 0) / 255));
      return format(rgb, av);
    }
    case 'hsl':
    case 'hsla': {
      const av = alpha === null && main.length === 4 ? alphaOf(main[3]) : a;
      return format(hslToSrgb([num(main[0], { angle: true }) ?? 0, num(main[1], { percent: 1 }) ?? 0, num(main[2], { percent: 1 }) ?? 0]), av);
    }
    case 'hwb':
      return format(hwbToSrgb([num(main[0], { angle: true }) ?? 0, num(main[1], { percent: 1 }) ?? 0, num(main[2], { percent: 1 }) ?? 0]), a);
    case 'lab':
      return format(labToSrgb([num(main[0], { percent: 100 }) ?? 0, num(main[1], { percent: 125 }) ?? 0, num(main[2], { percent: 125 }) ?? 0]), a);
    case 'lch':
      return format(labToSrgb(polarToRect([num(main[0], { percent: 100 }) ?? 0, num(main[1], { percent: 150 }) ?? 0, num(main[2], { angle: true }) ?? 0])), a);
    case 'oklab':
      return format(oklabToSrgb([num(main[0], { percent: 1 }) ?? 0, num(main[1], { percent: 0.4 }) ?? 0, num(main[2], { percent: 0.4 }) ?? 0]), a);
    case 'oklch':
      return format(oklabToSrgb(polarToRect([num(main[0], { percent: 1 }) ?? 0, num(main[1], { percent: 0.4 }) ?? 0, num(main[2], { angle: true }) ?? 0])), a);
    case 'color': {
      const space = (main[0] ?? '').toLowerCase();
      const v = main.slice(1, 4).map((t) => num(t, { percent: 1 }) ?? 0);
      while (v.length < 3) v.push(0);
      if (space === 'srgb') return format(v, a);
      if (space === 'srgb-linear') return format(v.map(linearToSrgb), a);
      if (space === 'display-p3') return format(xyzD65ToSrgb(mul(LIN_P3_TO_XYZ, v.map(srgbToLinear))), a);
      if (space === 'a98-rgb') return format(xyzD65ToSrgb(mul(LIN_A98_TO_XYZ, v.map(a98ToLinear))), a);
      if (space === 'prophoto-rgb') return format(xyzD65ToSrgb(mul(D50_TO_D65, mul(LIN_PROPHOTO_TO_XYZ_D50, v.map(prophotoToLinear)))), a);
      if (space === 'rec2020') return format(xyzD65ToSrgb(mul(LIN_REC2020_TO_XYZ, v.map(rec2020ToLinear))), a);
      if (space === 'xyz' || space === 'xyz-d65') return format(xyzD65ToSrgb(v), a);
      if (space === 'xyz-d50') return format(xyzD65ToSrgb(mul(D50_TO_D65, v)), a);
      return null;
    }
    default:
      return null;
  }
}

// Normalise a complete colour value; null when it isn't a colour.
export function normaliseColor(value) {
  const v = String(value).trim();
  const lower = v.toLowerCase();
  if (lower === 'transparent') return format([0, 0, 0], 0);
  if (Object.prototype.hasOwnProperty.call(NAMED, lower)) return fromHex(NAMED[lower]);
  if (v.startsWith('#')) return fromHex(v.slice(1).toLowerCase());
  const m = lower.match(/^(rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch|color)\(([\s\S]*)\)$/);
  if (!m) return null;
  return fromFunction(m[1], m[2]);
}

const COLOR_FN = /\b(rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\(/gi;
const HEX = /#[0-9a-f]{3,8}\b/gi;
const NAMED_RE = new RegExp(`\\b(${[...Object.keys(NAMED), 'transparent'].join('|')})\\b`, 'gi');

// Find the matching parenthesis for the opening one at position `open`.
function matchParen(text, open) {
  let depth = 0;
  for (let i = open; i < text.length; i += 1) {
    if (text[i] === '(') depth += 1;
    else if (text[i] === ')') {
      depth -= 1;
      if (depth === 0) return i;
    }
  }
  return -1;
}

// Replace every colour expression in a property value with `rgba(r, g, b, a)`.
export function normaliseColorsInValue(value) {
  if (typeof value !== 'string' || !value) return value;
  let out = '';
  let i = 0;
  COLOR_FN.lastIndex = 0;
  let m;
  while ((m = COLOR_FN.exec(value))) {
    const start = m.index;
    const open = start + m[0].length - 1;
    const close = matchParen(value, open);
    if (close < 0) break;
    const whole = value.slice(start, close + 1);
    const replaced = normaliseColor(whole);
    out += value.slice(i, start) + (replaced ?? whole);
    i = close + 1;
    COLOR_FN.lastIndex = i;
  }
  out += value.slice(i);
  out = out.replace(HEX, (hex) => normaliseColor(hex) ?? hex);
  // Replace named colours only when they stand alone. `\b` alone isn't enough:
  // in `url("/a/linen.png")`, `linen` sits between two word boundaries and still
  // isn't a colour. So the neighbouring characters must belong neither to an
  // identifier nor to a path.
  const NOT_ADJACENT = new Set(['-', '_', '.', '/', '\\', '#', '%', ':', '@', '(']);
  out = out.replace(NAMED_RE, (name, _g, offset, full) => {
    const before = full[offset - 1];
    const after = full[offset + name.length];
    if ((before && NOT_ADJACENT.has(before)) || (after && NOT_ADJACENT.has(after))) return name;
    return normaliseColor(name) ?? name;
  });
  return out;
}
