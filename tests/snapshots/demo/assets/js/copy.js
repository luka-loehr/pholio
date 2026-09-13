// Copy buttons: heading anchors and code blocks.
//
// Usage:
//   import { initCopyButtons } from './copy.js';
//   initCopyButtons();
//
// Original:
//   reference UI dist/components/heading.js – the button next to each heading
//     copies the current address with `#id` as hash.
//   reference UI dist/utils/use-copy-button.js – after a successful copy the
//     check mark shows for 1500 ms; a running timer is cancelled first.
//     `LinkIcon` becomes `CopyCheckIcon` and back.

import { icon } from './util.js';

const ICON_LINK = '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>'
  + '<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>';
const ICON_COPY_CHECK = '<path d="m12 15 2 2 4-4"></path>'
  + '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"></rect>'
  + '<path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"></path>';

// The state change from useCopyButton: copy, then 1500 ms confirmation.
function copyButton(button, copy, onChecked, onReset) {
  let timer = null;
  button.addEventListener('click', () => {
    if (timer) window.clearTimeout(timer);
    Promise.resolve(copy()).then(() => {
      onChecked();
      timer = window.setTimeout(() => {
        timer = null;
        onReset();
      }, 1500);
    }, () => { /* the clipboard may be denied */ });
  });
}

export function initCopyButtons(root = document) {
  // Headings with a copy button carry nd-heading (verify/CLASS-MAP.md).
  for (const heading of root.querySelectorAll('.nd-heading')) {
    const button = heading.querySelector('button[aria-label]');
    const anchor = heading.querySelector('a[data-card]');
    if (!button || !heading.id) continue;
    copyButton(
      button,
      () => {
        const url = new URL(window.location.href);
        url.hash = heading.id;
        return navigator.clipboard.writeText(url.href);
      },
      () => { button.innerHTML = icon('copy-check', '', ICON_COPY_CHECK); },
      () => { button.innerHTML = icon('link', '', ICON_LINK); },
    );
    void anchor;
  }

  // Code block buttons use the same check-mark cycle.
  for (const button of root.querySelectorAll('button[data-copy-code]')) {
    const pre = button.closest('figure, div')?.querySelector('pre');
    if (!pre) continue;
    copyButton(
      button,
      () => navigator.clipboard.writeText(pre.textContent ?? ''),
      () => button.setAttribute('data-checked', ''),
      () => button.removeAttribute('data-checked'),
    );
  }
}
