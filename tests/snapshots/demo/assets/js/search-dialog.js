// The search dialog: the reference theme's markup and keyboard behaviour, with Pholio's
// own engine (search.js) behind it.
//
// Usage:
//   import { mountSearchDialog } from './search-dialog.js';
//   const dialog = mountSearchDialog({ handle });
//   The search index URL comes from <meta name="nd-search-index" content="<base>/search-index.json">;
//   `indexUrl` overrides it for tests only.
//
// Structure (measured on the reference, reference export `states/search-*.html`):
//   Popup #fd-search-dialog-content
//     h2.hidden               title "Search", linked via aria-labelledby
//     div.flex…p-3            head: magnifier (pulses while loading),
//                             input [data-fd-search-dialog-input], ESC button
//     div[data-empty]         list, height animated via --fd-animated-height
//       div…max-h-[460px]     viewport, `hidden` while there are no hits
//   The visible backdrop and the footer are already in the static HTML
//   (components/search-dialog.php) and are only toggled here.
//
// Behaviour:
//   · the index loads once per page, when a trigger is hovered or focused or the
//     dialog opens; search-worker.js answers queries off the main thread, with
//     search.js on the main thread as the fallback when a module worker fails
//   · every keystroke sends the query; the worker answers only the newest one, an
//     answer for an older input value is dropped, and the list renders at most once
//     per animation frame as one markup write
//   · the magnifier pulses only when an answer takes longer than 120 ms
//   · empty input → items = null → data-empty="true", viewport hidden
//   · no hits → "No results found"
//   · ↑/↓ cycle through `items.at(idx % items.length)`, Enter navigates (Enter while
//     the answer for the current input is pending navigates once it arrives); a new
//     query selects its first result, a late answer for the same input keeps the selection,
//     pointer movement sets the active item, the active item is scrolled
//     `nearest` into the viewport
//   · answers are cached per query
//
// Labels come from i18n.js in the document language.

import { createDialog } from './dialog.js';
import { t } from './i18n.js';
import { html, icon, scrollIntoViewIfNeeded, clientId } from './util.js';

const ICON_SEARCH_PATHS = '<path d="m21 21-4.34-4.34"></path><circle cx="11" cy="11" r="8"></circle>';
const ICON_CHEVRON_PATHS = '<path d="m9 18 6-6-6-6"></path>';
const ICON_HASH_PATHS =
  '<line x1="4" x2="20" y1="9" y2="9"></line><line x1="4" x2="20" y1="15" y2="15"></line>'
  + '<line x1="10" x2="8" y1="3" y2="21"></line><line x1="16" x2="14" y1="3" y2="21"></line>';

// Classes per verify/CLASS-MAP.md: appearance hangs on nd component classes,
// states on attributes (data-empty, aria-selected, data-open/-closed).
const CLOSE_BUTTON_CLASS = 'nd-btn nd-search-close';

const POPUP_CLASS = 'nd-dialog';

const LOADING_DELAY_MS = 120;
const CACHE_SIZE = 100;

function escapeHtml(value) {
  return value.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}

// ---- One hit button ---------------------------------------------------------

// Plain text with the matched ranges wrapped as marks.
function renderContent(content, marks = []) {
  let out = '';
  let at = 0;
  for (const [start, end] of marks) {
    if (start < at) continue;
    out += `${escapeHtml(content.slice(at, start))}<span class="nd-search-mark">${escapeHtml(content.slice(start, end))}</span>`;
    at = end;
  }
  return `<p class="nd-search-md-p">${out}${escapeHtml(content.slice(at))}</p>`;
}

function renderItem(item, active) {
  const crumbs = (item.breadcrumbs ?? [])
    .map((crumb, i) => (i > 0 ? icon('chevron-right', 'nd-search-crumb-sep', ICON_CHEVRON_PATHS) : '') + escapeHtml(crumb))
    .join('');
  const line = item.type !== 'page'
    ? '<div role="none" class="nd-search-item-line"></div>'
    : '';
  const hash = item.type === 'heading'
    ? icon('hash', 'nd-search-item-hash', ICON_HASH_PATHS)
    : '';
  const variant = item.type === 'heading' ? 'nd-search-item-heading' : item.type === 'text' ? 'nd-search-item-text' : 'nd-search-item-page';
  // Active via aria-selected; .nd-search-item[aria-selected="true"] carries the look.
  return `<button type="button" aria-selected="${active}" class="nd-search-item">`
    + `<div class="nd-search-crumbs">${crumbs}</div>`
    + line
    + hash
    + `<div class="nd-search-item-body ${variant}">${renderContent(item.content, item.marks)}</div>`
    + '</button>';
}

// ---- Engine client ----------------------------------------------------------

// Loads the engine once and calls `deliver(query, items)` for every answer.
function connectEngine(url, deliver) {
  if (!url) {
    console.warn('search-dialog.js: <meta name="nd-search-index"> is missing, search stays empty.');
    return { query: (query) => deliver(query, []) };
  }
  const indexHref = new URL(url, document.baseURI).href;

  const mainThread = () => {
    const engine = fetch(indexHref)
      .then((res) => (res.ok ? res.json() : Promise.reject(new Error(`HTTP ${res.status}`))))
      .then((json) => import('./search.js').then((mod) => mod.createSearch(json)))
      .catch((error) => {
        console.warn(`search-dialog.js: search index not loaded (${error.message})`);
        return null;
      });
    return { query: (query) => engine.then((ready) => deliver(query, ready ? ready.search(query) : [])) };
  };

  let worker = null;
  try {
    worker = new Worker(new URL('./search-worker.js', import.meta.url), { type: 'module' });
  } catch {
    return mainThread();
  }

  let fallback = null;
  let last = null;
  worker.addEventListener('message', (event) => {
    const message = event.data;
    if (message?.type === 'result') deliver(message.query, message.items);
    else if (message?.type === 'error') console.warn(`search-dialog.js: search index not loaded (${message.message})`);
  });
  // A worker that fails to start (no module workers, blocked script) hands over to the main thread.
  worker.addEventListener('error', (event) => {
    event.preventDefault();
    if (fallback) return;
    worker.terminate();
    fallback = mainThread();
    if (last !== null) fallback.query(last);
  });
  worker.postMessage({ type: 'load', url: indexHref });

  return {
    query(query) {
      last = query;
      if (fallback) fallback.query(query);
      else worker.postMessage({ type: 'query', query });
    },
  };
}

// ---- The dialog -------------------------------------------------------------

export function mountSearchDialog({ handle = null, indexUrl = null } = {}) {
  const TEXTS = {
    search: t('Search(search dialog)'),
    closeSearch: t('Close Search(search dialog)(aria-label)'),
    noResults: t('No results found(search dialog)'),
  };

  // Id order as in the reference: the portal first, then the title
  // (React assigns useId in component order).
  const portalId = clientId();
  const titleId = clientId('base-ui-');
  const popup = html(
    `<div id="fd-search-dialog-content" role="dialog" tabindex="-1" data-base-ui-focusable="" class="${POPUP_CLASS}" aria-labelledby="${titleId}" style="--nested-dialogs: 0;">`
    + `<h2 id="${titleId}" class="nd-hidden">${TEXTS.search}</h2>`
    + '<div class="nd-search-head">'
    + icon('search', 'nd-search-head-icon', ICON_SEARCH_PATHS)
    + `<input data-fd-search-dialog-input="" placeholder="${TEXTS.search}" class="nd-search-input" value="">`
    + `<button type="button" aria-label="${TEXTS.closeSearch}" class="${CLOSE_BUTTON_CLASS}">ESC</button>`
    + '</div>'
    + '<div data-empty="true" class="nd-search-results">'
    + '<div class="nd-search-list nd-hidden"></div>'
    + '</div></div>',
  );

  const searchIcon = popup.querySelector('svg.lucide-search');
  const input = popup.querySelector('input[data-fd-search-dialog-input]');
  const closeButton = popup.querySelector('button[aria-label]');
  const list = popup.querySelector('[data-empty]');
  const viewport = list.firstElementChild;

  // Backdrop and footer are already in the HTML; when they are missing (a fixture
  // with the frozen reference DOM), they are added at the same position.
  let backdrop = document.querySelector('div[role="presentation"].nd-dialog-backdrop');
  if (!backdrop) {
    backdrop = html(
      '<div role="presentation" data-closed="" hidden="" class="nd-dialog-backdrop" style="user-select:none;-webkit-user-select:none"></div>',
    );
    const footer = html('<div class="nd-dialog-footer"></div>');
    const anchor = document.body.querySelector('#nd-notebook-layout, [data-home-layout], main');
    if (anchor) document.body.insertBefore(backdrop, anchor);
    else document.body.prepend(backdrop);
    backdrop.after(footer);
  }

  let items = null;            // null = no query, [] = no hits
  let itemsQuery = '';         // the input value `items` answers
  let rendered = null;         // the items the viewport currently shows
  let activeId = null;
  let engine = null;
  let frame = 0;
  let loadingTimer = 0;
  let enterPending = false;
  let navigatedQuery = null;   // the input value the selection was last moved in
  const cache = new Map();

  function setLoading(on) {
    searchIcon.classList.toggle('nd-search-loading', on);
  }

  function warm() {
    engine ??= connectEngine(indexUrl ?? document.querySelector('meta[name="nd-search-index"]')?.content, deliver);
    return engine;
  }

  function deliver(query, result) {
    cache.delete(query);
    cache.set(query, result);
    if (cache.size > CACHE_SIZE) cache.delete(cache.keys().next().value);
    if (query !== input.value) return;             // typed on in the meantime
    show(query, result);
  }

  function show(query, result) {
    items = result;
    itemsQuery = query;
    // A new query starts at the first result; a late answer for the input the user is
    // already navigating in keeps their selection.
    const keep = query === navigatedQuery && result?.some((item) => item.id === activeId);
    if (!keep) activeId = result && result.length ? result[0].id : null;
    scheduleRender();
  }

  function scheduleRender() {
    if (frame) return;
    frame = requestAnimationFrame(() => {
      frame = 0;
      clearTimeout(loadingTimer);
      setLoading(false);
      renderList();
      if (enterPending && itemsQuery === input.value) {
        enterPending = false;
        const selected = items?.find((item) => item.id === activeId);
        if (selected) select(selected);
      }
    });
  }

  function renderList() {
    rendered = items;
    list.setAttribute('data-empty', String(items === null));
    viewport.classList.toggle('nd-hidden', items === null);
    if (items === null) {
      viewport.textContent = '';
      return;
    }
    if (items.length === 0) {
      viewport.innerHTML = `<div class="nd-search-empty">${TEXTS.noResults}</div>`;
      return;
    }
    viewport.innerHTML = items.map((item) => renderItem(item, item.id === activeId)).join('');
    scrollActiveIntoView();
  }

  function setActive(id) {
    if (activeId === id) return;
    activeId = id;
    // A pending render applies the new state itself.
    if (frame || !rendered) return;
    const buttons = viewport.children;
    rendered.forEach((item, i) => {
      const selected = String(item.id === id);
      if (buttons[i] && buttons[i].getAttribute('aria-selected') !== selected) buttons[i].setAttribute('aria-selected', selected);
    });
    scrollActiveIntoView();
  }

  function scrollActiveIntoView() {
    const active = viewport.querySelector('[aria-selected="true"]');
    if (active) scrollIntoViewIfNeeded(active, viewport);
  }

  function select(item) {
    dialog.close();
    if (item.external) window.open(item.url, '_blank')?.focus();
    else window.location.href = item.url;
  }

  function runQuery(value) {
    if (value.trim() === '') {
      clearTimeout(loadingTimer);
      enterPending = false;
      show(value, null);
      return;
    }
    if (cache.has(value)) {
      show(value, cache.get(value));
      return;
    }
    clearTimeout(loadingTimer);
    loadingTimer = setTimeout(() => setLoading(true), LOADING_DELAY_MS);
    warm().query(value);
  }

  input.addEventListener('input', () => {
    // React mirrors the value of a controlled input into the attribute as well.
    input.setAttribute('value', input.value);
    runQuery(input.value);
  });

  // Pointer and click on the rows, delegated; rows map to `rendered` by position.
  const rowItem = (target) => {
    const button = target instanceof Element ? target.closest('button.nd-search-item') : null;
    if (!button || button.parentElement !== viewport || !rendered) return null;
    return rendered[Array.prototype.indexOf.call(viewport.children, button)] ?? null;
  };
  viewport.addEventListener('pointermove', (event) => {
    const item = rowItem(event.target);
    if (!item) return;
    navigatedQuery = input.value;
    setActive(item.id);
  });
  viewport.addEventListener('click', (event) => {
    const item = rowItem(event.target);
    if (item) select(item);
  });

  // Keyboard: the same rules as SearchDialogList.onKey.
  popup.addEventListener('keydown', (event) => {
    if (event.isComposing || event.keyCode === 229) return;
    const pending = input.value.trim() !== '' && itemsQuery !== input.value;
    if (event.key === 'Enter' && pending) {
      enterPending = true;
      event.preventDefault();
      return;
    }
    if (!items) return;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      let idx = items.findIndex((item) => item.id === activeId);
      if (idx === -1) idx = 0;
      else if (event.key === 'ArrowDown') idx += 1;
      else idx -= 1;
      navigatedQuery = input.value;
      setActive(items.at(idx % items.length)?.id ?? null);
      event.preventDefault();
    }
    if (event.key === 'Enter') {
      const selected = items.find((item) => item.id === activeId);
      if (selected) select(selected);
      event.preventDefault();
    }
  });

  // The list height follows the viewport (ResizeObserver as in the reference).
  // Observation starts only after the first open: React attaches the observer in
  // an effect after painting, so in the reference --fd-animated-height also shows
  // up in the style attribute only two frames after opening.
  const observer = new ResizeObserver(() => {
    list.style.setProperty('--fd-animated-height', `${viewport.clientHeight}px`);
  });

  const dialog = createDialog({
    popup,
    backdrop,
    handle,
    portalId,
    initialFocus: () => input,
    onOpenChange: (open) => {
      if (open) {
        warm();
        requestAnimationFrame(() => requestAnimationFrame(() => observer.observe(viewport)));
        return;
      }
      // On close Base UI detaches the popup; --fd-animated-height and the observer
      // are recreated on the next open, so reset them here.
      enterPending = false;
      observer.disconnect();
      list.style.removeProperty('--fd-animated-height');
      if (list.getAttribute('style') === '') list.removeAttribute('style');
    },
  });

  // Hovering or focusing a trigger starts loading the index before the click.
  const addTrigger = dialog.addTrigger;
  dialog.addTrigger = (el) => {
    addTrigger(el);
    el?.addEventListener('pointerenter', warm, { once: true });
    el?.addEventListener('focus', warm, { once: true });
  };

  closeButton.addEventListener('click', () => dialog.close());
  return dialog;
}
