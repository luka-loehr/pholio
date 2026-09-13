// Keyboard shortcuts: ⌘K / Ctrl K opens search, `d` toggles the theme.
//
// Usage:
//   import { initHotkeys } from './hotkeys.js';
//   initHotkeys({ searchHandle, theme });
//
// Original:
//   reference UI dist/contexts/search.js – SearchProvider.onKeyDown with
//     DEFAULT_HOT_KEYS = [{ key: e => e.metaKey || e.ctrlKey }, { key: 'k' }];
//     both conditions must hold (`hotKey.every`), then the open state is
//     toggled and the event is prevented. Input fields are explicitly NOT
//     excluded here – ⌘K also works inside the search field.
//   reference UI dist/provider/base.js – ThemeHotKey with hotKey "d":
//     bails out on defaultPrevented, isComposing, keyCode 229, on typing
//     targets (isTypingTarget, which includes anything inside [role="dialog"])
//     and whenever Meta, Ctrl or Alt is pressed.

import { isTypingTarget } from './util.js';

export function initHotkeys({ searchHandle = null, theme = null, themeHotKey = 'd' } = {}) {
  window.addEventListener('keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key === 'k') {
      searchHandle?.toggle();
      event.preventDefault();
      return;
    }
    if (!theme || themeHotKey === false) return;
    if (event.defaultPrevented || event.isComposing || event.keyCode === 229) return;
    if (isTypingTarget(event.target)) return;
    if (event.metaKey || event.ctrlKey || event.altKey) return;
    if (event.key.toLowerCase() !== String(themeHotKey).toLowerCase()) return;
    event.preventDefault();
    theme.toggle();
  });
}
