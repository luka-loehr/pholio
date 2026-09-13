// Shared helpers of the notebook modules: ids, transition phases, focus, click outside.
//
// Usage (ES module in the browser, no bundler):
//   import { clientId, transitionStatus, isTypingTarget, onClickOutside } from './util.js';
//
// Each function reproduces a concrete behaviour of @base-ui/react or the reference UI.
// The source is named in the comment above it, so deviations stay checkable.

// ---- Ids -------------------------------------------------------------------
//
// In the browser React assigns ids of the form `_r_0_`, `_r_1_`, … (useId after
// hydration); on the server ids look like `_R_15knel9etb_`. The golden DOM
// comparison normalises `_R_…_` away (HYDRATION_ID in verify/golden-dom.mjs), so
// elements that already exist in the static HTML carry the `_R_` form, and
// elements created only on opening carry the `_r_` form – exactly as measured in
// the reference (portal `_r_0_`, dialog title `base-ui-_r_1_`).

let clientCounter = 0;
let hydrationCounter = 0;

// Next client id in React format `_r_<n>_`.
export function clientId(prefix = '') {
  return `${prefix}_r_${clientCounter++}_`;
}

// Id in the format of server-side React ids; normalised by the golden DOM.
export function hydrationId(prefix = '') {
  return `${prefix}_R_${(hydrationCounter++).toString(36)}nb_`;
}

// ---- Frames ----------------------------------------------------------------

export function nextFrame(fn) {
  return requestAnimationFrame(() => fn());
}

// Two frames: that is when Base UI removes `data-starting-style` again
// (useTransitionStatus sets the status in a layout effect via AnimationFrame.request,
// the change becomes visible only in the frame after next).
export function afterTwoFrames(fn) {
  return requestAnimationFrame(() => requestAnimationFrame(() => fn()));
}

// ---- Transition phases (useTransitionStatus) -------------------------------
//
// Original: @base-ui/react/internals/useTransitionStatus.mjs
//   open    → `mounted = true`, status `starting` (synchronously in the click handler),
//             one frame later status `undefined`
//   close   → status `ending` (synchronously), after all animations have ended
//             the element is unmounted
// In the DOM: always `data-open` or `data-closed`, plus briefly
// `data-starting-style`, or `data-ending-style` until unmount.

// Open: set the attributes in exactly the measured order.
export function markOpen(el) {
  el.removeAttribute('data-closed');
  el.removeAttribute('data-ending-style');
  el.setAttribute('data-open', '');
  el.setAttribute('data-starting-style', '');
  afterTwoFrames(() => {
    // Only remove it if it wasn't closed again in the meantime.
    if (el.hasAttribute('data-open')) el.removeAttribute('data-starting-style');
  });
}

// Close: `data-closed` + `data-ending-style`, then `done()` once all animations
// of the element have finished (useAnimationsFinished).
export function markClosed(el, done) {
  el.removeAttribute('data-open');
  el.removeAttribute('data-starting-style');
  el.setAttribute('data-closed', '');
  el.setAttribute('data-ending-style', '');
  animationsFinished(el, done);
}

// Base UI: useAnimationsFinished – waits one frame so newly started animations
// are included, then for all `finished` promises.
export function animationsFinished(el, done) {
  requestAnimationFrame(() => {
    const running = el.getAnimations({ subtree: true }).filter((a) => a.playState === 'running');
    if (!running.length) {
      done();
      return;
    }
    Promise.all(running.map((a) => a.finished.catch(() => {}))).then(() => done());
  });
}

// ---- Keyboard --------------------------------------------------------------
//
// Original: reference UI dist/provider/base.js – isTypingTarget
export function isTypingTarget(target) {
  if (!(target instanceof HTMLElement)) return false;
  if (target.isContentEditable) return true;
  if (['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) return true;
  return target.closest('[role="dialog"]') !== null;
}

// ---- Click outside ---------------------------------------------------------
//
// Base UI closes on `pointerdown` outside all given elements.
// Returns a function that unsubscribes.
export function onClickOutside(elements, handler) {
  const listener = (event) => {
    const list = typeof elements === 'function' ? elements() : elements;
    for (const el of list) {
      if (el && el.contains(event.target)) return;
    }
    handler(event);
  };
  document.addEventListener('pointerdown', listener, true);
  return () => document.removeEventListener('pointerdown', listener, true);
}

// ---- Focus -----------------------------------------------------------------

const FOCUSABLE = [
  'a[href]', 'button:not([disabled])', 'input:not([disabled])', 'select:not([disabled])',
  'textarea:not([disabled])', '[tabindex]:not([tabindex="-1"])',
].join(',');

export function focusableWithin(container) {
  return [...container.querySelectorAll(FOCUSABLE)].filter((el) => {
    if (el.hasAttribute('data-base-ui-focus-guard')) return false;
    return el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement;
  });
}

// The two invisible guards Base UI (FloatingFocusManager) places around every
// modal content. Style and attributes are taken from the reference.
export function createFocusGuard() {
  const span = document.createElement('span');
  span.setAttribute('data-type', 'inside');
  span.setAttribute('aria-hidden', 'true');
  span.setAttribute('tabindex', '0');
  span.setAttribute('data-base-ui-focus-guard', '');
  span.setAttribute(
    'style',
    'clip-path: inset(50%); overflow: hidden; white-space: nowrap; border: 0px; padding: 0px; width: 1px; height: 1px; margin: -1px; position: fixed; top: 0px; left: 0px;',
  );
  return span;
}

// Focus trap like FloatingFocusManager: the key press is not intercepted;
// instead the two guards receive focus and pass it on. The browser therefore
// moves focus itself first – the same order as in the reference, where right
// after the keydown the previously focused element still applies.
export function wireFocusGuards(guards, container) {
  const [before, after] = guards;
  const toLast = () => {
    const items = focusableWithin(container);
    (items[items.length - 1] ?? container).focus();
  };
  const toFirst = () => {
    const items = focusableWithin(container);
    (items[0] ?? container).focus();
  };
  before?.addEventListener('focus', toLast);
  after?.addEventListener('focus', toFirst);
  return () => {
    before?.removeEventListener('focus', toLast);
    after?.removeEventListener('focus', toFirst);
  };
}

// ---- Scrolling -------------------------------------------------------------
//
// Replacement for `scroll-into-view-if-needed` with the options the original uses
// (scrollMode "if-needed", block "nearest", boundary = parent element):
// only the given container scrolls, and only as far as needed.
export function scrollIntoViewIfNeeded(el, boundary) {
  const box = boundary ?? el.parentElement;
  if (!box) return;
  const elRect = el.getBoundingClientRect();
  const boxRect = box.getBoundingClientRect();
  if (elRect.top < boxRect.top) box.scrollTop -= boxRect.top - elRect.top;
  else if (elRect.bottom > boxRect.bottom) box.scrollTop += elRect.bottom - boxRect.bottom;
}

// ---- Small helpers ---------------------------------------------------------

export function debounce(fn, ms) {
  let timer = null;
  const wrapped = (...args) => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => fn(...args), ms);
  };
  wrapped.cancel = () => {
    if (timer) clearTimeout(timer);
    timer = null;
  };
  return wrapped;
}

// Build an element from HTML text; meant only for the modules' fixed templates.
export function html(markup) {
  const tpl = document.createElement('template');
  tpl.innerHTML = markup.trim();
  return tpl.content.firstElementChild;
}

// All SVG icons come from lucide; the modules only hold the paths.
export function icon(name, className, paths, extra = '') {
  return `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-${name}${className ? ` ${className}` : ''}" aria-hidden="true"${extra}>${paths}</svg>`;
}
