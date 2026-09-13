// sidebar.js — sidebar of the notebook layout.
//
// Sources (read, not guessed):
//   reference UI dist/components/sidebar/base.js
//       SidebarProvider   – `mode` from matchMedia('(width < 768px)'), closeOnRedirect
//       SidebarContent    – hover rules of the collapsed sidebar
//       SidebarDrawerOverlay / SidebarDrawerContent – data-state, invisible after animationend
//       SidebarFolder / SidebarFolderLink – state and click rules
//       SidebarTrigger    – aria-expanded and changing aria-label
//       useAutoScroll     – scrollIntoView with boundary #nd-sidebar or #nd-sidebar-mobile
//   reference UI dist/layouts/notebook/slots/sidebar.js   – collapsed sidebar, hover zone
//   reference UI dist/layouts/notebook/slots/container.js – --fd-sidebar-col, data-column-changed
//
// Additionally (not in the original): scroll position, open folders and the collapsed
// state survive page navigation in `sessionStorage`, because the static site reloads
// where Next switches pages client-side. Storage, folder keys, the parking spot of the
// non-matching variant and the restore before first paint are shared with
// sidebar-restore.js (`window.ndSidebarRestore`, rationale in that file's header).
//
// Classes (verify/CLASS-MAP.md): state lives only in attributes — `data-collapsed`,
// `data-hovered` and `data-collapse-transition` on the aside, `data-sidebar-collapsed` and
// `data-column-changed` on the layout, `data-state` on drawer and overlay, `aria-*` on the
// triggers. The CSS hooks onto them; the sidebar's class list stays `nd-sidebar`. The module
// sets classes only on elements it creates itself, and for the drawer's visibility switch.

import './sidebar-restore.js';
import { Collapsible } from './collapsible.js';
import { ScrollArea } from './scroll-area.js';
import { scrollIntoViewIfNeeded } from './scroll-into-view.js';
import { t } from './i18n.js';

const core = window.ndSidebarRestore;

/** All classes this module sets or removes. */
export const CLASSES = {
  hoverZone: 'nd-sidebar-hoverzone',
  drawerInvisible: 'nd-invisible',
};

/** Timing constants from SidebarContent (base.js). */
export const LEAVE_DELAY_FAR = 0;    // pointer further than 100 px from the edge
export const LEAVE_DELAY_NEAR = 500; // pointer close to the edge
export const EDGE_DISTANCE = 100;

/**
 * Label of the drawer trigger. SidebarTrigger (base.js) switches `aria-label`
 * with the state: open "Close Sidebar", closed "Open Sidebar". The texts come
 * from i18n.js in the document language; `data-label-open` / `data-label-close`
 * on the trigger override them.
 */
export const LABELS = {
  get open() { return t('Open Sidebar(sidebar)(aria-label)'); },
  get close() { return t('Close Sidebar(sidebar)(aria-label)'); },
};

/**
 * Removes the variant that doesn't match the window width and restores open
 * folders and scroll position. sidebar-restore.js runs the same function as a
 * classic script before first paint; a second call has no effect.
 */
export function bootSidebar(doc = document) {
  return core.apply(doc);
}

export class Sidebar {
  constructor(root = document) {
    this.doc = root;
    this.parked = window.__ndSidebarParked = window.__ndSidebarParked || {};
    this.layout = root.getElementById('nd-notebook-layout');
    // The non-matching variant may already be parked (bootSidebar or
    // sidebar-restore.js); then it only exists in the parking spot.
    this.placeholder = root.querySelector('[data-sidebar-placeholder]') ?? this.parked.desktop?.node ?? null;
    this.aside = root.getElementById('nd-sidebar') ?? this.placeholder?.querySelector('#nd-sidebar') ?? null;
    this.drawer = root.getElementById('nd-sidebar-mobile') ?? this.parked.drawer?.node ?? null;
    this.overlayTemplate = root.querySelector('template[data-sidebar-overlay]');
    this.overlay = null;
    this.collapseTrigger = root.querySelector('[data-sidebar-collapse-trigger], button[aria-label][data-collapsed]');
    this.drawerTriggers = [...root.querySelectorAll('[aria-controls="nd-sidebar-mobile"]')];

    this.mediaQuery = window.matchMedia(core.MOBILE_QUERY);
    this.mode = this.mediaQuery.matches ? 'drawer' : 'full';
    this.collapsed = this.aside?.getAttribute('data-collapsed') === 'true';
    this.hovered = false;
    this.open = false;
    this.leaveTimer = 0;
    this.hoverZone = null;
    this.folders = [];

    this.setupScrollAreas();
    this.setupFolders();
    this.setupCollapse();
    this.setupDrawer();
    this.setupPersistence();
    this.mediaQuery.addEventListener('change', () => this.applyMode());
    this.autoScrollActive();
  }

  static attach(root) {
    if (document.__ndSidebar) return document.__ndSidebar;
    const instance = new Sidebar(root ?? document);
    document.__ndSidebar = instance;
    return instance;
  }

  get activeAside() { return this.mode === 'drawer' ? this.drawer : this.aside; }

  // ---- ScrollArea ----------------------------------------------------------

  setupScrollAreas() {
    for (const aside of [this.aside, this.drawer]) {
      const viewport = aside?.querySelector('[data-id$="-viewport"]');
      if (viewport?.parentElement) ScrollArea.attach(viewport.parentElement);
    }
  }

  // ---- Folders -------------------------------------------------------------

  setupFolders() {
    for (const scope of [this.aside, this.drawer]) {
      if (scope) this.attachFolders(scope);
    }
  }

  /**
   * Binds all folders below `scope`. Also called for every freshly mounted
   * panel: its nested folders are new nodes from the template.
   * `core.folderRoots` only matches real folders — open panels also carry
   * `data-open` and have entry links as first children, but don't count.
   */
  attachFolders(scope) {
    for (const root of core.folderRoots(scope)) {
      const id = core.folderKey(root);
      const instance = Collapsible.attach(root, {
        onOpenChange: (open) => {
          const state = core.readStore();
          state.folders = { ...(state.folders || {}), [id]: open };
          core.writeStore(state);
        },
        onTriggerClick: (event) => this.handleFolderClick(root, event),
        onPanelMount: (panel) => this.attachFolders(panel),
      });
      if (instance) this.folders.push({ id, instance });
    }
  }

  /** SidebarFolderLink: the chevron toggles, the text opens – or toggles when already active. */
  handleFolderClick(root, event) {
    const trigger = event.currentTarget;
    if (trigger.tagName !== 'A') return true;
    const instance = root.__ndCollapsible;
    const target = event.target;
    if (target instanceof Element && target.matches('[data-icon], [data-icon] *')) {
      event.preventDefault();
      instance.setOpen(!instance.open, 'trigger-press');
      return false;
    }
    const active = trigger.getAttribute('data-active') === 'true';
    instance.setOpen(active ? !instance.open : true, 'trigger-press');
    return false;
  }

  // ---- Collapse (desktop) ---------------------------------------------------

  setupCollapse() {
    this.collapseTrigger?.addEventListener('click', () => this.setCollapsed(!this.collapsed));
    this.applyCollapsed(false);
  }

  setCollapsed(collapsed) {
    if (collapsed === this.collapsed) return;
    this.collapsed = collapsed;
    if (collapsed) this.hovered = false;
    const state = core.readStore();
    state.collapsed = collapsed;
    core.writeStore(state);
    this.applyCollapsed(true);
  }

  applyCollapsed(changed) {
    const collapsed = this.collapsed;
    this.collapseTrigger?.setAttribute('data-collapsed', String(collapsed));
    if (this.layout) {
      this.layout.setAttribute('data-sidebar-collapsed', String(collapsed));
      this.layout.setAttribute('data-column-changed', String(changed));
      this.layout.style.setProperty('--fd-sidebar-col', collapsed ? '0px' : 'var(--fd-sidebar-width)');
      if (changed) {
        // In the original `data-column-changed` is true for exactly one commit
        // (useEffect updates previousCollapsed afterwards).
        // Measured in the reference: `true` survives one frame, the second shows `false`.
        // The running grid-template-columns transition is not interrupted by this.
        requestAnimationFrame(() => requestAnimationFrame(() => {
          this.layout.setAttribute('data-column-changed', 'false');
        }));
      }
    }
    this.applyAsideState();
    this.applyHoverZone();
  }

  /**
   * State of the sidebar as attributes. Position, border, shadow and transitions of
   * the collapsed and the hovered sidebar live in layout.css on
   * `#nd-sidebar[data-collapsed][data-hovered]`, `data-collapse-transition` and
   * `data-column-changed`.
   *
   * Each call corresponds to one render of SidebarContent. The transition class
   * `transition-[width,inset-block,translate,background-color]` is present in the original
   * exactly when the `data-collapsed` of the **previous** render differs from the new
   * state (`ref.current.getAttribute("data-collapsed") === "true" !== collapsed`
   * in layouts/notebook/slots/sidebar.js). So it stays after collapsing or
   * expanding until the next render comes — in practice the next hover change.
   * `data-collapse-transition` reproduces exactly that; without a collapse
   * interaction the attribute is never present.
   */
  applyAsideState() {
    const aside = this.aside;
    if (!aside) return;
    const previous = aside.getAttribute('data-collapsed') === 'true';
    if (previous !== this.collapsed) aside.setAttribute('data-collapse-transition', 'true');
    else aside.removeAttribute('data-collapse-transition');
    aside.setAttribute('data-collapsed', String(this.collapsed));
    aside.setAttribute('data-hovered', String(this.collapsed && this.hovered));
  }

  // ---- Hover expand ---------------------------------------------------------

  applyHoverZone() {
    if (this.collapsed && !this.hoverZone && this.placeholder) {
      const zone = document.createElement('div');
      zone.className = CLASSES.hoverZone;
      zone.addEventListener('pointerenter', (e) => this.onPointerEnter(e));
      zone.addEventListener('pointerleave', (e) => this.onPointerLeave(e));
      this.placeholder.insertBefore(zone, this.placeholder.firstChild);
      this.hoverZone = zone;
      this.aside?.addEventListener('pointerenter', this.boundEnter ??= (e) => this.onPointerEnter(e));
      this.aside?.addEventListener('pointerleave', this.boundLeave ??= (e) => this.onPointerLeave(e));
    } else if (!this.collapsed && this.hoverZone) {
      this.hoverZone.remove();
      this.hoverZone = null;
    }
  }

  shouldIgnoreHover(event) {
    const el = this.aside;
    if (!el) return true;
    return !this.collapsed || event.pointerType === 'touch' || el.getAnimations().length > 0;
  }

  onPointerEnter(event) {
    if (this.shouldIgnoreHover(event)) return;
    clearTimeout(this.leaveTimer);
    if (this.hovered) return;
    // `pointerover` is not a discrete event; React therefore commits the state
    // only in the next frame. Measured in the reference: right after the
    // pointer event `data-hovered` is still false.
    cancelAnimationFrame(this.hoverFrame);
    clearTimeout(this.hoverTimer);
    this.hoverFrame = requestAnimationFrame(() => {
      this.hoverTimer = setTimeout(() => {
        // setHover(true) on an already true value does not re-render in React.
        if (this.hovered) return;
        this.hovered = true;
        this.applyAsideState();
      }, 0);
    });
  }

  onPointerLeave(event) {
    if (this.shouldIgnoreHover(event)) return;
    clearTimeout(this.leaveTimer);
    const distance = Math.min(event.clientX, document.body.clientWidth - event.clientX);
    const delay = distance > EDGE_DISTANCE ? LEAVE_DELAY_FAR : LEAVE_DELAY_NEAR;
    cancelAnimationFrame(this.hoverFrame);
    clearTimeout(this.hoverTimer);
    this.leaveTimer = setTimeout(() => {
      // No value change, no render — otherwise data-collapse-transition would drop too early.
      if (!this.hovered) return;
      this.hovered = false;
      this.applyAsideState();
    }, delay);
  }

  // ---- Drawer (< 768 px) ----------------------------------------------------

  setupDrawer() {
    this.drawerTriggers.forEach((btn) => btn.addEventListener('click', () => this.setOpen(!this.open)));
    this.drawer?.querySelectorAll('[aria-controls="nd-sidebar-mobile"]').forEach((btn) => {
      if (!this.drawerTriggers.includes(btn)) {
        this.drawerTriggers.push(btn);
        btn.addEventListener('click', () => this.setOpen(!this.open));
      }
    });
    this.drawer?.addEventListener('animationend', () => {
      if (!this.open) this.applyDrawerVisibility(true);
    });
    this.applyMode();
  }

  /** SidebarDrawerContent: invisible after closing (`nd-invisible`). */
  applyDrawerVisibility(hidden) {
    this.drawer?.classList.toggle(CLASSES.drawerInvisible, hidden);
  }

  ensureOverlay() {
    if (this.overlay || !this.overlayTemplate) return;
    const node = this.overlayTemplate.content.firstElementChild.cloneNode(true);
    this.overlayTemplate.parentNode.insertBefore(node, this.overlayTemplate);
    node.addEventListener('click', () => this.setOpen(false));
    node.addEventListener('animationend', () => {
      if (!this.open) { this.overlay?.remove(); this.overlay = null; }
    });
    this.overlay = node;
  }

  setOpen(open) {
    if (this.mode !== 'drawer' || open === this.open) return;
    this.open = open;
    if (open) { this.ensureOverlay(); this.applyDrawerVisibility(false); }
    this.overlay?.setAttribute('data-state', open ? 'open' : 'closed');
    this.drawer?.setAttribute('data-state', open ? 'open' : 'closed');
    this.drawerTriggers.forEach((btn) => {
      btn.setAttribute('aria-expanded', String(open));
      btn.setAttribute('aria-label', open ? (btn.dataset.labelClose || LABELS.close) : (btn.dataset.labelOpen || LABELS.open));
    });
  }

  /** Puts the variant matching the window width back into the DOM. */
  restore(key) {
    const entry = this.parked[key];
    if (!entry || entry.node.isConnected) return;
    // The remembered sibling may have been removed or moved in the meantime.
    const next = entry.next && entry.next.parentNode === entry.parent ? entry.next : null;
    entry.parent.insertBefore(entry.node, next);
    delete this.parked[key];
  }

  park(el, key) {
    if (!el || !el.isConnected) return;
    core.park(el, key);
  }

  applyMode() {
    const mode = this.mediaQuery.matches ? 'drawer' : 'full';
    this.mode = mode;
    if (mode === 'drawer') {
      this.park(this.placeholder, 'desktop');
      this.restore('drawer');
      this.drawer?.setAttribute('data-state', this.open ? 'open' : 'closed');
      if (!this.open) this.applyDrawerVisibility(true);
    } else {
      this.restore('desktop');
      this.park(this.drawer, 'drawer');
      this.overlay?.remove();
      this.overlay = null;
      this.open = false;
    }
  }

  // ---- Auto scroll and persistence ------------------------------------------

  /**
   * useAutoScroll (base.js): **every** active entry (SidebarItem, SidebarFolderLink)
   * calls `scrollIntoView(el, { boundary, scrollMode: 'if-needed' })` on mount, in
   * tree order; the last one wins. On pages below a menu link that is both the
   * menu link and the page entry — measured in the reference.
   *
   * useAutoScroll passes neither block nor inline; the original therefore centres
   * as soon as the entry is not visible (see scroll-into-view.js).
   *
   * Measurement condition as in the reference: there the drawer mounts only when
   * useMediaQuery (initial value null) switches to "drawer", and the effects run while
   * its close animation `fd-sidebar-out` stands at 0 ms, i.e. without offset. Here
   * the drawer is in the HTML from the start; when this module runs, the animation
   * has already started and pushes it to the right, so the entry sticks out over
   * the window edge and wrongly counts as hidden (measured: 16.5 px at 17 ms).
   * So running animations of the sidebar are set to 0 ms for the measurement and
   * synchronously reset to their position afterwards; nothing is painted in between.
   */
  autoScrollActive() {
    const aside = this.activeAside;
    if (!aside) return;
    const links = [...aside.querySelectorAll('a[data-active="true"]')];
    if (links.length === 0) return;
    withAnimationsAtStart(aside, () => {
      for (const link of links) scrollIntoViewIfNeeded(link, { boundary: aside, scrollMode: 'if-needed' });
    });
  }

  setupPersistence() {
    const save = () => {
      const viewport = this.activeAside?.querySelector('[data-id$="-viewport"]');
      const state = core.readStore();
      if (viewport) state.scrollTop = viewport.scrollTop;
      core.writeStore(state);
    };
    for (const aside of [this.aside, this.drawer]) {
      const viewport = aside?.querySelector('[data-id$="-viewport"]');
      viewport?.addEventListener('scroll', () => {
        clearTimeout(this.saveTimer);
        this.saveTimer = setTimeout(save, 100);
      }, { passive: true });
    }
    window.addEventListener('pagehide', save);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') save(); });
  }
}

/**
 * Runs `fn` while running or paused animations of `el` stand at 0 ms, and
 * restores their exact time afterwards. Finished animations are left alone
 * (rewinding would make them finish again and fire a second animationend).
 */
function withAnimationsAtStart(el, fn) {
  const seek = el.getAnimations().filter((a) => a.playState === 'running' || a.playState === 'paused');
  const saved = seek.map((a) => a.currentTime);
  for (const a of seek) a.currentTime = 0;
  try {
    fn();
  } finally {
    seek.forEach((a, i) => { a.currentTime = saved[i]; });
  }
}

export default Sidebar;

/**
 * Entry point for notebook.js. Without the classic sidebar-restore.js in the markup
 * (fixture, development) `bootSidebar` performs the restore here;
 * with the script in the markup the call has no effect.
 */
export function boot(doc = document) {
  bootSidebar(doc);
  Sidebar.attach(doc);
}
