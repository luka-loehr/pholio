// scroll-into-view.js — replacement for the packages `scroll-into-view-if-needed` and
// `compute-scroll-into-view`, which Fumadocs uses for the auto scroll of the sidebar
// (components/sidebar/base.js → useAutoScroll) and of the table of contents
// (fumadocs-core/toc → TOCItem).
//
// Ported is exactly the part of the original computation these two calls
// hit: collect scrollable ancestors up to the boundary, compute the target
// scroll per ancestor for `start` / `center` / `end` / `nearest`, and with
// `scrollMode: "if-needed"` stop as soon as the element is already visible.
//
// IMPORTANT (taken from the original code, not from its documentation):
// `scrollIntoView(el, { boundary, scrollMode: 'if-needed' })` passes neither
// `block` nor `inline`. The original's normaliser forwards a non-empty
// options object unchanged, so `block` stays `undefined` — and every
// `undefined` branch of the computation ends up **centring**. The sidebar's
// auto scroll therefore centres the active entry as soon as it is not visible.
// This case distinction is reproduced 1:1 here.

const isElement = (value) => typeof value === 'object' && value != null && value.nodeType === 1;

// "Visible" overflow does not count as a scroll container.
const canOverflow = (value, skipOverflowHiddenElements) =>
  (!skipOverflowHiddenElements || value !== 'hidden') && value !== 'visible' && value !== 'clip';

function isScrollable(el, skipOverflowHiddenElements) {
  if (el.clientHeight < el.scrollHeight || el.clientWidth < el.scrollWidth) {
    const style = getComputedStyle(el, null);
    return (
      canOverflow(style.overflowY, skipOverflowHiddenElements) ||
      canOverflow(style.overflowX, skipOverflowHiddenElements) ||
      isScrollableFrame(el)
    );
  }
  return false;
}

function isScrollableFrame(el) {
  const doc = el.ownerDocument;
  let frame = null;
  if (doc && doc.defaultView) {
    try {
      frame = doc.defaultView.frameElement;
    } catch {
      frame = null;
    }
  }
  return !!frame && (frame.clientHeight < el.scrollHeight || frame.clientWidth < el.scrollWidth);
}

// `nearest`: scroll only as far as needed for the edge to fit.
function alignNearest(scrollingEdgeStart, scrollingEdgeEnd, scrollingSize, scrollingBorderStart, scrollingBorderEnd, elementEdgeStart, elementEdgeEnd, elementSize) {
  if ((elementEdgeStart < scrollingEdgeStart && elementEdgeEnd > scrollingEdgeEnd) ||
      (elementEdgeStart > scrollingEdgeStart && elementEdgeEnd < scrollingEdgeEnd)) {
    return 0;
  }
  if ((elementEdgeStart <= scrollingEdgeStart && elementSize <= scrollingSize) ||
      (elementEdgeEnd >= scrollingEdgeEnd && elementSize >= scrollingSize)) {
    return elementEdgeStart - scrollingEdgeStart - scrollingBorderStart;
  }
  if ((elementEdgeEnd > scrollingEdgeEnd && elementSize < scrollingSize) ||
      (elementEdgeStart < scrollingEdgeStart && elementSize > scrollingSize)) {
    return elementEdgeEnd - scrollingEdgeEnd + scrollingBorderEnd;
  }
  return 0;
}

function parentOf(el) {
  const parent = el.parentElement;
  if (parent == null) return el.getRootNode().host || null;
  return parent;
}

function scrollMarginOf(el) {
  const style = getComputedStyle(el);
  return {
    top: parseFloat(style.scrollMarginTop) || 0,
    right: parseFloat(style.scrollMarginRight) || 0,
    bottom: parseFloat(style.scrollMarginBottom) || 0,
    left: parseFloat(style.scrollMarginLeft) || 0,
  };
}

/**
 * Returns the list `{ el, top, left }` of scroll containers with their target position.
 * Corresponds to `compute()` from `compute-scroll-into-view`.
 */
export function computeScrollActions(target, options = {}) {
  if (typeof document === 'undefined') return [];
  const { scrollMode, block, inline, boundary, skipOverflowHiddenElements } = options;
  const checkBoundary = typeof boundary === 'function' ? boundary : (node) => node !== boundary;
  if (!isElement(target)) throw new TypeError('Invalid target');

  const scrollingElement = document.scrollingElement || document.documentElement;
  const frames = [];
  let cursor = target;
  while (isElement(cursor) && checkBoundary(cursor)) {
    cursor = parentOf(cursor);
    if (cursor === scrollingElement) {
      frames.push(cursor);
      break;
    }
    if (cursor != null && cursor === document.body && isScrollable(cursor) && !isScrollable(document.documentElement)) continue;
    if (cursor != null && isScrollable(cursor, skipOverflowHiddenElements)) frames.push(cursor);
  }

  const viewportWidth = window.visualViewport?.width ?? innerWidth;
  const viewportHeight = window.visualViewport?.height ?? innerHeight;
  const { scrollX, scrollY } = window;
  const { height, width, top, right, bottom, left } = target.getBoundingClientRect();
  const margin = scrollMarginOf(target);

  // `undefined` deliberately falls into the centring branch (see the header comment).
  let targetBlock =
    block === 'start' || block === 'nearest' ? top - margin.top
    : block === 'end' ? bottom + margin.bottom
    : top + height / 2 - margin.top + margin.bottom;
  let targetInline =
    inline === 'center' ? left + width / 2 - margin.left + margin.right
    : inline === 'end' ? right + margin.right
    : left - margin.left;

  const actions = [];
  for (let i = 0; i < frames.length; i++) {
    const frame = frames[i];
    const rect = frame.getBoundingClientRect();
    const frameHeight = rect.height;
    const frameWidth = rect.width;

    // `if-needed`: visible in the window **and** in the container → do nothing.
    if (scrollMode === 'if-needed' && top >= 0 && left >= 0 && bottom <= viewportHeight && right <= viewportWidth &&
        ((frame === scrollingElement && !isScrollable(frame)) ||
         (top >= rect.top && bottom <= rect.bottom && left >= rect.left && right <= rect.right))) {
      return actions;
    }

    const style = getComputedStyle(frame);
    const borderLeft = parseInt(style.borderLeftWidth, 10);
    const borderTop = parseInt(style.borderTopWidth, 10);
    const borderRight = parseInt(style.borderRightWidth, 10);
    const borderBottom = parseInt(style.borderBottomWidth, 10);
    let blockScroll = 0;
    let inlineScroll = 0;
    const scrollbarWidth = 'offsetWidth' in frame ? frame.offsetWidth - frame.clientWidth - borderLeft - borderRight : 0;
    const scrollbarHeight = 'offsetHeight' in frame ? frame.offsetHeight - frame.clientHeight - borderTop - borderBottom : 0;
    const ratioX = 'offsetWidth' in frame ? (frame.offsetWidth === 0 ? 0 : frameWidth / frame.offsetWidth) : 0;
    const ratioY = 'offsetHeight' in frame ? (frame.offsetHeight === 0 ? 0 : frameHeight / frame.offsetHeight) : 0;

    if (frame === scrollingElement) {
      blockScroll =
        block === 'start' ? targetBlock
        : block === 'end' ? targetBlock - viewportHeight
        : block === 'nearest' ? alignNearest(scrollY, scrollY + viewportHeight, viewportHeight, borderTop, borderBottom, scrollY + targetBlock, scrollY + targetBlock + height, height)
        : targetBlock - viewportHeight / 2;
      inlineScroll =
        inline === 'start' ? targetInline
        : inline === 'center' ? targetInline - viewportWidth / 2
        : inline === 'end' ? targetInline - viewportWidth
        : alignNearest(scrollX, scrollX + viewportWidth, viewportWidth, borderLeft, borderRight, scrollX + targetInline, scrollX + targetInline + width, width);
      blockScroll = Math.max(0, blockScroll + scrollY);
      inlineScroll = Math.max(0, inlineScroll + scrollX);
    } else {
      blockScroll =
        block === 'start' ? targetBlock - rect.top - borderTop
        : block === 'end' ? targetBlock - rect.bottom + borderBottom + scrollbarHeight
        : block === 'nearest' ? alignNearest(rect.top, rect.bottom, frameHeight, borderTop, borderBottom + scrollbarHeight, targetBlock, targetBlock + height, height)
        : targetBlock - (rect.top + frameHeight / 2) + scrollbarHeight / 2;
      inlineScroll =
        inline === 'start' ? targetInline - rect.left - borderLeft
        : inline === 'center' ? targetInline - (rect.left + frameWidth / 2) + scrollbarWidth / 2
        : inline === 'end' ? targetInline - rect.right + borderRight + scrollbarWidth
        : alignNearest(rect.left, rect.right, frameWidth, borderLeft, borderRight + scrollbarWidth, targetInline, targetInline + width, width);

      const { scrollLeft, scrollTop } = frame;
      blockScroll = ratioY === 0 ? 0 : Math.max(0, Math.min(scrollTop + blockScroll / ratioY, frame.scrollHeight - frameHeight / ratioY + scrollbarHeight));
      inlineScroll = ratioX === 0 ? 0 : Math.max(0, Math.min(scrollLeft + inlineScroll / ratioX, frame.scrollWidth - frameWidth / ratioX + scrollbarWidth));
      targetBlock += scrollTop - blockScroll;
      targetInline += scrollLeft - inlineScroll;
    }
    actions.push({ el: frame, top: blockScroll, left: inlineScroll });
  }
  return actions;
}

function isConnectedToDocument(el) {
  let node = el;
  while (node && node.parentNode) {
    if (node.parentNode === document) return true;
    node = node.parentNode instanceof ShadowRoot ? node.parentNode.host : node.parentNode;
  }
  return false;
}

/**
 * Corresponds to the default export of `scroll-into-view-if-needed`.
 * @param {Element} target
 * @param {{boundary?: Element|Function, scrollMode?: 'if-needed'|'always', block?: string, inline?: string, behavior?: ScrollBehavior}} [options]
 */
export function scrollIntoViewIfNeeded(target, options = {}) {
  if (!target || !target.isConnected || !isConnectedToDocument(target)) return;
  const margin = scrollMarginOf(target);
  const behavior = typeof options === 'boolean' || options == null ? undefined : options.behavior;
  for (const { el, top, left } of computeScrollActions(target, options)) {
    el.scroll({ top: top - margin.top + margin.bottom, left: left - margin.left + margin.right, behavior });
  }
}

export default scrollIntoViewIfNeeded;
