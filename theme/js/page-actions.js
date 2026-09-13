// Page menu next to the page title (components/page-actions.php).
//
// Usage:
//   import { boot } from './page-actions.js';
//   boot(document);
//
// · "Copy page" (the button and the first menu item) copies the page's Markdown twin
//   (data-markdown-url). The fetch is cached; the first copy goes through a ClipboardItem
//   with a promise, which keeps the user activation in Safari. After a successful copy
//   the element carries data-checked for 2000 ms; the CSS shows the check and "Copied".
// · The chevron opens the menu, a popover (popover.js) filled from
//   <template data-page-actions-popup>, aligned to the end of the trigger; the chevron
//   rotates through data-popup-open. The chat links get their href here, because the
//   prompt names the absolute URL of the twin. Choosing a chat link closes the menu.
// · ArrowDown, ArrowUp, Home and End move between the items; Escape and a click outside
//   close the menu and return focus to the chevron (popover.js).

import { createPopover } from './popover.js';

// Classes of the popup (verify/CLASS-MAP.md).
const POPUP_CLASS = 'nd-popover nd-page-actions-popup';
const CHECKED_MS = 2000;

const booted = new WeakSet();
const timers = new WeakMap();

function markChecked(element) {
  window.clearTimeout(timers.get(element));
  element.setAttribute('data-checked', '');
  timers.set(element, window.setTimeout(() => element.removeAttribute('data-checked'), CHECKED_MS));
}

/** The chat URL with the prompt prefilled. */
export function chatUrl(action, prompt) {
  return action === 'chatgpt'
    ? `https://chatgpt.com/?${new URLSearchParams({ hints: 'search', prompt })}`
    : `https://claude.ai/new?${new URLSearchParams({ q: prompt })}`;
}

function moveFocus(event) {
  if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
  const items = [...event.currentTarget.parentElement.querySelectorAll('.nd-page-actions-item')];
  const index = items.indexOf(event.currentTarget);
  const next = {
    ArrowDown: items[(index + 1) % items.length],
    ArrowUp: items[(index - 1 + items.length) % items.length],
    Home: items[0],
    End: items[items.length - 1],
  }[event.key];
  event.preventDefault();
  next?.focus();
}

export function boot(doc = document) {
  for (const root of doc.querySelectorAll('[data-page-actions]')) {
    if (booted.has(root)) continue;
    booted.add(root);

    const markdownUrl = new URL(root.getAttribute('data-markdown-url'), window.location.href).href;
    const prompt = (root.getAttribute('data-prompt') ?? '').replace('{url}', markdownUrl);

    let cached = null;
    const load = () => {
      cached ??= fetch(markdownUrl, { headers: { Accept: 'text/markdown' } })
        .then((response) => (response.ok ? response.text() : Promise.reject(new Error(`HTTP ${response.status}`))))
        .catch((err) => {
          cached = null;
          throw err;
        });
      return cached;
    };

    // Copies the twin and marks `element` as checked.
    const copy = (element) => {
      let copied;
      if (cached) {
        copied = cached.then((text) => navigator.clipboard.writeText(text));
      } else if (typeof ClipboardItem === 'function' && navigator.clipboard.write) {
        const blob = load().then((body) => new Blob([body], { type: 'text/plain' }));
        copied = navigator.clipboard.write([new ClipboardItem({ 'text/plain': blob })]);
      } else {
        copied = load().then((body) => navigator.clipboard.writeText(body));
      }
      return copied.then(() => markChecked(element), () => { /* the clipboard may be denied */ });
    };

    const copyButton = root.querySelector('button[data-page-copy]');
    copyButton?.addEventListener('click', () => copy(copyButton));

    const trigger = root.querySelector('button[data-page-actions-trigger]');
    const template = root.querySelector('template[data-page-actions-popup]');
    if (!trigger || !template) continue;

    const popover = createPopover({
      trigger,
      popupClass: POPUP_CLASS,
      align: 'end',
      content: () => {
        const fragment = template.content.cloneNode(true);
        for (const item of fragment.querySelectorAll('.nd-page-actions-item')) {
          const action = item.getAttribute('data-page-action');
          item.addEventListener('keydown', moveFocus);
          if (action === 'copy') {
            item.addEventListener('click', () => copy(item));
          } else {
            item.setAttribute('href', chatUrl(action, prompt));
            item.addEventListener('click', () => popover.close());
          }
        }
        return fragment;
      },
    });
  }
}
