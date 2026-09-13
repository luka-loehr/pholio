// i18n.js — user interface strings for labels that JavaScript renders or swaps.
//
// Usage:
//   import { t } from './i18n.js';
//   button.setAttribute('aria-label', t('Close Sidebar(sidebar)(aria-label)'));
//
// Keys are the Fumadocs translation keys, identical to the keys in
// src/i18n/<language>.php: the English source text followed by its context
// notes. The values below are copies of those tables for the keys the modules
// use; keep both in sync.
//
// The language comes from `<html lang>`, which the generator writes from the
// `language` config: a value starting with `de` selects German, anything else
// English. No DOM attribute is added, so the static HTML stays unchanged.
//
// Unknown keys throw, like I18n::t() on the PHP side: a missing string is a
// bug, never a silent fallback.

export const STRINGS = {
  en: {
    'Close Search(search dialog)(aria-label)': 'Close Search',
    'Close Sidebar(sidebar)(aria-label)': 'Close Sidebar',
    'No results found(search dialog)': 'No results found',
    'Open Sidebar(sidebar)(aria-label)': 'Open Sidebar',
    'Search(search dialog)': 'Search',
  },
  de: {
    'Close Search(search dialog)(aria-label)': 'Suche schließen',
    'Close Sidebar(sidebar)(aria-label)': 'Seitenleiste schließen',
    'No results found(search dialog)': 'Keine Treffer',
    'Open Sidebar(sidebar)(aria-label)': 'Seitenleiste öffnen',
    'Search(search dialog)': 'Suchen',
  },
};

/** Language of the document: `de` for any `lang` starting with `de`, else `en`. */
export function language(doc = document) {
  const lang = (doc.documentElement.getAttribute('lang') || '').toLowerCase();
  return lang.startsWith('de') ? 'de' : 'en';
}

/** Translation of `key` in the document language. */
export function t(key, doc = document) {
  const table = STRINGS[language(doc)];
  if (!Object.prototype.hasOwnProperty.call(table, key)) {
    throw new Error(`i18n.js: unknown translation key: ${key}`);
  }
  return table[key];
}
