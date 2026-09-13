// Page actions below the page description (components/page-actions.php).
//
// Usage:
//   import { boot } from './page-actions.js';
//   boot(document);
//
// Ported from the reference UI's layouts/shared/page-actions.js:
// · MarkdownCopyButton: copies the page's Markdown twin (data-markdown-url). The fetch
//   is cached; the first copy goes through a ClipboardItem with a promise, which keeps
//   the user activation in Safari. The button is disabled while loading and shows the
//   check for 1500 ms (use-copy-button.js), through data-checked.
// · ViewOptionsPopover: the menu is a popover (popover.js) filled from
//   <template data-page-actions-popup>. The chat links get their href here, because the
//   prompt names the absolute URL of the twin. Choosing a link closes the menu.
// · Not in the reference: ArrowDown, ArrowUp, Home and End move between the items, and
//   Copy llms.txt URL copies the index address with the same check cycle.

import { createPopover } from './popover.js';

// Classes of the popup (verify/CLASS-MAP.md).
const POPUP_CLASS = 'nd-popover nd-page-actions-popup';
const CHECKED_MS = 1500;

const booted = new WeakSet();
const timers = new WeakMap();

function markChecked(element) {
  window.clearTimeout(timers.get(element));
  element.setAttribute('data-checked', '');
  timers.set(element, window.setTimeout(() => element.removeAttribute('data-checked'), CHECKED_MS));
}

function chatUrl(action, prompt) {
  return action === 'chatgpt'
    ? `https://chatgpt.com/?${new URLSearchParams({ prompt, hints: 'search' })}`
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
    const llms = root.getAttribute('data-llms-url');
    const llmsUrl = llms ? new URL(llms, window.location.href).href : null;
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

    const copyButton = root.querySelector('button[data-page-copy]');
    if (copyButton) {
      copyButton.addEventListener('click', () => {
        let copied;
        if (cached) {
          copied = cached.then((text) => navigator.clipboard.writeText(text));
        } else {
          copyButton.disabled = true;
          const text = load();
          copied = typeof ClipboardItem === 'function' && navigator.clipboard.write
            ? navigator.clipboard.write([new ClipboardItem({ 'text/plain': text.then((body) => new Blob([body], { type: 'text/plain' })) })])
            : text.then((body) => navigator.clipboard.writeText(body));
          copied.finally(() => { copyButton.disabled = false; }).catch(() => {});
        }
        copied.then(() => markChecked(copyButton), () => { /* the clipboard may be denied */ });
      });
    }

    const trigger = root.querySelector('button[data-page-actions-trigger]');
    const template = root.querySelector('template[data-page-actions-popup]');
    if (!trigger || !template) continue;

    const popover = createPopover({
      trigger,
      popupClass: POPUP_CLASS,
      content: () => {
        const fragment = template.content.cloneNode(true);
        for (const item of fragment.querySelectorAll('.nd-page-actions-item')) {
          const action = item.getAttribute('data-page-action');
          item.addEventListener('keydown', moveFocus);
          if (action === 'chatgpt' || action === 'claude') item.setAttribute('href', chatUrl(action, prompt));
          if (action === 'llms') {
            item.addEventListener('click', () => {
              navigator.clipboard.writeText(llmsUrl).then(() => markChecked(item), () => { /* denied */ });
            });
          } else {
            item.addEventListener('click', () => popover.close());
          }
        }
        return fragment;
      },
    });
  }
}
