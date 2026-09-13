/*
 * sidebar-restore.js — restores the sidebar before first paint.
 *
 * Next switches pages without a reload; the static site reloads. So that the
 * sidebar doesn't jump, this script restores the open folders, the scroll
 * position and a collapsed sidebar from sessionStorage (`nd-sidebar-state`;
 * collapsed only on in-site navigation and back/forward, since the reference
 * starts expanded after a reload) and removes the variant
 * that doesn't match the window width (the reference renders only the drawer below
 * 768 px, only the desktop sidebar above).
 *
 * INCLUSION (CSP, no inline script): as a classic, synchronous script after
 * the last of the two variants, i.e. after `aside#nd-sidebar-mobile`
 * (the reference renders `SidebarDrawer` after `SidebarContent`):
 *
 *   <script src="…/assets/js/sidebar-restore.js"></script>
 *
 * There both variants are fully parsed and everything happens immediately. For
 * robustness the earlier place directly after `aside#nd-sidebar` works as well:
 * then the script waits with a MutationObserver until `#nd-sidebar-mobile` is
 * inserted, and releases the observer at the latest on DOMContentLoaded.
 *
 * FOLDER KEYS: preferably `data-folder-id` (path of the folder in the page tree,
 * written by the generator on each folder div). Without the attribute: the target of
 * a folder link, otherwise the path of folder names.
 *
 * SHARED CORE: `sidebar.js` (ES module) imports this file as a side effect and
 * uses the same functions through `window.ndSidebarRestore`. A classic script
 * cannot export anything and a module cannot run synchronously before first
 * paint; one file that works in both roles avoids maintaining the storage key,
 * folder keys and parking spot twice. It runs automatically only when loaded
 * classically (`document.currentScript` is set); as a module the file only
 * provides the functions.
 *
 * Class-independent: state lives exclusively in attributes
 * (`data-open`/`data-closed`, `data-panel-open`, `aria-expanded`, `aria-controls`).
 */
(function () {
  'use strict';

  if (window.ndSidebarRestore) {
    if (document.currentScript) window.ndSidebarRestore.apply(document);
    return;
  }

  var STORE_KEY = 'nd-sidebar-state';
  var MOBILE_QUERY = '(width < 768px)';
  var TEMPLATE_SELECTOR = ':scope > template[data-collapsible-panel]';
  var PANEL_SELECTOR = ':scope > div[id]';
  var COLLAPSE_TRIGGER_SELECTOR = '[data-sidebar-collapse-trigger], button[aria-label][data-collapsed]';

  function readStore() {
    try {
      return JSON.parse(sessionStorage.getItem(STORE_KEY) || '{}') || {};
    } catch (e) {
      return {};
    }
  }

  function writeStore(state) {
    try {
      sessionStorage.setItem(STORE_KEY, JSON.stringify(state));
    } catch (e) {
      /* private mode or blocked storage */
    }
  }

  function triggerOf(root) {
    var first = root.firstElementChild;
    return first && (first.tagName === 'BUTTON' || first.tagName === 'A') ? first : null;
  }

  /**
   * A folder is a `div[data-open|data-closed]` without `id` whose first child
   * is the trigger. Panels also carry `data-open`, but always have an
   * `id` and entry links as children — they must not count as folders.
   */
  function isFolderRoot(el) {
    return !!el && el.tagName === 'DIV' && !el.id &&
      (el.hasAttribute('data-open') || el.hasAttribute('data-closed')) && !!triggerOf(el);
  }

  function folderRoots(scope) {
    return Array.prototype.filter.call(scope.querySelectorAll('div[data-open], div[data-closed]'), isFolderRoot);
  }

  /**
   * Stable key of a folder across pages: the generator's `data-folder-id`,
   * otherwise the target of a folder link, otherwise the path of folder
   * names (independent of which panels are currently mounted).
   */
  function folderKey(root) {
    if (root.getAttribute('data-folder-id')) return root.getAttribute('data-folder-id');
    var trigger = triggerOf(root);
    if (trigger && trigger.tagName === 'A' && trigger.getAttribute('href')) return 'h:' + trigger.getAttribute('href');
    var labels = [];
    for (var node = root; node && node.id !== 'nd-sidebar' && node.id !== 'nd-sidebar-mobile'; node = node.parentElement) {
      if (isFolderRoot(node)) labels.unshift((triggerOf(node).textContent || '').trim());
    }
    return 't:' + labels.join('/');
  }

  /** Opens a folder in the state of a panel that is open on first render (without animation). */
  function openFolder(root) {
    var trigger = triggerOf(root);
    var panel = root.querySelector(PANEL_SELECTOR);
    if (!panel) {
      var template = root.querySelector(TEMPLATE_SELECTOR);
      if (!template || !template.content.firstElementChild) return false;
      panel = template.content.firstElementChild.cloneNode(true);
      root.insertBefore(panel, template);
    }
    panel.removeAttribute('data-closed');
    panel.removeAttribute('data-starting-style');
    panel.removeAttribute('data-ending-style');
    panel.removeAttribute('hidden');
    panel.setAttribute('data-open', '');
    panel.style.setProperty('--collapsible-panel-height', 'auto');
    panel.style.setProperty('--collapsible-panel-width', 'auto');
    // Like a server-rendered open Base UI panel: no opening animation on mount.
    panel.style.setProperty('animation-name', 'none');
    root.removeAttribute('data-closed');
    root.setAttribute('data-open', '');
    if (trigger) {
      if (trigger.tagName === 'BUTTON' || trigger.hasAttribute('aria-expanded')) trigger.setAttribute('aria-expanded', 'true');
      if (panel.id) trigger.setAttribute('aria-controls', panel.id);
      trigger.setAttribute('data-panel-open', '');
    }
    return true;
  }

  /** Closes a folder; the panel moves into the template so the folder can open again. */
  function closeFolder(root) {
    var trigger = triggerOf(root);
    var panel = root.querySelector(PANEL_SELECTOR);
    if (panel) {
      var template = root.querySelector(TEMPLATE_SELECTOR);
      if (!template) {
        template = document.createElement('template');
        template.setAttribute('data-collapsible-panel', '');
        root.appendChild(template);
      }
      if (template.content.firstElementChild) {
        panel.parentNode.removeChild(panel);
      } else {
        panel.style.removeProperty('animation-name');
        template.content.appendChild(panel);
      }
    }
    root.removeAttribute('data-open');
    root.setAttribute('data-closed', '');
    if (trigger) {
      if (trigger.tagName === 'BUTTON' || trigger.hasAttribute('aria-expanded')) trigger.setAttribute('aria-expanded', 'false');
      trigger.removeAttribute('aria-controls');
      trigger.removeAttribute('data-panel-open');
    }
    return true;
  }

  /** Open/closed folders; folders on the path of the current page stay open. */
  function restoreFolders(aside, state) {
    var wanted = state.folders || {};
    var changed = true;
    for (var pass = 0; changed && pass < 20; pass++) {
      changed = false;
      folderRoots(aside).forEach(function (root) {
        var key = folderKey(root);
        if (!Object.prototype.hasOwnProperty.call(wanted, key)) return;
        var open = root.hasAttribute('data-open');
        if (wanted[key] === true && !open) {
          if (openFolder(root)) changed = true;
        } else if (wanted[key] === false && open && !root.querySelector('[data-active="true"]')) {
          closeFolder(root);
          changed = true;
        }
      });
    }
  }

  function restoreAside(aside, state) {
    if (!aside) return;
    restoreFolders(aside, state);
    // Only after the folders: the scroll height depends on the opened panels.
    var viewport = aside.querySelector('[data-id$="-viewport"]');
    if (viewport && typeof state.scrollTop === 'number') viewport.scrollTop = state.scrollTop;
  }

  /**
   * Whether a collapsed sidebar carries over to this page. Next keeps the collapsed
   * state across client-side page changes and back/forward, but a reload or a freshly
   * opened page starts expanded (`useState(false)` in SidebarProvider). Link clicks
   * inside the site come with a same-origin referrer; typed URLs and bookmarks don't.
   */
  function keepsCollapsed(doc) {
    var entries = window.performance && performance.getEntriesByType ? performance.getEntriesByType('navigation') : [];
    var type = entries.length ? entries[0].type : 'navigate';
    if (type === 'reload') return false;
    if (type === 'back_forward') return true;
    try {
      return !!doc.referrer && new URL(doc.referrer).origin === window.location.origin;
    } catch (e) {
      return false;
    }
  }

  /**
   * Collapsed state as js/sidebar.js sets it, without transition: the header and the
   * layout are parsed before this script, the aside is passed in. sidebar.js reads
   * `data-collapsed` of the aside on start and takes over from there.
   */
  function restoreCollapsed(doc, aside) {
    var layout = doc.getElementById('nd-notebook-layout');
    if (layout) {
      layout.setAttribute('data-sidebar-collapsed', 'true');
      layout.style.setProperty('--fd-sidebar-col', '0px');
    }
    if (aside) aside.setAttribute('data-collapsed', 'true');
    var trigger = doc.querySelector(COLLAPSE_TRIGGER_SELECTOR);
    if (trigger) trigger.setAttribute('data-collapsed', 'true');
  }

  /** Remove the non-matching variant and keep it for a width change. */
  function park(el, key) {
    if (!el || !el.parentNode) return;
    var parked = (window.__ndSidebarParked = window.__ndSidebarParked || {});
    parked[key] = { node: el, parent: el.parentNode, next: el.nextSibling };
    el.parentNode.removeChild(el);
  }

  var applied = null;

  /** Corresponds to `bootSidebar(document)` in sidebar.js; repeated calls have no effect. */
  function apply(doc) {
    doc = doc || document;
    if (applied) return applied.state;
    var drawerMode = window.matchMedia(MOBILE_QUERY).matches;
    var state = readStore();
    var done = { desktop: false, drawer: false };
    applied = { state: state };

    function handle() {
      var desktop = doc.querySelector('[data-sidebar-placeholder]');
      var drawer = doc.getElementById('nd-sidebar-mobile');
      if (!done.desktop && desktop) {
        done.desktop = true;
        if (state.collapsed === true) {
          if (keepsCollapsed(doc)) {
            restoreCollapsed(doc, desktop.querySelector('#nd-sidebar'));
          } else {
            state.collapsed = false;
            writeStore(state);
          }
        }
        if (drawerMode) park(desktop, 'desktop');
        else restoreAside(desktop.querySelector('#nd-sidebar'), state);
      }
      if (!done.drawer && drawer) {
        done.drawer = true;
        if (drawerMode) restoreAside(drawer, state);
        else park(drawer, 'drawer');
      }
      return done.desktop && done.drawer;
    }

    if (handle() || doc.readyState !== 'loading') return state;
    var observer = new MutationObserver(function () {
      if (handle()) observer.disconnect();
    });
    observer.observe(doc.documentElement, { childList: true, subtree: true });
    doc.addEventListener('DOMContentLoaded', function () {
      handle();
      observer.disconnect();
    }, { once: true });
    return state;
  }

  window.ndSidebarRestore = {
    STORE_KEY: STORE_KEY,
    MOBILE_QUERY: MOBILE_QUERY,
    readStore: readStore,
    writeStore: writeStore,
    isFolderRoot: isFolderRoot,
    folderRoots: folderRoots,
    folderKey: folderKey,
    openFolder: openFolder,
    closeFolder: closeFolder,
    park: park,
    apply: apply,
  };

  if (document.currentScript) apply(document);
})();
