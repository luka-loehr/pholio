// collapsible.js — port of Base UI `Collapsible` (@base-ui/react 1.8.0),
// sources: collapsible/root/useCollapsibleRoot.js, collapsible/panel/useCollapsiblePanel.js,
// collapsible/trigger/CollapsibleTrigger.js, internals/useTransitionStatus.js.
//
// Used by: sidebar folders (`js/sidebar.js`), the TOC popover
// (`js/toc-popover.js`) and the start page menu.
//
// DOM shape (as the original renders it):
//
//   <div data-open|data-closed>
//     <button|a  aria-expanded aria-controls data-panel-open>…<svg data-icon …></svg></button>
//     <div id=<panelId> data-open class="nd-collapsible-panel …">…</div>
//     <template data-collapsible-panel>…the same panel…</template>   ← only when starting closed
//   </div>
//
// Base UI **does not mount the panel** while the section is closed
// (`keepMounted` is off). So that the port's initial DOM matches the reference,
// the content of a closed section sits in a `<template>`; opening creates the
// panel from it, closing removes it again. This also matches the state loss of
// nested folders on unmount.
//
// Phase sequence (css-transition, identical to useTransitionStatus + useCollapsiblePanel):
//   Open     frame 0: data-open + data-starting-style, --collapsible-panel-height = measured px
//            frame 1: data-starting-style removed → transition runs
//            after  : --collapsible-panel-height/-width = auto
//   Close    frame 0: data-closed, --collapsible-panel-height = measured px (still looks open)
//            frame 1: data-ending-style → transition runs
//            end    : panel removed (or hidden="until-found"), variables back to auto

// This module toggles no classes. State lives in `data-open`/`data-closed`
// (root, panel), `data-panel-open`/`aria-expanded`/`aria-controls` (trigger) and
// the phase attributes; the CSS hooks onto them, e.g.
// `:not([data-panel-open]) > .nd-sidebar-chevron` rotates the folder chevron (verify/CLASS-MAP.md).

const PANEL_SELECTOR = ':scope > [id]:not(template)';
const TEMPLATE_SELECTOR = ':scope > template[data-collapsible-panel]';

function nextFrame(fn) {
  return requestAnimationFrame(fn);
}

/** Waits until all animations/transitions of the element have ended (useAnimationsFinished). */
function onAnimationsFinished(el, signal, done) {
  const animations = el.getAnimations({ subtree: false });
  if (animations.length === 0) {
    if (!signal.aborted) done();
    return;
  }
  Promise.allSettled(animations.map((a) => a.finished)).then(() => {
    if (!signal.aborted) done();
  });
}

/** getAnimationType() from useCollapsiblePanel.js. */
function getAnimationType(el) {
  const styles = getComputedStyle(el);
  const hasAnimation =
    styles.animationName.split(',').map((n) => n.trim()).some((n) => n !== '' && n !== 'none') &&
    hasNonZeroDuration(styles.animationDuration);
  const hasTransition = hasNonZeroDuration(styles.transitionDuration);
  if (hasTransition) return 'css-transition';
  if (hasAnimation) return 'css-animation';
  return 'none';
}

function hasNonZeroDuration(value) {
  return value.split(',').map((p) => p.trim()).some((p) => p !== '' && Number.parseFloat(p) > 0);
}

/** resetLayoutStyles() from useCollapsiblePanel.js: neutralise alignment to measure cleanly. */
function resetLayoutStyles(el) {
  const keys = ['justify-content', 'align-items', 'align-content', 'justify-items'];
  const previous = keys.map((k) => el.style.getPropertyValue(k));
  keys.forEach((k) => el.style.setProperty(k, 'initial', 'important'));
  return () => {
    keys.forEach((k, i) => {
      if (previous[i] === '') el.style.removeProperty(k);
      else el.style.setProperty(k, previous[i]);
    });
  };
}

export class Collapsible {
  /**
   * @param {HTMLElement} root
   * @param {{hiddenUntilFound?: boolean, onOpenChange?: (open: boolean, reason: string) => void, trigger?: Element, template?: HTMLTemplateElement, panel?: HTMLElement, disabled?: boolean}} [options]
   */
  constructor(root, options = {}) {
    this.root = root;
    this.options = options;
    this.hiddenUntilFound = options.hiddenUntilFound ?? false;
    this.disabled = options.disabled ?? false;
    this.trigger = options.trigger ?? root.querySelector(':scope > button, :scope > a');
    // Panel and template usually sit directly under the root (sidebar folders).
    // The TOC popover renders both in its <header> row next to the trigger
    // instead (TOCPopover in layouts/notebook/page/slots/toc.js), so that place
    // is searched as well.
    // If both sit somewhere else, the `template` and `panel` options give them
    // directly; they take precedence over any search. Example: the mobile menu of
    // the start page (layouts/home/slots/header.js) has the root `header#nd-nav`, the
    // trigger in `.nd-home-nav-narrow` and the panel in `nav.nd-home-nav`.
    // A panel created from the template is always inserted directly before the template.
    const beside = this.trigger && this.trigger.parentElement !== root ? this.trigger.parentElement : null;
    this.template = options.template ?? root.querySelector(TEMPLATE_SELECTOR) ?? beside?.querySelector(TEMPLATE_SELECTOR) ?? null;
    this.panel = options.panel ?? this.findPanel(root) ?? (beside ? this.findPanel(beside) : null);
    this.open = root.hasAttribute('data-open');
    // A panel that is open on first render skips its opening animation
    // (shouldPreventMountAnimationRef); the reference writes `animation-name:none`
    // inline for that. It is dropped on the first close.
    this.mountAnimationSuppressed = this.open;
    this.panelId = this.panel?.id ?? this.template?.content.firstElementChild?.id ?? null;
    this.abort = null;
    this.frame = -1;
    this.timer = 0;

    if (this.trigger && !this.disabled) {
      this.trigger.addEventListener('click', (event) => this.handleTrigger(event));
    }
    this.bindBeforeMatch();
  }

  /**
   * First child of `scope` with an `id` that is not a template and neither is the
   * trigger nor contains it. Without this exclusion a trigger with its own `id`
   * counts as an already open panel: opening would then set the panel attributes
   * on the button, and closing would remove the button from the DOM.
   */
  findPanel(scope) {
    for (const el of scope.querySelectorAll(PANEL_SELECTOR)) {
      if (this.trigger && (el === this.trigger || el.contains(this.trigger))) continue;
      return el;
    }
    return null;
  }

  static attach(root, options) {
    if (!root || root.__ndCollapsible) return root?.__ndCollapsible ?? null;
    const instance = new Collapsible(root, options);
    root.__ndCollapsible = instance;
    return instance;
  }

  handleTrigger(event) {
    if (this.disabled) return;
    if (this.options.onTriggerClick && this.options.onTriggerClick(event) === false) return;
    this.setOpen(!this.open, 'trigger-press');
  }

  toggle() { this.setOpen(!this.open, 'none'); }

  /** @param {boolean} open */
  setOpen(open, reason = 'none', skipAnimation = false) {
    if (open === this.open) return;
    this.open = open;
    this.options.onOpenChange?.(open, reason);
    // collapsibleOpenStateMapping: the root carries data-open or data-closed.
    this.root.toggleAttribute('data-open', open);
    this.root.toggleAttribute('data-closed', !open);
    this.applyTriggerState(open);
    if (open) this.runOpen(skipAnimation);
    else this.runClose();
  }

  // ---- Trigger -------------------------------------------------------------

  applyTriggerState(open) {
    const trigger = this.trigger;
    if (!trigger) return;
    if (trigger.tagName === 'BUTTON' || trigger.hasAttribute('aria-expanded')) {
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (open) {
      if (this.panelId) trigger.setAttribute('aria-controls', this.panelId);
      trigger.setAttribute('data-panel-open', '');
    } else {
      trigger.removeAttribute('aria-controls');
      trigger.removeAttribute('data-panel-open');
    }
  }

  // ---- Panel ---------------------------------------------------------------

  /** Creates the panel from the `<template>` if it is not in the DOM. */
  materializePanel() {
    if (this.panel && this.panel.isConnected) return this.panel;
    if (!this.template) return null;
    const node = this.template.content.firstElementChild.cloneNode(true);
    // A freshly mounted panel never has `animation-name:none`; in the original
    // only a panel that was already open on first render carries it.
    node.style.removeProperty('animation-name');
    this.template.parentNode.insertBefore(node, this.template);
    this.panel = node;
    this.panelId = node.id || this.panelId;
    this.options.onPanelMount?.(node);
    return node;
  }

  removePanel() {
    if (!this.panel) return;
    if (this.hiddenUntilFound) {
      this.panel.setAttribute('hidden', 'until-found');
      this.panel.setAttribute('data-starting-style', '');
      return;
    }
    this.options.onPanelUnmount?.(this.panel);
    this.panel.remove();
    if (this.template) this.panel = null;
  }

  /**
   * In the original `data-starting-style` / `data-ending-style` are not only on
   * the panel: `transitionStatusMapping` also applies to the root and the
   * trigger (measured in the reference). Without an argument both are removed.
   */
  setPhase(phase) {
    for (const el of [this.root, this.trigger, this.panel]) {
      if (!el) continue;
      el.toggleAttribute('data-starting-style', phase === 'starting');
      el.toggleAttribute('data-ending-style', phase === 'ending');
    }
  }

  setDimensions(panel, height, width) {
    panel.style.setProperty('--collapsible-panel-height', height);
    panel.style.setProperty('--collapsible-panel-width', width);
  }

  runOpen(skipAnimation) {
    this.cancelPending();
    const panel = this.materializePanel();
    if (!panel) return;

    panel.removeAttribute('hidden');
    panel.removeAttribute('data-closed');
    panel.removeAttribute('data-ending-style');
    panel.setAttribute('data-open', '');
    this.setPhase('starting');
    // A previously suppressed mount animation no longer applies after the first cycle.
    if (this.mountAnimationSuppressed) {
      panel.style.removeProperty('animation-name');
      this.mountAnimationSuppressed = false;
    }

    const type = getAnimationType(panel);
    if (type === 'none' || skipAnimation) {
      this.setDimensions(panel, `${panel.scrollHeight}px`, `${panel.scrollWidth}px`);
      this.setPhase(null);
      this.setDimensions(panel, 'auto', 'auto');
      return;
    }

    const restore = resetLayoutStyles(panel);
    this.setDimensions(panel, `${panel.scrollHeight}px`, `${panel.scrollWidth}px`);
    // Measured in the reference: the alignment styles are back in frame 1,
    // `data-starting-style` disappears only after that (React commits the
    // rAF-triggered state change in the task after the frame).
    this.frame = nextFrame(() => {
      restore();
      this.timer = setTimeout(() => {
        this.setPhase(null);
        const controller = new AbortController();
        this.abort = controller;
        // After the opening animation ends the height goes back to `auto`
        // (useOpenChangeComplete → setDimensions(EMPTY_DIMENSIONS)).
        this.frame = nextFrame(() => {
          onAnimationsFinished(panel, controller.signal, () => {
            if (this.open) this.setDimensions(panel, 'auto', 'auto');
          });
        });
      }, 0);
    });
  }

  runClose() {
    this.cancelPending();
    const panel = this.panel;
    if (!panel || !panel.isConnected) return;

    this.mountAnimationSuppressed = false;
    panel.style.removeProperty('animation-name');
    panel.removeAttribute('data-open');
    this.setPhase(null);
    panel.setAttribute('data-closed', '');

    const type = getAnimationType(panel);
    if (type === 'none') {
      this.setDimensions(panel, 'auto', 'auto');
      this.removePanel();
      return;
    }
    // First capture the current size, then `data-ending-style` one frame later.
    this.setDimensions(panel, `${panel.scrollHeight}px`, `${panel.scrollWidth}px`);
    // As when opening: frame 1 shows only `data-closed`, `data-ending-style`
    // follows in the task after it (deferEndingState in useTransitionStatus).
    this.frame = nextFrame(() => {
      this.timer = setTimeout(() => {
        this.setPhase('ending');
        const controller = new AbortController();
        this.abort = controller;
        this.frame = nextFrame(() => {
          onAnimationsFinished(panel, controller.signal, () => {
            if (this.open) return;
            this.removePanel();
            if (this.panel) this.setDimensions(this.panel, 'auto', 'auto');
            // The original clears `data-ending-style` on root and trigger only
            // in the render after unmount; one task later matches that.
            setTimeout(() => { if (!this.open) this.setPhase(null); }, 0);
          });
        });
      }, 0);
    });
  }

  cancelPending() {
    if (this.frame !== -1) cancelAnimationFrame(this.frame);
    this.frame = -1;
    clearTimeout(this.timer);
    this.abort?.abort();
    this.abort = null;
  }

  // ---- hidden="until-found" -------------------------------------------------

  bindBeforeMatch() {
    if (!this.hiddenUntilFound || !this.panel) return;
    this.panel.addEventListener('beforematch', () => {
      // The find-in-page match should be visible immediately: this one opening
      // cycle skips the motion (shouldSkipNextOpenRef).
      this.setOpen(true, 'none', true);
    });
  }
}

/** Attaches an instance to every matching node. */
export function attachCollapsibles(scope, selector, options) {
  const roots = scope.querySelectorAll(selector);
  const out = [];
  roots.forEach((root) => {
    const instance = Collapsible.attach(root, typeof options === 'function' ? options(root) : options);
    if (instance) out.push(instance);
  });
  return out;
}

/**
 * Entry point for notebook.js. `collapsible.js` does not attach itself to
 * nodes: folders are bound by `sidebar.js`, the TOC popover by `toc-popover.js`.
 */
export function boot() {}
