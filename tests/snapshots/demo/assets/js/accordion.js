// accordion.js — accordion list: reference UI components/accordion.js (Accordions,
// Accordion, CopyButton) on Base UI Accordion (@base-ui/react 1.8.0:
// accordion/root/AccordionRoot.js, accordion/item/AccordionItem.js,
// accordion/trigger/AccordionTrigger.js, accordion/panel/AccordionPanel.js,
// collapsible/panel/useCollapsiblePanel.js).
//
// Static DOM: components/accordion.php. All panels are mounted
// (hiddenUntilFound); closed ones carry hidden="until-found" and data-starting-style.
//
// Behaviour:
//   · Click on the trigger: handleValueChange with multiple = false (the reference only passes
//     `type` through as an attribute) → at most one item is open; a click on
//     the open one closes it.
//   · On start: if the hash holds the id of a header in this list, its value is
//     prepended to the open values (useEffect in Accordions).
//   · beforematch (find-in-page hits text in a closed panel) opens without motion.
//   · Panel animation like useCollapsiblePanel with css-transition: measure the height, one frame
//     data-starting-style, then the transition, at the end --accordion-panel-height:auto.
//   · Copy button: copies the address with #id, shows the check mark for 1500 ms (useCopyButton).

import { icon } from './util.js';

const CHECK_PATHS = '<path d="M20 6 9 17l-5-5"></path>';
let booted = false;

export function boot(doc = document) {
  if (booted) return;
  booted = true;
  const roots = new Set();
  for (const header of doc.querySelectorAll('h3[data-accordion-value]')) {
    const root = header.parentElement?.parentElement;
    if (root) roots.add(root);
  }
  roots.forEach(attach);
}

function hasTransition(el) {
  return getComputedStyle(el)
    .transitionDuration.split(',')
    .some((part) => Number.parseFloat(part) > 0);
}

function whenTransitionsDone(el, done) {
  requestAnimationFrame(() => {
    const running = el.getAnimations().filter((a) => a.playState === 'running');
    if (!running.length) {
      done();
      return;
    }
    Promise.allSettled(running.map((a) => a.finished)).then(done);
  });
}

function attach(root) {
  if (root.__ndAccordion) return;
  root.__ndAccordion = true;

  const items = [...root.children]
    .map((item) => {
      const header = item.querySelector(':scope > h3[data-accordion-value]');
      if (!header) return null;
      return {
        item,
        header,
        trigger: header.querySelector(':scope > button[aria-expanded]'),
        copy: header.querySelector(':scope > button:not([aria-expanded])'),
        panel: item.querySelector(':scope > [role="region"]'),
        value: header.getAttribute('data-accordion-value'),
        token: 0,
      };
    })
    .filter(Boolean);

  let open = items.filter((x) => x.item.hasAttribute('data-open')).map((x) => x.value);

  function setOpen(next, skipAnimation = false) {
    const before = open;
    open = next;
    for (const entry of items) {
      const was = before.includes(entry.value);
      const is = next.includes(entry.value);
      if (was === is) continue;
      if (is) openItem(entry, skipAnimation);
      else closeItem(entry);
    }
  }

  function handleValueChange(value) {
    setOpen(open[0] === value ? [] : [value]);
  }

  function openItem(entry, skipAnimation) {
    const { item, header, trigger, panel, value } = entry;
    const token = ++entry.token;
    for (const el of [item, header]) {
      el.removeAttribute('data-closed');
      el.removeAttribute('data-hidden');
      el.setAttribute('data-open', '');
    }
    trigger.removeAttribute('data-hidden');
    trigger.setAttribute('data-value', value);
    trigger.setAttribute('data-panel-open', '');
    trigger.setAttribute('aria-controls', panel.id);
    trigger.setAttribute('aria-expanded', 'true');

    panel.style.removeProperty('animation-name');
    panel.removeAttribute('hidden');
    panel.removeAttribute('data-hidden');
    panel.removeAttribute('data-closed');
    panel.removeAttribute('data-ending-style');
    panel.setAttribute('data-open', '');

    const finish = () => {
      if (entry.token !== token) return;
      panel.style.setProperty('--accordion-panel-height', 'auto');
      panel.style.setProperty('--accordion-panel-width', 'auto');
    };

    if (skipAnimation || !hasTransition(panel)) {
      panel.removeAttribute('data-starting-style');
      finish();
      return;
    }
    panel.setAttribute('data-starting-style', '');
    panel.style.setProperty('--accordion-panel-height', `${panel.scrollHeight}px`);
    panel.style.setProperty('--accordion-panel-width', `${panel.scrollWidth}px`);
    requestAnimationFrame(() => {
      if (entry.token !== token) return;
      panel.removeAttribute('data-starting-style');
      whenTransitionsDone(panel, finish);
    });
  }

  function closeItem(entry) {
    const { item, header, trigger, panel } = entry;
    const token = ++entry.token;
    for (const el of [item, header]) {
      el.removeAttribute('data-open');
      el.setAttribute('data-closed', '');
    }
    trigger.removeAttribute('data-panel-open');
    trigger.removeAttribute('aria-controls');
    trigger.setAttribute('aria-expanded', 'false');

    panel.removeAttribute('data-open');
    panel.setAttribute('data-closed', '');

    const finish = () => {
      if (entry.token !== token) return;
      panel.removeAttribute('data-ending-style');
      panel.setAttribute('data-starting-style', '');
      panel.setAttribute('hidden', 'until-found');
      panel.style.setProperty('--accordion-panel-height', 'auto');
      panel.style.setProperty('--accordion-panel-width', 'auto');
      for (const el of [item, header, trigger, panel]) el.setAttribute('data-hidden', '');
    };

    if (!hasTransition(panel)) {
      finish();
      return;
    }
    panel.style.setProperty('--accordion-panel-height', `${panel.scrollHeight}px`);
    panel.style.setProperty('--accordion-panel-width', `${panel.scrollWidth}px`);
    requestAnimationFrame(() => {
      if (entry.token !== token) return;
      panel.setAttribute('data-ending-style', '');
      whenTransitionsDone(panel, finish);
    });
  }

  for (const entry of items) {
    entry.trigger?.addEventListener('click', () => handleValueChange(entry.value));
    entry.panel?.addEventListener('beforematch', () => {
      if (!open.includes(entry.value)) setOpen([...open, entry.value], true);
    });
    if (entry.copy) wireCopy(entry.copy, entry.header.id);
  }

  const id = window.location.hash.substring(1);
  if (id.length > 0) {
    const selected = document.getElementById(id);
    if (selected && root.contains(selected)) {
      const value = selected.getAttribute('data-accordion-value');
      if (value) setOpen([value, ...open]);
    }
  }
}

function wireCopy(button, id) {
  const original = button.innerHTML;
  const svg = button.querySelector('svg');
  const extra = [...(svg?.classList ?? [])].filter((c) => c !== 'lucide' && !c.startsWith('lucide-')).join(' ');
  let timer = 0;
  button.addEventListener('click', () => {
    const url = new URL(window.location.href);
    url.hash = id;
    navigator.clipboard?.writeText(url.toString()).catch(() => {});
    button.innerHTML = icon('check', extra, CHECK_PATHS);
    clearTimeout(timer);
    timer = setTimeout(() => {
      button.innerHTML = original;
    }, 1500);
  });
}
