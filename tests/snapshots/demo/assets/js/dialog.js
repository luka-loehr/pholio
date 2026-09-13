// Base UI dialog as a plain DOM module: portal, backdrop, focus trap, scroll lock.
//
// Usage:
//   import { createDialog, createHandle } from './dialog.js';
//   const handle = createHandle();                       // usable before the dialog exists (hotkeys)
//   const dialog = createDialog({ popup, backdrop, handle, initialFocus: () => input });
//   dialog.addTrigger(document.querySelector('[data-search-full]'));
//
// Ported is the part of @base-ui/react/dialog the search dialog needs: modal, with
// backdrop, without description, with its own `initialFocus`. Attributes and their
// order follow Base UI, because the CSS and the animations hang on them:
//
//   Click on the trigger (synchronous):
//     · the trigger gets aria-expanded="true"
//     · portal `<div id="_r_0_" data-base-ui-portal>` at the end of <body>
//     · inside: internal backdrop (role="presentation", inset 0, user-select none),
//       focus guard, popup, focus guard
//     · popup and visible backdrop carry data-open + data-starting-style
//     · all other children of <body> get aria-hidden="true" data-base-ui-inert
//   one frame later: focus into the input (initialFocus)
//   two frames later: data-starting-style is dropped, body gets overflow:hidden
//   Close (synchronous): data-closed + data-ending-style, scroll lock gone,
//     inert gone; after the animations end the portal is removed and focus returns.
//
// The scroll lock is only `overflow: hidden` on <body>: thanks to
// `scrollbar-gutter` no margin compensation is needed.

import { clientId, markOpen, markClosed, createFocusGuard, wireFocusGuards, onClickOutside } from './util.js';

// Dialog.createHandle(): a handle shared by several triggers, even before the
// dialog itself exists (the hotkeys are registered earlier than the content).
export function createHandle() {
  const handle = {
    dialog: null,
    open() { handle.dialog?.open(); },
    close() { handle.dialog?.close(); },
    toggle() { handle.dialog?.toggle(); },
    get isOpen() { return Boolean(handle.dialog?.isOpen); },
  };
  return handle;
}

const INTERNAL_BACKDROP_STYLE = 'position: fixed; inset: 0px; user-select: none;';

export function createDialog({ popup, backdrop = null, handle = null, initialFocus = null, onOpenChange = null, portalId = null }) {
  let isOpen = false;
  let portal = null;
  let guards = [];
  let internalBackdrop = null;
  let releaseTrap = null;
  let releaseOutside = null;
  let lastTrigger = null;
  const triggers = [];
  const id = portalId ?? clientId();

  // Everything outside the portal is silenced for screen readers and keyboard.
  function setOutsideInert(on) {
    for (const child of [...document.body.children]) {
      if (child === portal) continue;
      if (on) {
        child.setAttribute('aria-hidden', 'true');
        child.setAttribute('data-base-ui-inert', '');
      } else {
        child.removeAttribute('aria-hidden');
        child.removeAttribute('data-base-ui-inert');
      }
    }
  }

  function buildPortal() {
    portal = document.createElement('div');
    portal.id = id;
    portal.setAttribute('data-base-ui-portal', '');

    internalBackdrop = document.createElement('div');
    internalBackdrop.setAttribute('role', 'presentation');
    internalBackdrop.setAttribute('data-base-ui-inert', '');
    internalBackdrop.setAttribute('aria-hidden', 'true');
    internalBackdrop.setAttribute('style', INTERNAL_BACKDROP_STYLE);

    guards = [createFocusGuard(), createFocusGuard()];
    guards.forEach((g) => g.setAttribute('data-base-ui-inert', ''));

    portal.append(internalBackdrop, guards[0], popup, guards[1]);
    document.body.append(portal);
  }

  function onKeyDown(event) {
    if (event.key !== 'Escape' || event.defaultPrevented) return;
    event.preventDefault();
    close();
  }

  // `source` is the trigger that opened the dialog. Only it gets aria-expanded="true";
  // when opening through the handle (hotkey ⌘K) all triggers stay "false".
  function open(source = null) {
    if (isOpen) return;
    isOpen = true;
    lastTrigger = document.activeElement instanceof HTMLElement ? document.activeElement : source ?? triggers[0] ?? null;
    if (source) source.setAttribute('aria-expanded', 'true');

    buildPortal();
    setOutsideInert(true);
    markOpen(popup);
    if (backdrop) {
      backdrop.removeAttribute('hidden');
      markOpen(backdrop);
    }

    // Focus one frame after mounting, once the popup is laid out.
    requestAnimationFrame(() => {
      if (!isOpen) return;
      const target = typeof initialFocus === 'function' ? initialFocus() : initialFocus;
      if (target && typeof target.focus === 'function') target.focus();
      else popup.focus();
    });
    // Scroll lock one more frame later, together with dropping
    // data-starting-style (Base UI sets it in a passive effect).
    requestAnimationFrame(() => requestAnimationFrame(() => {
      if (isOpen) document.body.style.overflow = 'hidden';
    }));

    releaseTrap = wireFocusGuards(guards, popup);
    releaseOutside = onClickOutside([popup], () => close());
    document.addEventListener('keydown', onKeyDown, true);
    onOpenChange?.(true);
  }

  function close() {
    if (!isOpen) return;
    isOpen = false;
    triggers.forEach((t) => t.setAttribute('aria-expanded', 'false'));
    document.body.style.overflow = '';
    setOutsideInert(false);
    document.removeEventListener('keydown', onKeyDown, true);
    releaseTrap?.();
    releaseOutside?.();
    releaseTrap = null;
    releaseOutside = null;

    // On closing, the internal backdrop swaps its inert form and aria-hidden,
    // and the focus guards lose data-base-ui-inert.
    if (internalBackdrop) {
      internalBackdrop.removeAttribute('data-base-ui-inert');
      internalBackdrop.removeAttribute('aria-hidden');
      internalBackdrop.setAttribute('inert', '');
    }
    guards.forEach((g) => g.removeAttribute('data-base-ui-inert'));

    let pending = backdrop ? 2 : 1;
    const finish = () => {
      pending -= 1;
      if (pending > 0 || isOpen) return;
      portal?.remove();
      portal = null;
      internalBackdrop = null;
      guards = [];
      popup.removeAttribute('data-ending-style');
      if (backdrop) {
        backdrop.removeAttribute('data-ending-style');
        backdrop.setAttribute('hidden', '');
      }
      lastTrigger?.focus();
      onOpenChange?.(false);
    };
    markClosed(popup, finish);
    if (backdrop) markClosed(backdrop, finish);
  }

  const api = {
    get isOpen() { return isOpen; },
    open,
    close,
    toggle(source = null) { if (isOpen) close(); else open(source); },
    get popup() { return popup; },
    addTrigger(el) {
      if (!el || triggers.includes(el)) return;
      triggers.push(el);
      el.setAttribute('aria-expanded', String(isOpen));
      el.addEventListener('click', (event) => {
        event.preventDefault();
        if (isOpen) close();
        else open(el);
      });
    },
  };
  if (handle) handle.dialog = api;
  return api;
}
