// Page actions next to the page title (components/page-actions.php).
//
// Usage:
//   import { boot } from './page-actions.js';
//   boot(document);
//
// · "Copy page" copies the page's Markdown twin (data-markdown-url). The text is
//   fetched ahead on hover and focus, so the copy usually runs inside the click;
//   otherwise it goes through a ClipboardItem with a promise, which keeps the user
//   activation in Safari, or through a fetch followed by writeText.
// · The menu is a popover (popover.js) filled from <template data-page-actions-popup>.
//   The chat links get their href here, because the prompt names the absolute URL
//   of the twin. Choosing a link closes the menu.
// · ArrowDown, ArrowUp, Home and End move between the menu items; Escape and a click
//   outside close it and return focus to the trigger (popover.js).
// · After a successful copy the button carries data-checked for 1500 ms, like the
//   code block buttons (copy.js); the CSS swaps the icon.

import { createPopover } from './popover.js';

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
  const query = encodeURIComponent(prompt);
  return action === 'chatgpt' ? `https://chatgpt.com/?hints=search&q=${query}` : `https://claude.ai/new?q=${query}`;
}

function moveFocus(event) {
  const keys = ['ArrowDown', 'ArrowUp', 'Home', 'End'];
  if (!keys.includes(event.key)) return;
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

    let text = null;
    let pending = null;
    const load = () => {
      pending ??= fetch(markdownUrl, { headers: { Accept: 'text/markdown' } })
        .then((response) => (response.ok ? response.text() : Promise.reject(new Error(`HTTP ${response.status}`))))
        .then((body) => {
          text = body;
          return body;
        })
        .catch((err) => {
          pending = null;
          throw err;
        });
      return pending;
    };

    const copyButton = root.querySelector('button[data-page-copy]');
    if (copyButton) {
      const warm = () => load().catch(() => { /* retried on click */ });
      copyButton.addEventListener('pointerenter', warm);
      copyButton.addEventListener('focus', warm);
      copyButton.addEventListener('click', () => {
        let copied;
        if (text !== null) {
          copied = navigator.clipboard.writeText(text);
        } else if (typeof ClipboardItem === 'function' && navigator.clipboard.write) {
          const blob = load().then((body) => new Blob([body], { type: 'text/plain' }));
          copied = navigator.clipboard.write([new ClipboardItem({ 'text/plain': blob })]);
        } else {
          copied = load().then((body) => navigator.clipboard.writeText(body));
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
      align: 'end',
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
