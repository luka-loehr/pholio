// Entry module of the notebook theme: wires up all behaviour modules.
//
// Included as a single ES module at the end of <body>:
//   <script type="module" src="…/assets/js/notebook.js"></script>
// The classic scripts theme-init.js (in <head>) and sidebar-restore.js (after
// the sidebar placeholder) run separately before first paint; they do not
// belong in this import list.
//
// Rules:
//   · fixed, static import list of all modules – no dynamic imports,
//     no fallback for missing files
//   · idempotent: a second call of boot() sets nothing up again
//     (module variable plus window flag, no attribute in the DOM)
//   · everything is found through ids, data and aria attributes or nd classes
//     that already exist in the static HTML; if an element is missing, nothing happens
//   · every layout module exports boot(doc) and guards against repeated calls itself

import { createHandle } from './dialog.js';
import { mountSearchDialog } from './search-dialog.js';
import { createPopover } from './popover.js';
import { initTheme } from './theme.js';
import { initHotkeys } from './hotkeys.js';
import { initCopyButtons } from './copy.js';
import { boot as bootCollapsibles, Collapsible } from './collapsible.js';
import { boot as bootScrollAreas } from './scroll-area.js';
import { boot as bootSidebar } from './sidebar.js';
import { boot as bootToc } from './toc.js';
import { boot as bootTocPopover } from './toc-popover.js';
// Catalogue components. Every page loads them; without matching elements their
// boot() returns immediately.
import { boot as bootTabs } from './tabs.js';
import { boot as bootAccordion } from './accordion.js';
import { boot as bootCatalogueCollapsible } from './catalogue-collapsible.js';
import { boot as bootImageZoom } from './image-zoom.js';
import { boot as bootBanner } from './banner.js';
import { boot as bootPageActions } from './page-actions.js';

// Classes of the section switcher popup (verify/CLASS-MAP.md, notebook.js).
const POPUP_CLASS = 'nd-popover nd-tabsdrop-popup fd-scroll-container';

let booted = false;

export function boot(doc = document) {
  if (booted || window.__ndBooted) return null;
  booted = true;
  window.__ndBooted = true;

  const theme = initTheme();

  // Search: both triggers share one handle (Dialog.createHandle).
  const searchHandle = createHandle();
  const triggers = [...doc.querySelectorAll('button[data-search-full], button[data-search]')];
  if (triggers.length) {
    const dialog = mountSearchDialog({ handle: searchHandle });
    triggers.forEach((trigger) => dialog.addTrigger(trigger));
  }

  initHotkeys({ searchHandle, theme });
  initCopyButtons(doc);

  // Section switcher (popover) in the desktop sidebar and in the drawer. Below
  // 768 px sidebar-restore.js removes the desktop sidebar together with its template
  // before this module runs; so each trigger uses the template the generator
  // writes directly after it. Only if there is none there does a template
  // anywhere in the document apply. Without a template the trigger stays unwired.
  const sidebarTriggers = doc.querySelectorAll(
    '#nd-sidebar button[aria-haspopup="dialog"], #nd-sidebar-mobile button[aria-haspopup="dialog"]',
  );
  for (const trigger of sidebarTriggers) {
    if (trigger.hasAttribute('data-search') || trigger.hasAttribute('data-search-full')) continue;
    const beside = trigger.parentElement?.querySelector(':scope > template[data-nd-tabs-popup]');
    const template = beside ?? doc.querySelector('template[data-nd-tabs-popup]');
    if (!template) continue;
    createPopover({
      trigger,
      popupClass: POPUP_CLASS,
      content: () => template.content.cloneNode(true),
    });
  }

  // Mobile menu of the start page (reference layouts/home/slots/header.js): a
  // Base UI Collapsible with header#nd-nav as root, the chevron button as
  // trigger and the panel from <template data-collapsible-panel> in nav.
  // collapsible.js sets phases, height and aria; here only what the header itself
  // does: close on a click outside the header (window click as in the original)
  // and on a click on a menu link, the theme switcher in the panel, and after
  // mounting, marking the active theme icon.
  const homeHeader = doc.getElementById('nd-nav');
  // The small search trigger sits in the same row and also carries
  // aria-expanded; the menu button is the only one without aria-haspopup.
  const homeTrigger = homeHeader?.querySelector('.nd-home-nav-narrow > button[aria-expanded]:not([aria-haspopup])');
  const homeTemplate = homeHeader?.querySelector('nav > template[data-collapsible-panel]');
  if (homeHeader && homeTrigger && homeTemplate) {
    const menu = Collapsible.attach(homeHeader, {
      trigger: homeTrigger,
      template: homeTemplate,
      onOpenChange: (open) => {
        // The panel is created only inside setOpen; mark the icon afterwards.
        if (open) queueMicrotask(() => theme.refresh());
      },
    });
    homeHeader.addEventListener('click', (event) => {
      const panel = event.target.closest?.('#nd-home-menu-panel');
      if (!panel) return;
      if (event.target.closest('button[data-theme-toggle]')) theme.toggle();
      else if (event.target.closest('a[href]')) menu.setOpen(false, 'link-press');
    });
    window.addEventListener('click', (event) => {
      if (!menu.open) return;
      if (event.target !== homeHeader && !homeHeader.contains(event.target)) menu.setOpen(false, 'outside-press');
    });
  }

  // Layout and catalogue modules in a fixed order. An error in one module does not
  // stop the others; it shows up in the console.
  const layout = [
    ['collapsible', bootCollapsibles],
    ['scroll-area', bootScrollAreas],
    ['sidebar', bootSidebar],
    ['toc', bootToc],
    ['toc-popover', bootTocPopover],
    ['tabs', bootTabs],
    ['accordion', bootAccordion],
    ['catalogue-collapsible', bootCatalogueCollapsible],
    ['image-zoom', bootImageZoom],
    ['banner', bootBanner],
    ['page-actions', bootPageActions],
  ];
  for (const [name, start] of layout) {
    try {
      const result = start(doc);
      if (result && typeof result.then === 'function') {
        result.catch((err) => console.error(`notebook.js: boot of ${name}.js failed`, err));
      }
    } catch (err) {
      console.error(`notebook.js: boot of ${name}.js failed`, err);
    }
  }

  return { theme, searchHandle };
}

boot();
