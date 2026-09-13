// The search dialog: exactly the content fumadocs-ui renders (search-default.js).
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
// Behaviour as in the reference:
//   · 100 ms debounce (useDocsSearch, delayMs = 100), loading state on the magnifier
//   · empty input → items = null → data-empty="true", viewport hidden
//   · no hits → "No results found"
//   · ↑/↓ cycle through `items.at(idx % items.length)`, Enter navigates,
//     pointer movement sets the active item, the active item is scrolled
//     `nearest` into the viewport
//   · result cache per query, the index is loaded on first open
//
// Labels come from i18n.js in the document language.
//
// The search itself lives in search.js (createSearch from the index JSON);
// this module only knows `search(query) → items` from it.

import { createDialog } from './dialog.js';
import { t } from './i18n.js';
import { html, icon, debounce, scrollIntoViewIfNeeded, clientId } from './util.js';

const ICON_SEARCH_PATHS = '<path d="m21 21-4.34-4.34"></path><circle cx="11" cy="11" r="8"></circle>';
const ICON_CHEVRON_PATHS = '<path d="m9 18 6-6-6-6"></path>';
const ICON_HASH_PATHS =
  '<line x1="4" x2="20" y1="9" y2="9"></line><line x1="4" x2="20" y1="15" y2="15"></line>'
  + '<line x1="10" x2="8" y1="3" y2="21"></line><line x1="16" x2="14" y1="3" y2="21"></line>';

// Classes per verify/CLASS-MAP.md: appearance hangs on nd component classes,
// states on attributes (data-empty, aria-selected, data-open/-closed).
const CLOSE_BUTTON_CLASS = 'nd-btn nd-search-close';

const POPUP_CLASS = 'nd-dialog';

// ---- Markdown of the hits ---------------------------------------------------
//
// `search.js` returns Markdown with <mark> tags. It is rendered with the minimal
// table from the reference's search.js (mdComponents): mark → span.text-fd-primary,
// a → span, p → p.min-w-0, strong, code. No other nodes occur in the index.

function escapeHtml(value) {
  return value.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}

// mdComponents.custom from the reference's search.js: rehypeCustomElements replaces
// every element the browser doesn't know (`<Card …/>` from the Markdown) with a tile
// showing the tag name and one field per attribute.
const CUSTOM_TAG = /^<([A-Za-z][A-Za-z0-9-]*)((?:\s+[A-Za-z-]+="[^"]*")*)\s*(\/?)>/;

function renderCustom(match) {
  const tagName = match[1].toLowerCase();
  const attrs = [...match[2].matchAll(/([A-Za-z-]+)="([^"]*)"/g)];
  const fields = attrs
    .map(([, key, value]) => `<code class="nd-search-tag-field">`
      + `<span class="nd-search-tag-key">${escapeHtml(key)}: </span>${escapeHtml(value)}</code>`)
    .join('');
  return `<span class="nd-search-tag"><code class="nd-search-tag-name">${escapeHtml(tagName)}</code>${fields}</span>`;
}

function renderInline(text) {
  let out = '';
  let i = 0;
  while (i < text.length) {
    if (text.startsWith('<mark>', i)) {
      const end = text.indexOf('</mark>', i);
      const inner = end < 0 ? text.slice(i + 6) : text.slice(i + 6, end);
      out += `<span class="nd-search-mark">${renderInline(inner)}</span>`;
      i = end < 0 ? text.length : end + 7;
      continue;
    }
    if (text[i] === '<' && !text.startsWith('</', i)) {
      const match = CUSTOM_TAG.exec(text.slice(i));
      if (match && document.createElement(match[1]) instanceof HTMLUnknownElement) {
        out += renderCustom(match);
        i += match[0].length;
        // Child content up to the closing tag is appended.
        const close = `</${match[1]}>`;
        const end = match[3] === '/' ? -1 : text.indexOf(close, i);
        if (end >= 0) {
          out = `${out.slice(0, -7)}<span class="nd-search-tag-children">${renderInline(text.slice(i, end))}</span></span>`;
          i = end + close.length;
        }
        continue;
      }
    }
    if (text.startsWith('**', i)) {
      const end = text.indexOf('**', i + 2);
      if (end > 0) {
        out += `<strong class="nd-search-strong">${renderInline(text.slice(i + 2, end))}</strong>`;
        i = end + 2;
        continue;
      }
    }
    if (text[i] === '`') {
      const end = text.indexOf('`', i + 1);
      if (end > 0) {
        out += `<code class="nd-search-code">${escapeHtml(text.slice(i + 1, end))}</code>`;
        i = end + 1;
        continue;
      }
    }
    if (text[i] === '\\' && i + 1 < text.length) {
      out += escapeHtml(text[i + 1]);
      i += 2;
      continue;
    }
    out += escapeHtml(text[i]);
    i += 1;
  }
  return out;
}

function renderMarkdown(content) {
  return content
    .split(/\n{2,}/)
    .filter((block) => block.trim() !== '')
    .map((block) => `<p class="nd-search-md-p">${renderInline(block.trim())}</p>`)
    .join('');
}

// ---- One hit button ---------------------------------------------------------

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
  return html(
    // Active via aria-selected; .nd-search-item[aria-selected="true"] carries the look.
    `<button type="button" aria-selected="${active}" class="nd-search-item">`
    + `<div class="nd-search-crumbs">${crumbs}</div>`
    + line
    + hash
    + `<div class="nd-search-item-body ${variant}">${renderMarkdown(item.content)}</div>`
    + '</button>',
  );
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
  let activeId = null;
  let engine = null;           // createSearch(...) from search.js
  let enginePromise = null;
  const cache = new Map();

  function setLoading(on) {
    searchIcon.classList.toggle('nd-search-loading', on);
  }

  function renderList() {
    list.setAttribute('data-empty', String(items === null));
    viewport.classList.toggle('nd-hidden', items === null);
    viewport.textContent = '';
    if (items === null) return;
    if (items.length === 0) {
      viewport.append(html(`<div class="nd-search-empty">${TEXTS.noResults}</div>`));
      return;
    }
    for (const item of items) {
      const button = renderItem(item, item.id === activeId);
      button.addEventListener('pointermove', () => setActive(item.id));
      button.addEventListener('click', () => select(item));
      viewport.append(button);
    }
    scrollActiveIntoView();
  }

  function setActive(id) {
    if (activeId === id) return;
    activeId = id;
    if (!items) return;
    [...viewport.children].forEach((button, i) => {
      const active = items[i] && items[i].id === id;
      button.setAttribute('aria-selected', String(Boolean(active)));
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

  // The index is fetched on first open and kept afterwards.
  function loadEngine() {
    if (enginePromise) return enginePromise;
    // The build writes <base>/search-index.json and names its URL in
    // <meta name="nd-search-index">. There is no hard-wired fallback URL.
    const url = indexUrl ?? document.querySelector('meta[name="nd-search-index"]')?.content;
    if (!url) {
      console.warn('search-dialog.js: <meta name="nd-search-index"> is missing, search stays empty.');
      engine = { search: () => [] };
      enginePromise = Promise.resolve(engine);
      return enginePromise;
    }
    enginePromise = fetch(url)
      .then((res) => (res.ok ? res.json() : Promise.reject(new Error(`HTTP ${res.status}`))))
      .then((json) => import('./search.js').then((mod) => {
        engine = mod.createSearch(json);
        return engine;
      }))
      .catch(() => {
        engine = { search: () => [] };
        return engine;
      });
    return enginePromise;
  }

  async function runQuery(query) {
    if (query.length === 0) {
      items = null;
      activeId = null;
      setLoading(false);
      renderList();
      return;
    }
    if (cache.has(query)) {
      items = cache.get(query);
      activeId = items.length ? items[0].id : null;
      setLoading(false);
      renderList();
      return;
    }
    setLoading(true);
    const ready = await loadEngine();
    if (input.value !== query) return;             // a newer query is running
    const result = ready.search(query);
    cache.set(query, result);
    items = result;
    activeId = result.length ? result[0].id : null;
    setLoading(false);
    renderList();
  }

  const query = debounce((value) => { runQuery(value); }, 100);

  input.addEventListener('input', () => {
    // React mirrors the value of a controlled input into the attribute as well.
    input.setAttribute('value', input.value);
    setLoading(true);
    query(input.value);
  });

  // Keyboard: the same rules as SearchDialogList.onKey.
  popup.addEventListener('keydown', (event) => {
    if (!items || event.isComposing || event.keyCode === 229) return;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      let idx = items.findIndex((item) => item.id === activeId);
      if (idx === -1) idx = 0;
      else if (event.key === 'ArrowDown') idx += 1;
      else idx -= 1;
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
        loadEngine();
        requestAnimationFrame(() => requestAnimationFrame(() => observer.observe(viewport)));
        return;
      }
      // On close Base UI detaches the popup; --fd-animated-height and the observer
      // are recreated on the next open, so reset them here.
      observer.disconnect();
      list.style.removeProperty('--fd-animated-height');
      if (list.getAttribute('style') === '') list.removeAttribute('style');
    },
  });

  closeButton.addEventListener('click', () => dialog.close());
  return dialog;
}
