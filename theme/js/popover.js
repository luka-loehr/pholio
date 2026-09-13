// Base UI popover as a plain DOM module: positioning, phases, focus, closing.
//
// Usage:
//   import { createPopover } from './popover.js';
//   createPopover({ trigger, popupClass: '…', content: () => element });
//
// Ported is the feature set fumadocs-ui uses
// (components/ui/popover.js): side "bottom", sideOffset 4, align "center",
// flip and shift, portal at the end of <body>. Measured in the reference
// (states/tabs-*.html of the reference export):
//
//   positioner     <div data-open data-side="bottom" data-align="center"
//                       role="presentation" class="nd-popover-positioner"
//                       style="position: absolute; top: 0; left: 0;
//                              --available-width; --available-height;
//                              --anchor-width; --anchor-height;
//                              --transform-origin; transform: translate(x, y)">
//   inside         focus guard, popup, focus guard
//   popup          data-open data-side data-align data-starting-style
//                  id role="dialog" tabindex="-1" data-base-ui-focusable
//
//   On opening, positioner and popup carry `transition: none` for one frame
//   (Base UI suppresses the transition of the first measurement).
//   On closing via Esc or click outside, `data-instant="dismiss"` is added and
//   the positioner gets `pointer-events: none`; the animation fd-popover-out
//   (100 ms) still runs, then the portal is unmounted.
//
// Computed values (derived from the reference numbers, viewport 900 × 900,
// anchor 236 × 38, popup 240 wide, sideOffset 4, collisionPadding 5):
//   --anchor-width/-height   size of the trigger
//   --available-width        viewport width − 2 × padding
//   --available-height       viewport height − popup top edge − padding
//   --transform-origin       anchor centre relative to the popup edge, y = −sideOffset
//   transform                translate(popup position in document coordinates)

import { clientId, hydrationId, markOpen, markClosed, createFocusGuard, focusableWithin, onClickOutside } from './util.js';

const PADDING = 5;

export function createPopover({
  trigger,
  content,
  popupClass = '',
  side = 'bottom',
  align = 'center',
  sideOffset = 4,
  positionerClass = 'nd-popover-positioner',
}) {
  let isOpen = false;
  let portal = null;
  let positioner = null;
  let popup = null;
  let guards = [];
  let releaseOutside = null;
  const portalId = clientId();
  const popupId = hydrationId();

  // Positioning in a single pass. The style attribute is written at the end in
  // exactly the order Base UI writes it:
  //   position, top, left, --available-width, --available-height, [transition],
  //   --anchor-width, --anchor-height, --transform-origin, transform, [pointer-events]
  function measure({ transitionNone = false } = {}) {
    const anchor = trigger.getBoundingClientRect();
    const viewportWidth = document.documentElement.clientWidth;
    const viewportHeight = document.documentElement.clientHeight;

    // The popup is `--anchor-width` wide; without this variable it measures wrong.
    positioner.style.setProperty('--anchor-width', `${Math.round(anchor.width)}px`);
    positioner.style.setProperty('--anchor-height', `${Math.round(anchor.height)}px`);
    // offsetWidth/offsetHeight instead of getBoundingClientRect: while fd-popover-in
    // runs, the animation shrinks the rectangle (scale 0.95).
    const popRect = { width: popup.offsetWidth, height: popup.offsetHeight };

    // Flip: upwards when there is no room left below.
    let usedSide = side;
    const below = viewportHeight - anchor.bottom - sideOffset - PADDING;
    const above = anchor.top - sideOffset - PADDING;
    if (side === 'bottom' && popRect.height > below && above > below) usedSide = 'top';

    const top = usedSide === 'bottom'
      ? anchor.bottom + sideOffset
      : anchor.top - sideOffset - popRect.height;

    // Align center, then shift into the viewport.
    let left = align === 'start'
      ? anchor.left
      : align === 'end'
        ? anchor.right - popRect.width
        : anchor.left + anchor.width / 2 - popRect.width / 2;
    const maxLeft = viewportWidth - popRect.width - PADDING;
    left = Math.max(PADDING, Math.min(left, Math.max(PADDING, maxLeft)));

    const availableHeight = usedSide === 'bottom' ? viewportHeight - top - PADDING : top - PADDING;
    const originX = anchor.left + anchor.width / 2 - left;
    const originY = usedSide === 'bottom' ? -sideOffset : popRect.height + sideOffset;

    const parts = [
      'position: absolute',
      'top: 0px',
      'left: 0px',
      `--available-width: ${Math.round(viewportWidth - PADDING * 2)}px`,
      `--available-height: ${Math.round(availableHeight)}px`,
    ];
    if (transitionNone) parts.push('transition: none');
    parts.push(
      `--anchor-width: ${Math.round(anchor.width)}px`,
      `--anchor-height: ${Math.round(anchor.height)}px`,
      `--transform-origin: ${Math.round(originX)}px ${Math.round(originY)}px`,
      `transform: translate(${Math.round(left + window.scrollX)}px, ${Math.round(top + window.scrollY)}px)`,
    );
    positioner.setAttribute('style', `${parts.join('; ')};`);

    positioner.setAttribute('data-side', usedSide);
    positioner.setAttribute('data-align', align);
    popup.setAttribute('data-side', usedSide);
    popup.setAttribute('data-align', align);
  }

  // Base UI also silences everything outside the portal for a modal popover –
  // unlike the dialog, only with data-base-ui-inert and without aria-hidden.
  function setOutsideInert(on) {
    for (const child of [...document.body.children]) {
      if (child === portal) continue;
      if (on) child.setAttribute('data-base-ui-inert', '');
      else child.removeAttribute('data-base-ui-inert');
    }
  }

  function open() {
    if (isOpen) return;
    isOpen = true;
    trigger.setAttribute('aria-expanded', 'true');
    trigger.setAttribute('data-popup-open', '');

    portal = document.createElement('div');
    portal.id = portalId;
    portal.setAttribute('data-base-ui-portal', '');

    positioner = document.createElement('div');
    positioner.setAttribute('role', 'presentation');
    positioner.className = positionerClass;

    popup = document.createElement('div');
    popup.id = popupId;
    popup.setAttribute('role', 'dialog');
    popup.setAttribute('tabindex', '-1');
    popup.setAttribute('data-base-ui-focusable', '');
    popup.className = popupClass;
    const node = typeof content === 'function' ? content() : content;
    if (node) popup.append(node);

    guards = [createFocusGuard(), createFocusGuard()];
    positioner.append(guards[0], popup, guards[1]);
    portal.append(positioner);
    document.body.append(portal);
    setOutsideInert(true);

    // First measurement without transition, exactly like Base UI.
    popup.style.setProperty('transition', 'none');
    measure({ transitionNone: true });
    positioner.setAttribute('data-open', '');
    markOpen(popup);

    requestAnimationFrame(() => {
      if (!isOpen) return;
      measure();
      // React leaves an empty style attribute behind when `transition: none` is dropped.
      popup.setAttribute('style', '');
      const first = focusableWithin(popup)[0];
      (first ?? popup).focus();
    });

    releaseOutside = onClickOutside([popup, trigger], () => close('dismiss'));
    document.addEventListener('keydown', onKeyDown, true);
  }

  function onKeyDown(event) {
    if (event.key !== 'Escape' || event.defaultPrevented) return;
    event.preventDefault();
    close('dismiss');
  }

  function close(reason = null) {
    if (!isOpen) return;
    isOpen = false;
    trigger.setAttribute('aria-expanded', 'false');
    trigger.removeAttribute('data-popup-open');
    document.removeEventListener('keydown', onKeyDown, true);
    releaseOutside?.();
    releaseOutside = null;

    setOutsideInert(false);
    positioner.removeAttribute('data-open');
    positioner.setAttribute('data-closed', '');
    if (reason) {
      positioner.setAttribute('data-instant', reason);
      popup.setAttribute('data-instant', reason);
    }
    positioner.setAttribute('style', `${positioner.getAttribute('style')} pointer-events: none;`);

    markClosed(popup, () => {
      portal?.remove();
      portal = null;
      positioner = null;
      popup = null;
      guards = [];
      // Base UI always returns focus to the trigger, even after a click
      // outside (FloatingFocusManager returnFocus).
      trigger.focus();
    });
  }

  trigger.addEventListener('click', (event) => {
    event.preventDefault();
    if (isOpen) close();
    else open();
  });

  return {
    get isOpen() { return isOpen; },
    open,
    close,
    get popup() { return popup; },
  };
}
