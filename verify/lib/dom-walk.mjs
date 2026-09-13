// The normalised element tree the computed-style comparison needs.
//
// The normalisation is the same as in golden-dom.mjs: the same dropped elements
// (Next.js scripts, preloads, dev overlay, non-content <meta>), so stage 1 and
// stage 2 see the same tree. Only element nodes count; text nodes have no computed
// styles.
//
// Unlike golden-dom.mjs, the normalisation runs in the browser here, because
// getComputedStyle() only exists there. The rules are therefore kept as a string and
// injected with page.evaluate; golden-dom.mjs stays independent.
//
// <head> is dropped as well: its children are never rendered, their computed styles
// are meaningless, and the reference app's dev server injects <style> blocks there
// that the candidate can't have. `--with-head` turns this off.

// Runtime nodes of the Next.js dev server that only exist in the reference app:
// `nextjs-portal` holds the dev tools badge in the bottom left, `next-route-announcer`
// is the invisible live region for route changes and is the last child of <body>.
// The candidate can't and shouldn't have either. computed-style.mjs drops the nodes
// before pairing, pixel-diff.mjs hides them before every screenshot.
export const NEXT_RUNTIME_TAGS = ['nextjs-portal', 'next-route-announcer'];

// Rules from golden-dom.mjs, plus the route announcer.
export const DROP_TAGS = ['script', 'noscript', 'template', 'title', ...NEXT_RUNTIME_TAGS];
export const META_KEEP = ['charset', 'viewport', 'description'];
export const DROP_LINK_REL = ['preload', 'stylesheet', 'modulepreload', 'prefetch', 'preconnect', 'dns-prefetch'];

// The browser part: collects the kept elements in document order and reads the
// requested properties plus getBoundingClientRect() for each element.
//
// The result is column-oriented on purpose (arrays instead of one object per element),
// because a document easily has 1500 elements × 80 values and would otherwise be ten
// times as large.
export const COLLECT_FN = `(options) => {
  const { properties, customProperties, dropTags, metaKeep, dropLinkRel, withHead, dropStyleTags, scope } = options;
  const drop = new Set(dropTags);
  const keepMeta = new Set(metaKeep);
  const dropRel = new Set(dropLinkRel);

  function shouldDrop(el) {
    const tag = el.tagName.toLowerCase();
    if (drop.has(tag)) return true;
    if (tag === 'head' && !withHead) return true;
    if (tag === 'style' && dropStyleTags) return true;
    if (tag === 'meta') {
      if (el.hasAttribute('charset')) return false;
      return !keepMeta.has((el.getAttribute('name') || '').toLowerCase());
    }
    if (tag === 'link') {
      const rels = (el.getAttribute('rel') || '').toLowerCase().split(/\\s+/).filter(Boolean);
      if (rels.some((r) => dropRel.has(r))) return true;
    }
    for (const a of el.attributes) if (a.name.toLowerCase().startsWith('data-nextjs')) return true;
    return false;
  }

  // Label as in golden-dom.mjs, but without hydration ids in the text:
  // tag#id.firstClass, for orientation in the report only, not for comparison.
  function label(el) {
    const tag = el.tagName.toLowerCase();
    const id = el.getAttribute('id');
    const cls = (el.getAttribute('class') || '').split(/\\s+/).filter(Boolean)[0];
    const idPart = id ? '#' + id.replace(/_R_[0-9a-z]*_/g, '_R_') : '';
    const clsPart = cls ? '.' + cls.replace(/[^\\w-]/g, '_') : '';
    return tag + idPart + clsPart;
  }

  const root = scope ? document.querySelector(scope) : document.documentElement;
  if (!root) return { missingScope: true };

  const tags = [];
  const paths = [];
  const rows = [];

  function visit(el, parentPath, index) {
    const path = parentPath === null ? label(el) : parentPath + ' > ' + label(el) + ':nth-child(' + (index + 1) + ')';
    tags.push(el.tagName.toLowerCase());
    paths.push(path);

    const cs = getComputedStyle(el);
    const row = new Array(properties.length + customProperties.length + 4);
    for (let i = 0; i < properties.length; i += 1) row[i] = cs.getPropertyValue(properties[i]);
    for (let i = 0; i < customProperties.length; i += 1) {
      row[properties.length + i] = cs.getPropertyValue(customProperties[i]).trim();
    }
    const r = el.getBoundingClientRect();
    const base = properties.length + customProperties.length;
    row[base] = r.x; row[base + 1] = r.y; row[base + 2] = r.width; row[base + 3] = r.height;
    rows.push(row);

    let kept = 0;
    for (const child of el.children) {
      if (shouldDrop(child)) continue;
      visit(child, path, kept);
      kept += 1;
    }
  }

  visit(root, null, 0);
  return { tags, paths, rows };
}`;

// Build the COLLECT_FN options from the tool flags.
export function collectOptions({ properties, customProperties, withHead = false, dropStyleTags = false, scope = '' }) {
  return {
    properties,
    customProperties,
    dropTags: DROP_TAGS,
    metaKeep: META_KEEP,
    dropLinkRel: DROP_LINK_REL,
    withHead,
    dropStyleTags,
    scope,
  };
}

// First index at which the tag sequences differ, or null.
// Structure is stage 1's job (golden-dom.mjs); it is only checked here so that an
// offset doesn't show up as a thousand style differences.
export function firstStructureMismatch(refTags, candTags, refPaths, candPaths) {
  const n = Math.min(refTags.length, candTags.length);
  for (let i = 0; i < n; i += 1) {
    if (refTags[i] !== candTags[i]) {
      return { index: i, reference: refPaths[i], candidate: candPaths[i], kind: 'tag' };
    }
  }
  if (refTags.length !== candTags.length) {
    return {
      index: n,
      reference: refPaths[n] ?? null,
      candidate: candPaths[n] ?? null,
      kind: refTags.length > candTags.length ? 'missing' : 'extra',
    };
  }
  return null;
}
