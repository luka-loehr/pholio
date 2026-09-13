// toc-popover.js — the table of contents as a collapsible bar below xl.
//
// Source: fumadocs-ui/dist/layouts/notebook/page/slots/toc.js
//   TOCPopover               – Collapsible, click outside closes, click on
//                              an entry closes; the header row background
//                              is fixed (transparentMode "none"), its shadow hangs
//                              in the CSS on `[data-toc-popover][data-open]`
//   PageTOCPopoverTrigger    – progress circle, two stacked spans, chevron
//   ProgressCircle           – size 18, strokeWidth 1.5, max 1
//
// The circle's value is (index of the **last** active entry + 1) / count.

import { Collapsible } from './collapsible.js';

/**
 * Classes this module toggles (verify/CLASS-MAP.md). Header shadow, circle
 * colour, chevron rotation and page title colour hang on the `data-open` of
 * `[data-toc-popover]` (set by collapsible.js). Only the swap of the two
 * stacked spans has no attribute in the reference and stays a class.
 */
export const CLASSES = {
  labelUp: 'nd-tocpop-label-up',     // page title moved up and away while a heading is shown
  labelDown: 'nd-tocpop-label-down', // active heading moved down and away while the title is shown
};

export const SIZE = 18;
export const STROKE_WIDTH = 1.5;
export const RADIUS = SIZE / 2 - STROKE_WIDTH;             // 7.5
export const CIRCUMFERENCE = 2 * Math.PI * RADIUS;         // 47.1238898038469

export class TOCPopover {
  /**
   * @param {HTMLElement} root  the element with `data-toc-popover`
   * @param {import('./toc.js').TOCObserver} observer
   */
  constructor(root, observer) {
    this.root = root;
    this.observer = observer;
    this.header = root.querySelector(':scope > header');
    this.trigger = root.querySelector('[data-toc-popover-trigger]');
    this.progress = this.trigger?.querySelector('svg[role="progressbar"]');
    this.progressArc = this.progress?.querySelectorAll('circle')[1] ?? null;
    const spans = this.trigger?.querySelectorAll(':scope > span > span') ?? [];
    this.titleSpan = spans[0] ?? null;
    this.headingSpan = spans[1] ?? null;

    this.collapsible = Collapsible.attach(root, {
      trigger: this.trigger,
      onOpenChange: (open) => this.applyOpen(open),
      onPanelMount: (panel) => {
        this.bindItems(panel);
        // The entries in the panel are TOCItems of their own on the same observer.
        this.list = document.__ndToc?.addList?.(panel) ?? null;
      },
      onPanelUnmount: () => {
        document.__ndToc?.removeList?.(this.list);
        this.list = null;
      },
    });
    this.open = this.collapsible?.open ?? false;

    // A click outside the header row closes (window listener as in the original).
    window.addEventListener('click', (event) => {
      if (!this.open || !(event.target instanceof HTMLElement)) return;
      if (this.header && !this.header.contains(event.target)) this.setOpen(false);
    });
    if (this.collapsible?.panel) this.bindItems(this.collapsible.panel);

    observer?.listen((items) => this.onUpdate(items));
    if (observer) this.onUpdate(observer.items);
  }

  static attach(doc, observer) {
    const root = (doc ?? document).querySelector('[data-toc-popover]');
    if (!root || root.__ndTocPopover) return root?.__ndTocPopover ?? null;
    const instance = new TOCPopover(root, observer);
    root.__ndTocPopover = instance;
    return instance;
  }

  bindItems(panel) {
    panel.querySelectorAll('a[href^="#"]').forEach((a) => {
      a.addEventListener('click', () => this.setOpen(false));
    });
  }

  setOpen(open) {
    this.collapsible?.setOpen(open, 'none');
    if (!this.collapsible) this.applyOpen(open);
  }

  applyOpen(open) {
    this.open = open;
    this.onUpdate(this.observer?.items ?? []);
  }

  /** Update progress circle, title row and active heading. */
  onUpdate(items) {
    const total = Math.max(1, items.length);
    let lastActiveIdx = -1;
    for (let i = 0; i < items.length; i++) if (items[i].active) lastActiveIdx = i;
    const value = Math.min(1, Math.max(0, (lastActiveIdx + 1) / total));
    if (this.progressArc) {
      this.progressArc.setAttribute('stroke-dasharray', String(CIRCUMFERENCE));
      this.progressArc.setAttribute('stroke-dashoffset', String(CIRCUMFERENCE - value * CIRCUMFERENCE));
    }
    this.progress?.setAttribute('aria-valuenow', String(value));

    // In the original `selectedIdx` is the **first** active entry.
    const selectedIdx = items.findIndex((item) => item.active);
    const showItem = selectedIdx !== -1 && !this.open;
    this.titleSpan?.classList.toggle(CLASSES.labelUp, showItem);
    if (this.headingSpan) {
      this.headingSpan.classList.toggle(CLASSES.labelDown, !showItem);
      const link = selectedIdx === -1 ? null : document.querySelector(`#nd-toc a[href="#${CSS.escape(items[selectedIdx].id)}"]`);
      const title = link ? link.textContent.trim() : '';
      if (this.headingSpan.textContent !== title) this.headingSpan.textContent = title;
    }
  }
}

export default TOCPopover;

/**
 * Entry point for notebook.js. Gets the observer through `TOC.attach`,
 * so that the order in which the modules load doesn't matter.
 */
export async function boot(doc = document) {
  if (!doc.querySelector('[data-toc-popover]')) return;
  const { TOC } = await import('./toc.js');
  TOCPopover.attach(doc, TOC.attach(doc).observer);
}
