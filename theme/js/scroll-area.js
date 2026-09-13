// scroll-area.js — port of Base UI `ScrollArea` (@base-ui/react 1.8.0) to the
// extent Fumadocs uses it: `components/ui/scroll-area.tsx` renders
// root, viewport, corner and exactly one vertical scrollbar with one thumb.
//
// Sources: scroll-area/root/ScrollAreaRoot.js, viewport/ScrollAreaViewport.js
// (computeThumbPosition), scrollbar/ScrollAreaScrollbar.js, thumb/ScrollAreaThumb.js,
// scroll-area/constants.js (SCROLL_TIMEOUT = 500, MIN_THUMB_SIZE = 16).
//
// Measured behaviour: the scrollbar is **not hidden with a delay**.
// `data-hovering`, and with it the visibility (`.nd-scroll-bar:not([data-hovering])`),
// follows the pointer events on the root immediately; the fade-out comes from
// `transition-opacity` alone. The 500 ms of `SCROLL_TIMEOUT` apply to the
// `data-scrolling` attribute, not to visibility.
//
// Also measured: the scrollbar exists in the DOM only while the viewport
// overflows (`shouldRender = keepMounted || !hiddenState.y`). The frozen
// reference DOM at 1440x900 therefore has none.

export const SCROLL_TIMEOUT = 500;
export const MIN_THUMB_SIZE = 16;

/**
 * Classes of the elements this module creates itself (verify/CLASS-MAP.md).
 * Visibility is not switched through classes: `.nd-scroll-bar:not([data-hovering])`
 * hides the track, the module only sets `data-hovering`.
 */
export const CLASSES = {
  scrollbar: 'nd-scroll-bar',
  thumb: 'nd-scroll-thumb',
};

/** getOffset() from scroll-area/utils/getOffset.js. */
function getOffset(el, prop, axis) {
  if (!el) return 0;
  const styles = getComputedStyle(el);
  const key = `${prop}${axis === 'x' ? 'Inline' : 'Block'}`;
  const start = parseFloat(styles[`${key}Start`]);
  if (axis === 'x' && prop === 'margin') return start * 2;
  return start + parseFloat(styles[`${key}End`]);
}

const clamp = (v, min, max) => (v < min ? min : v > max ? max : v);

const OVERFLOW_EDGE_VARS = [
  '--scroll-area-overflow-x-start', '--scroll-area-overflow-x-end',
  '--scroll-area-overflow-y-start', '--scroll-area-overflow-y-end',
];
let overflowVarsRegistered = false;

/**
 * removeCSSVariableInheritance() from ScrollAreaViewport.js: the four overflow
 * variables don't inherit, otherwise every child of the viewport would take them over.
 * Base UI skips this optimisation in WebKit.
 */
function registerOverflowVars() {
  if (overflowVarsRegistered) return;
  overflowVarsRegistered = true;
  const webkit = /AppleWebKit/.test(navigator.userAgent) && !/Chrome|Chromium/.test(navigator.userAgent);
  if (webkit || typeof CSS === 'undefined' || !('registerProperty' in CSS)) return;
  for (const name of OVERFLOW_EDGE_VARS) {
    try {
      CSS.registerProperty({ name, syntax: '<length>', inherits: false, initialValue: '0px' });
    } catch { /* already registered */ }
  }
}

export class ScrollArea {
  /**
   * @param {HTMLElement} root  the element with `role="presentation"` and `position:relative`
   * @param {{overflowEdgeThreshold?: number}} [options]
   */
  constructor(root, options = {}) {
    this.root = root;
    this.viewport = root.querySelector(':scope > [data-id$="-viewport"]') || root.firstElementChild;
    this.rootId = (this.viewport?.dataset.id || '').replace(/-viewport$/, '');
    this.threshold = options.overflowEdgeThreshold ?? 0;

    this.hovering = false;
    this.scrollingY = false;
    this.touchModality = false;
    this.hasMeasured = false;
    this.hiddenY = true;
    this.thumbHeight = 0;
    this.scrollTimer = 0;
    this.scrollEndTimer = 0;
    this.programmaticScroll = true;
    this.activePointerId = null;
    this.startY = 0;
    this.startScrollTop = 0;

    this.scrollbar = null;
    this.thumb = null;

    registerOverflowVars();
    this.bind();
    queueMicrotask(() => this.compute());
    if (typeof ResizeObserver !== 'undefined' && this.viewport) {
      this.observer = new ResizeObserver(() => this.compute());
      this.observer.observe(this.viewport);
    }
  }

  static attach(root, options) {
    if (!root || root.__ndScrollArea) return root?.__ndScrollArea ?? null;
    const instance = new ScrollArea(root, options);
    root.__ndScrollArea = instance;
    return instance;
  }

  bind() {
    const root = this.root;
    const onEnterOrMove = (event) => {
      this.touchModality = event.pointerType === 'touch';
      if (event.pointerType !== 'touch') this.setHovering(root.contains(event.target));
    };
    root.addEventListener('pointerenter', onEnterOrMove);
    root.addEventListener('pointermove', onEnterOrMove);
    root.addEventListener('pointerdown', (event) => { this.touchModality = event.pointerType === 'touch'; });
    root.addEventListener('pointerleave', () => this.setHovering(false));

    if (!this.viewport) return;
    const userInteraction = () => { this.programmaticScroll = false; };
    this.viewport.addEventListener('wheel', userInteraction, { passive: true });
    this.viewport.addEventListener('pointermove', userInteraction);
    this.viewport.addEventListener('pointerenter', userInteraction);
    this.viewport.addEventListener('keydown', userInteraction);
    this.viewport.addEventListener('scroll', () => {
      this.compute();
      if (this.touchModality || !this.programmaticScroll) this.startScrolling();
      clearTimeout(this.scrollEndTimer);
      this.scrollEndTimer = setTimeout(() => { this.programmaticScroll = true; }, 100);
    });
    if (this.viewport.matches(':hover')) this.setHovering(true);
  }

  setHovering(value) {
    if (this.hovering === value) return;
    this.hovering = value;
    this.applyScrollbarState();
  }

  startScrolling() {
    if (!this.scrollingY) {
      this.scrollingY = true;
      this.applyScrollingState();
    }
    clearTimeout(this.scrollTimer);
    this.scrollTimer = setTimeout(() => {
      this.scrollingY = false;
      this.applyScrollingState();
    }, SCROLL_TIMEOUT);
  }

  applyScrollingState() {
    for (const el of [this.root, this.viewport, this.scrollbar, this.thumb]) {
      if (!el) continue;
      if (this.scrollingY) el.setAttribute('data-scrolling', '');
      else el.removeAttribute('data-scrolling');
    }
  }

  applyScrollbarState() {
    if (!this.scrollbar) return;
    if (this.hovering) this.scrollbar.setAttribute('data-hovering', '');
    else this.scrollbar.removeAttribute('data-hovering');
  }

  // ---- Create / remove the scrollbar ---------------------------------------

  ensureScrollbar() {
    if (this.scrollbar) return;
    const bar = document.createElement('div');
    bar.className = CLASSES.scrollbar;
    if (this.rootId) bar.setAttribute('data-id', `${this.rootId}-scrollbar`);
    bar.setAttribute('aria-hidden', 'true');
    bar.setAttribute('data-orientation', 'vertical');
    bar.style.position = 'absolute';
    bar.style.touchAction = 'none';
    bar.style.webkitUserSelect = 'none';
    bar.style.userSelect = 'none';
    bar.style.top = '0px';
    bar.style.bottom = 'var(--scroll-area-corner-height)';
    bar.style.insetInlineEnd = '0px';
    if (!this.hasMeasured) bar.style.visibility = 'hidden';

    const thumb = document.createElement('div');
    thumb.setAttribute('data-orientation', 'vertical');
    thumb.className = CLASSES.thumb;
    thumb.style.height = 'var(--scroll-area-thumb-height)';
    if (!this.hasMeasured) thumb.style.visibility = 'hidden';
    bar.appendChild(thumb);

    this.root.appendChild(bar);
    this.scrollbar = bar;
    this.thumb = thumb;
    this.applyScrollbarState();
    this.bindScrollbar();
  }

  removeScrollbar() {
    this.scrollbar?.remove();
    this.scrollbar = null;
    this.thumb = null;
  }

  bindScrollbar() {
    const bar = this.scrollbar;
    const thumb = this.thumb;
    const viewport = this.viewport;

    bar.addEventListener('wheel', (event) => {
      if (event.ctrlKey || !viewport) return;
      const delta = event.deltaY;
      if (delta === 0) return;
      const maxScroll = viewport.scrollHeight - viewport.clientHeight;
      const value = viewport.scrollTop;
      if ((value <= 0 && delta < 0) || (value >= maxScroll && delta > 0)) return;
      event.preventDefault();
      viewport.scrollTop = Math.min(maxScroll, Math.max(0, value + delta));
      this.startScrolling();
    }, { passive: false });

    // A click on the track jumps, a click on the thumb drags.
    bar.addEventListener('pointerdown', (event) => {
      if (event.button !== 0) return;
      if (thumb.contains(event.target)) { this.beginDrag(event); return; }
      const thumbOffset = getOffset(thumb, 'margin', 'y');
      const barOffset = getOffset(bar, 'padding', 'y');
      const thumbSize = thumb.offsetHeight;
      const rect = bar.getBoundingClientRect();
      const clickPosition = event.clientY - rect.top - thumbSize / 2 - barOffset + thumbOffset / 2;
      const maxThumbOffset = bar.offsetHeight - thumbSize - barOffset - thumbOffset;
      if (maxThumbOffset <= 0) return;
      viewport.scrollTop = (clickPosition / maxThumbOffset) * (viewport.scrollHeight - viewport.clientHeight);
      this.beginDrag(event);
    });
    bar.addEventListener('mousedown', (event) => event.preventDefault());
    bar.addEventListener('pointerup', (event) => this.endDrag(event));
    bar.addEventListener('pointercancel', (event) => this.endDrag(event));
    thumb.addEventListener('pointerdown', (event) => { if (event.button === 0) this.beginDrag(event); });
    thumb.addEventListener('pointermove', (event) => this.dragMove(event));
    thumb.addEventListener('pointerup', (event) => this.endDrag(event));
    thumb.addEventListener('pointercancel', (event) => this.endDrag(event));
  }

  beginDrag(event) {
    if (this.activePointerId !== null && this.thumb?.hasPointerCapture(this.activePointerId)) return;
    this.activePointerId = event.pointerId;
    this.startY = event.clientY;
    this.startScrollTop = this.viewport.scrollTop;
    this.thumb?.setPointerCapture(event.pointerId);
  }

  dragMove(event) {
    if (event.pointerId !== this.activePointerId) return;
    if (event.buttons % 2 === 0) { this.endDrag(event); return; }
    const bar = this.scrollbar;
    const thumb = this.thumb;
    const viewport = this.viewport;
    if (!bar || !thumb || !viewport) return;
    const barOffset = getOffset(bar, 'padding', 'y');
    const thumbOffset = getOffset(thumb, 'margin', 'y');
    const maxThumbOffset = bar.offsetHeight - thumb.offsetHeight - barOffset - thumbOffset;
    const delta = event.clientY - this.startY;
    const scrollRatio = maxThumbOffset <= 0 ? 0 : delta / maxThumbOffset;
    viewport.scrollTop = this.startScrollTop + scrollRatio * (viewport.scrollHeight - viewport.clientHeight);
    event.preventDefault();
    this.startScrolling();
  }

  endDrag(event) {
    if (event.pointerId !== this.activePointerId) return;
    this.activePointerId = null;
    this.scrollingY = false;
    clearTimeout(this.scrollTimer);
    this.applyScrollingState();
    if (this.thumb?.hasPointerCapture(event.pointerId)) this.thumb.releasePointerCapture(event.pointerId);
  }

  // ---- Measurement (computeThumbPosition) ----------------------------------

  compute() {
    const viewport = this.viewport;
    if (!viewport) return;
    const contentHeight = viewport.scrollHeight;
    const contentWidth = viewport.scrollWidth;
    const viewportHeight = viewport.clientHeight;
    const scrollTop = viewport.scrollTop;
    if (!this.hasMeasured) this.hasMeasured = true;
    if (contentHeight === 0 || contentWidth === 0) return;

    const hiddenY = viewportHeight >= contentHeight;
    if (hiddenY !== this.hiddenY) {
      this.hiddenY = hiddenY;
      if (hiddenY) this.removeScrollbar();
      else this.ensureScrollbar();
      this.applyOverflowAttributes(hiddenY);
      viewport.tabIndex = hiddenY ? -1 : 0;
    } else if (!hiddenY) {
      this.ensureScrollbar();
    }
    if (hiddenY) {
      this.setOverflowVars(0, 0);
      return;
    }

    const bar = this.scrollbar;
    const thumb = this.thumb;
    if (bar) bar.style.visibility = '';
    if (thumb) thumb.style.visibility = '';

    const ratioY = viewportHeight / contentHeight;
    const maxScrollTop = Math.max(0, contentHeight - viewportHeight);
    const fromStart = clamp(scrollTop, 0, maxScrollTop);
    const fromEnd = maxScrollTop - fromStart;

    const barOffset = getOffset(bar, 'padding', 'y');
    const thumbOffset = getOffset(thumb, 'margin', 'y');
    const idealHeight = viewportHeight - barOffset - thumbOffset;
    const maxHeight = bar ? Math.min(bar.offsetHeight, idealHeight) : idealHeight;
    const thumbHeight = Math.max(MIN_THUMB_SIZE, maxHeight * ratioY);
    this.thumbHeight = thumbHeight;
    if (bar) bar.style.setProperty('--scroll-area-thumb-height', `${thumbHeight}px`);

    if (bar && thumb) {
      const maxThumbOffset = bar.offsetHeight - thumbHeight - barOffset - thumbOffset;
      const offsetY = maxScrollTop ? (fromStart / maxScrollTop) * maxThumbOffset : 0;
      thumb.style.transform = `translate3d(0,${offsetY}px,0)`;
    }

    this.setOverflowVars(fromStart, fromEnd);
    this.applyEdgeAttributes(fromStart > this.threshold, fromEnd > this.threshold);
  }

  setOverflowVars(fromStart, fromEnd) {
    const v = this.viewport;
    if (!v) return;
    v.style.setProperty('--scroll-area-overflow-x-start', '0px');
    v.style.setProperty('--scroll-area-overflow-x-end', '0px');
    v.style.setProperty('--scroll-area-overflow-y-start', `${fromStart}px`);
    v.style.setProperty('--scroll-area-overflow-y-end', `${fromEnd}px`);
  }

  applyOverflowAttributes(hiddenY) {
    for (const el of [this.root, this.viewport, this.scrollbar]) {
      if (!el) continue;
      if (hiddenY) el.removeAttribute('data-has-overflow-y');
      else el.setAttribute('data-has-overflow-y', '');
    }
  }

  applyEdgeAttributes(start, end) {
    for (const el of [this.root, this.viewport, this.scrollbar]) {
      if (!el) continue;
      el.toggleAttribute('data-overflow-y-start', start);
      el.toggleAttribute('data-overflow-y-end', end);
    }
  }
}

export function attachScrollAreas(scope, selector) {
  return [...scope.querySelectorAll(selector)].map((el) => ScrollArea.attach(el)).filter(Boolean);
}

/**
 * Entry point for notebook.js. The sidebar's scroll areas are bound by
 * `sidebar.js`; only areas outside of it remain here.
 */
export function boot(doc = document) {
  for (const viewport of doc.querySelectorAll('[data-id$="-viewport"]')) {
    if (viewport.closest('#nd-sidebar, #nd-sidebar-mobile')) continue;
    if (viewport.parentElement) ScrollArea.attach(viewport.parentElement);
  }
}
