// toc.js — table of contents: active headings and the clerk thumb.
//
// Parts:
//   TOCObserver        – IntersectionObserver, threshold 0.9, single=false, fallback,
//                        timestamp `t`; entries get data-active, the most recently
//                        activated entry is scrolled into view, initially `instant`,
//                        then `smooth`
//   clerk thumb        – SVG path from offsetTop/clientHeight/paddings via
//                        ResizeObserver, --track-top/--track-bottom,
//                        clip-path with transition-[clip-path]
//
// The SVGs of the individual entries depend only on the depths and are
// therefore already in the HTML; only the moving thumb is created here.

import { scrollIntoViewIfNeeded } from './scroll-into-view.js';

/** Classes of the clerk thumb this module creates itself. */
export const CLASSES = {
  thumbTrack: 'nd-toc-thumb',
  thumbSvg: 'nd-toc-thumb-svg',
  thumbPath: 'nd-toc-thumb-path',
};

export const THRESHOLD = 0.9;
const SVG_NS = 'http://www.w3.org/2000/svg';

/** Horizontal offset of the line per heading depth (base 8). */
export function getLineOffset(depth) {
  if (depth <= 2) return 8;
  if (depth === 3) return 20;
  return 32;
}

/** Heading observer: which headings are active while the page scrolls. */
export class TOCObserver {
  constructor() {
    this.items = [];
    this.single = false;
    this.observer = null;
    this.listeners = new Set();
  }

  listen(listener) { this.listeners.add(listener); }
  unlisten(listener) { this.listeners.delete(listener); }

  setItems(ids) {
    if (this.observer) {
      for (const item of this.items) {
        const el = document.getElementById(item.id);
        if (el) this.observer.unobserve(el);
      }
    }
    this.update(ids.map((id) => ({ id, active: false, fallback: false, t: 0 })));
    this.observeItems();
  }

  watch(options) {
    if (this.observer) return;
    this.observer = new IntersectionObserver((entries) => this.callback(entries), options);
    this.observeItems();
  }

  unwatch() {
    this.observer?.disconnect();
    this.observer = null;
  }

  callback(entries) {
    if (entries.length === 0) return;
    let hasActive = false;
    const updated = this.items.map((item) => {
      const entry = entries.find((e) => e.target.id === item.id);
      let active = entry ? entry.isIntersecting : item.active && !item.fallback;
      if (this.single && hasActive) active = false;
      if (item.active !== active) item = { ...item, t: Date.now(), active, fallback: false };
      if (active) hasActive = true;
      return item;
    });
    // No visible heading: the one with the smallest distance to the top edge.
    if (!hasActive && entries[0].rootBounds) {
      const viewTop = entries[0].rootBounds.top;
      let min = Number.MAX_VALUE;
      let fallbackIdx = -1;
      for (let i = 0; i < updated.length; i++) {
        const el = document.getElementById(updated[i].id);
        if (!el) continue;
        const d = Math.abs(viewTop - el.getBoundingClientRect().top);
        if (d < min) { fallbackIdx = i; min = d; }
      }
      if (fallbackIdx !== -1) {
        updated[fallbackIdx] = { ...updated[fallbackIdx], active: true, fallback: true, t: Date.now() };
      }
    }
    this.update(updated);
  }

  observeItems() {
    if (!this.observer) return;
    for (const item of this.items) {
      const el = document.getElementById(item.id);
      if (el) this.observer.observe(el);
    }
  }

  update(next) {
    this.items = next;
    for (const listener of this.listeners) listener(next);
  }
}

/** One list of TOC entries: data-active, auto scroll and clerk thumb. */
class TOCList {
  /**
   * @param {HTMLElement} container   the element that holds the links
   * @param {HTMLElement|null} scroller  the scrolling area (TOCScrollArea)
   */
  constructor(container, scroller, observer) {
    this.container = container;
    this.scroller = scroller;
    this.observer = observer;
    this.links = [...container.querySelectorAll('a[href^="#"]')];
    this.ids = this.links.map((a) => a.getAttribute('href').slice(1));
    this.track = null;
    this.computed = null;
    this.initial = true;

    this.print();
    if (typeof ResizeObserver !== 'undefined') {
      this.resize = new ResizeObserver(() => this.print());
      this.resize.observe(container);
    }
    this.listener = (items) => this.onUpdate(items);
    observer.listen(this.listener);
    this.onUpdate(observer.items);
  }

  /** On unmount of the panel (TOC popover) release observer and listener. */
  destroy() {
    this.observer.unlisten(this.listener);
    this.resize?.disconnect();
  }

  /** onPrint() from clerk.js: path, width, height and the positions per entry. */
  print() {
    const container = this.container;
    if (!container || container.clientHeight === 0) return;
    if (this.links.length === 0) { this.removeTrack(); return; }
    let w = 0;
    let h = 0;
    let d = '';
    const positions = [];
    for (let i = 0; i < this.links.length; i++) {
      const el = this.links[i];
      const styles = getComputedStyle(el);
      const x = getLineOffset(this.depthOf(el)) + 0.5;
      const top = el.offsetTop + parseFloat(styles.paddingTop);
      const bottom = el.offsetTop + el.clientHeight - parseFloat(styles.paddingBottom);
      w = Math.max(x + 8, w);
      h = Math.max(h, bottom);
      if (i === 0) {
        d += ` M${x} ${top} L${x} ${bottom}`;
      } else {
        const [, upperBottom, upperX] = positions[i - 1];
        d += ` L ${upperX} ${upperBottom} ${x} ${top} L${x} ${bottom}`;
      }
      positions.push([top, bottom, x]);
    }
    this.computed = { width: w, height: h, d, positions };
    this.renderTrack();
  }

  /**
   * The depth is not in the DOM; it follows from the `padding-inline-start`
   * of the entry (getItemOffset: 20 / 32 / 44 px for depth ≤2 / 3 / ≥4).
   */
  depthOf(el) {
    const start = Math.round(parseFloat(getComputedStyle(el).paddingInlineStart));
    if (start >= 44) return 4;
    if (start >= 32) return 3;
    return 2;
  }

  renderTrack() {
    const { width, height, d } = this.computed;
    // Only this module creates the thumb: path and height depend on the
    // measured offsetTop/clientHeight of the entries, i.e. on line wrapping
    // with the actual font metrics; static HTML cannot know that, so the generator
    // doesn't emit it. If one is already there anyway, it is adopted instead of
    // creating a second one.
    if (!this.track) {
      const existing = this.container.firstElementChild;
      if (existing && existing.tagName === 'DIV' && existing.querySelector(':scope > svg')) {
        this.track = existing;
      }
    }
    if (!this.track) {
      const wrapper = document.createElement('div');
      wrapper.className = CLASSES.thumbTrack;
      const svg = document.createElementNS(SVG_NS, 'svg');
      svg.setAttribute('xmlns', SVG_NS);
      svg.setAttribute('class', CLASSES.thumbSvg);
      // Order of the inline properties: sizes first.
      svg.style.width = '0px';
      svg.style.height = '0px';
      svg.style.clipPath = 'polygon(0 var(--track-top,0), 100% var(--track-top,0), 100% var(--track-bottom,0), 0 var(--track-bottom,0))';
      const path = document.createElementNS(SVG_NS, 'path');
      path.setAttribute('class', CLASSES.thumbPath);
      path.setAttribute('stroke-width', '1');
      path.setAttribute('fill', 'none');
      svg.appendChild(path);
      wrapper.appendChild(svg);
      this.container.insertBefore(wrapper, this.container.firstChild);
      this.track = wrapper;
    }
    const svg = this.track.querySelector(':scope > svg');
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    svg.style.width = `${width}px`;
    svg.style.height = `${height}px`;
    svg.querySelector('path').setAttribute('d', d);
    this.track.style.width = `${width}px`;
    this.track.style.height = `${height}px`;
    this.applyTrackVars(this.observer.items);
  }

  removeTrack() {
    this.track?.remove();
    this.track = null;
  }

  /** calculate() from ThumbTrack: first and last active entry. */
  applyTrackVars(items) {
    if (!this.track || !this.computed) return;
    const active = this.ids.map((id) => items.find((i) => i.id === id)?.active === true);
    const startIdx = active.indexOf(true);
    if (startIdx === -1) return;
    const endIdx = active.lastIndexOf(true);
    this.track.style.setProperty('--track-top', `${this.computed.positions[startIdx][0]}px`);
    this.track.style.setProperty('--track-bottom', `${this.computed.positions[endIdx][1]}px`);
  }

  onUpdate(items) {
    let lastActive = null;
    for (const item of items) {
      if (!item.active) continue;
      if (!lastActive || lastActive.t < item.t) lastActive = item;
    }
    this.links.forEach((link, i) => {
      const item = items.find((it) => it.id === this.ids[i]);
      if (!item) return;
      link.setAttribute('data-active', String(item.active));
      if (this.scroller && lastActive && lastActive.id === this.ids[i]) {
        scrollIntoViewIfNeeded(link, {
          behavior: this.initial ? 'instant' : 'smooth',
          block: 'center',
          inline: 'center',
          scrollMode: 'always',
          boundary: this.scroller,
        });
      }
    });
    this.applyTrackVars(items);
    this.initial = false;
  }
}

export class TOC {
  constructor(doc = document, observer = new TOCObserver()) {
    this.observer = observer;
    this.lists = [];
    const containers = [...doc.querySelectorAll('#nd-toc, [data-toc-popover-content]')];
    const ids = [];
    for (const root of containers) {
      const list = this.addList(root);
      if (!list) continue;
      for (const id of list.ids) if (!ids.includes(id)) ids.push(id);
    }
    this.observer.setItems(ids);
    this.observer.watch({ threshold: THRESHOLD });
  }

  /**
   * Attaches another entry list to the same observer. Needed for the TOC
   * popover: its panel is mounted only on opening and has its
   * own entries inside (data-active, auto scroll, clerk thumb).
   */
  addList(root) {
    const first = root.querySelector('a[href^="#"]');
    if (!first) return null;
    const container = first.parentElement;
    const existing = this.lists.find((l) => l.container === container);
    if (existing) return existing;
    const list = new TOCList(container, container.parentElement, this.observer);
    this.lists.push(list);
    return list;
  }

  removeList(list) {
    if (!list) return;
    list.destroy();
    this.lists = this.lists.filter((l) => l !== list);
  }

  static attach(doc) {
    // The flag is set **before** construction: notebook.js loads toc.js and
    // toc-popover.js together, and both call attach(). Without this lock
    // there would be two observers and two thumb SVGs in the TOC.
    if (document.__ndToc) return document.__ndToc;
    const pending = { observer: new TOCObserver(), lists: [] };
    document.__ndToc = pending;
    const instance = new TOC(doc ?? document, pending.observer);
    document.__ndToc = instance;
    return instance;
  }
}

export default TOC;

/** Entry point for notebook.js. */
export function boot(doc = document) {
  if (!(doc.getElementById('nd-toc') || doc.querySelector('[data-toc-popover]'))) return;
  TOC.attach(doc);
}
