// Search engine over the index src/lib/SearchIndex.php writes (search-index.json,
// version 2; the file format is documented there). No DOM, no dependencies: it
// runs in search-worker.js, on the main thread as a fallback, and in Node for the
// verify tools.
//
// Normalisation, identical to SearchIndex.php (verify/search-parity.mjs checks it):
//   lowercase → FOLDING (ä→a, ß→ss, æ→ae …) → drop combining marks U+0300–U+036F
//   → german only: ae/oe/ue read as a/o/u, so "Passwörter", "Passwoerter" and
//   "passworter" meet → split at everything but a–z and 0–9. Hyphen and underscore
//   chains also yield their joined form: "chat-export" gives chat, export, chatexport.
//
// Matching, per query term ("slot"):
//   exact word · prefix (always for the last term while it is being typed, and for
//   terms of four or more letters) · joined neighbours ("chat export" finds
//   "chatexport") and, the other way round, a one-word term split into two words
//   ("codetabs" finds "code tabs") · infix inside compounds ("export" finds "datenexport") ·
//   inflected forms of a shorter indexed word ("exportieren" finds "export") ·
//   typos (edit distance 1, or 2 from seven letters, same first letter), tried only
//   for terms nothing else matched.
//
// Ranking of pages (results are grouped by page):
//   tier 6  the query equals the title, the frontmatter heading, the slug or
//           "<parent breadcrumb> <title>"
//   tier 5  the title starts with or contains the query as a phrase
//   tier 4  every term is in the title or the path (breadcrumbs, URL segments)
//   tier 3  one heading contains every term
//   tier 2  every term occurs on the page (sections holding all of them score higher)
//   tier 1  some terms occur
//   Inside a tier: the sum over terms of the best field score, where title ≫ path,
//   heading ≫ text, weighted by idf and by how well the word matched, plus bonuses
//   when one section holds every term and when a heading reads exactly like the query.
//   All weights are the constants below.
//
// Result rows: the page, then up to three of its sections, best matching first,
// shown by their heading. Every row carries plain `content` and `marks`
// ([start, end] ranges of matched words).

export const INDEX_VERSION = 2;

/** Normalisation profiles, matching SearchIndex::TOKENIZERS. */
export const TOKENIZERS = Object.freeze(['english', 'german']);

// Folding applied after lowercasing: the first character of each entry becomes the
// rest. SearchIndex::FOLDING carries the identical string.
export const FOLDING =
  'àa áa âa ãa äa åa æae çc èe ée êe ëe ìi íi îi ïi ðd ñn òo óo ôo õo öo øo ùu úu ûu üu ýy þth ÿy ßss '
  + 'āa ăa ąa ćc ĉc ċc čc ďd đd ēe ĕe ėe ęe ěe ĝg ğg ġg ģg ĥh ħh ĩi īi ĭi įi ıi ĳij ĵj ķk ĸk ĺl ļl ľl ŀl łl '
  + 'ńn ņn ňn ŉn ŋn ōo ŏo őo œoe ŕr ŗr řr śs ŝs şs šs ţt ťt ŧt ũu ūu ŭu ůu űu ųu ŵw ŷy źz żz žz ſs';

export const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

export const FIELD_TITLE = 1;
export const FIELD_PATH = 2;
export const FIELD_HEADING = 4;

export const MAX_PAGES = 10;
export const MAX_CHILDREN = 3;

const MAX_TERMS = 8;
const PREFIX_MIN_NON_LAST = 4;   // earlier terms match as prefixes from this length
const PREFIX_LIMIT = 400;        // prefix expansions per term, most frequent first
const INFIX_MIN = 4;
const INFIX_LIMIT = 48;
const TYPO_MIN = 4;
const TYPO_LIMIT = 12;
const SPLIT_MIN = 6;             // one-word terms from this length may split into two words
const STEM_MIN = 5;              // shortest indexed word a longer term may extend
const DETAIL_LIMIT = 60;         // pages that get the tier check
const SAME_SECTION_BONUS = 40;   // every term inside one section
const EXACT_HEADING_BONUS = 40;  // a heading that reads exactly like the query

// Field scores: title ≫ path ≈ heading ≫ text (by number of text blocks, saturating).
const WEIGHT_TITLE = 12;
const WEIGHT_PATH = 5;
const WEIGHT_HEADING = 5;
const WEIGHT_TEXT = [0, 1, 1.5, 1.8, 2.1, 2.3, 2.5, 2.6];

// ------------------------------------------------------------------ Normalising

const FOLD_MAP = new Map(FOLDING.split(' ').map((entry) => [entry[0], entry.slice(1)]));
const FOLD_CHARS = /[\u00df-\u017f]/g;
const COMBINING = /[\u0300-\u036f]+/g;
const SEPARATORS = /[^a-z0-9]+/;
const CHAINS = /[a-z0-9]+(?:[-_][a-z0-9]+)+/g;

/** Lowercase, folded and, for `german`, with ae/oe/ue read as a/o/u. */
export function normalize(text, tokenizer = 'english') {
  let normal = String(text).toLowerCase().replace(FOLD_CHARS, (char) => FOLD_MAP.get(char) ?? char).replace(COMBINING, '');
  if (tokenizer === 'german') normal = normal.replace(/([aou])e/g, '$1');
  return normal;
}

/** Words of a text in order, with duplicates, followed by the joined forms of hyphen and underscore chains. */
export function words(text, tokenizer = 'english') {
  const normal = normalize(text, tokenizer);
  const out = normal.split(SEPARATORS).filter(Boolean);
  for (const chain of normal.match(CHAINS) ?? []) {
    const parts = chain.split(/[-_]/);
    out.push(parts.join(''));
    if (parts.length > 2) {
      for (let i = 0; i + 1 < parts.length; i++) out.push(parts[i] + parts[i + 1]);
    }
  }
  return out;
}

// ---------------------------------------------------------------------- Encoding

const DIGITS = new Int8Array(128).fill(-1);
for (let i = 0; i < ALPHABET.length; i++) DIGITS[ALPHABET.charCodeAt(i)] = i;

/** Posting string → Int32Array [slot, flags, slot, flags, …]. */
export function decodePostings(posting) {
  const out = [];
  let slot = -1;
  let value = 0;
  let shift = 0;
  let expectFlags = false;
  for (let i = 0; i < posting.length; i++) {
    const digit = DIGITS[posting.charCodeAt(i)];
    if (digit === undefined || digit < 0) throw new Error(`search.js: invalid posting character ${JSON.stringify(posting[i])}`);
    if (expectFlags) {
      out.push(slot, digit);
      expectFlags = false;
    } else if (digit >= 32) {
      value += (digit - 32) * 2 ** shift;
      shift += 5;
    } else {
      slot += value + digit * 2 ** shift + 1;
      value = 0;
      shift = 0;
      expectFlags = true;
    }
  }
  return Int32Array.from(out);
}

/** [slot, flags] pairs in ascending slot order → posting string. */
export function encodePostings(entries) {
  let out = '';
  let previous = -1;
  for (const [slot, flags] of entries) {
    let value = slot - previous - 1;
    while (value >= 32) {
      out += ALPHABET[32 + (value % 32)];
      value = Math.floor(value / 32);
    }
    out += ALPHABET[value] + ALPHABET[flags];
    previous = slot;
  }
  return out;
}

/** The front-coded "words" string → the sorted word list. */
export function decodeWords(coded) {
  const out = [];
  let previous = '';
  for (const entry of coded.split(' ')) {
    if (entry === '') continue;
    previous = previous.slice(0, Number.parseInt(entry[0], 36)) + entry.slice(1);
    out.push(previous);
  }
  return out;
}

/** Sorted unique words → the front-coded "words" string. */
export function encodeWords(list) {
  let previous = '';
  return list.map((word) => {
    let shared = 0;
    const max = Math.min(word.length, previous.length, 35);
    while (shared < max && word[shared] === previous[shared]) shared++;
    previous = word;
    return shared.toString(36) + word.slice(shared);
  }).join(' ');
}

function fieldScore(flags) {
  return (flags & FIELD_TITLE ? WEIGHT_TITLE : 0)
    + (flags & FIELD_PATH ? WEIGHT_PATH : 0)
    + (flags & FIELD_HEADING ? WEIGHT_HEADING : 0)
    + WEIGHT_TEXT[flags >> 3];
}

// -------------------------------------------------------------------- Distance

/**
 * Optimal string alignment distance, or `max + 1` once it exceeds `max`. With
 * `prefix`, the distance from `a` to the closest prefix of `b`.
 */
export function editDistance(a, b, max, prefix = false) {
  const m = a.length;
  const n = prefix ? Math.min(b.length, m + max) : b.length;
  if (!prefix && Math.abs(n - m) > max) return max + 1;
  let before = null;
  let previous = new Uint8Array(n + 1);
  for (let j = 0; j <= n; j++) previous[j] = Math.min(j, 255);
  for (let i = 1; i <= m; i++) {
    const current = new Uint8Array(n + 1);
    current[0] = Math.min(i, 255);
    let rowMin = current[0];
    for (let j = 1; j <= n; j++) {
      const cost = a.charCodeAt(i - 1) === b.charCodeAt(j - 1) ? 0 : 1;
      let value = Math.min(previous[j] + 1, current[j - 1] + 1, previous[j - 1] + cost);
      if (i > 1 && j > 1 && a.charCodeAt(i - 1) === b.charCodeAt(j - 2) && a.charCodeAt(i - 2) === b.charCodeAt(j - 1)) {
        value = Math.min(value, before[j - 2] + 1);
      }
      current[j] = value;
      if (value < rowMin) rowMin = value;
    }
    if (rowMin > max) return max + 1;
    before = previous;
    previous = current;
  }
  if (!prefix) return previous[n];
  let best = max + 1;
  for (let j = Math.max(0, m - max); j <= n; j++) best = Math.min(best, previous[j]);
  return best;
}

// ------------------------------------------------------------------------ Engine

const RUNS = /[\p{L}\p{N}]+(?:[-_][\p{L}\p{N}]+)*/gu;
const PARTS = /[\p{L}\p{N}]+/gu;

/**
 * Builds the engine from a parsed search-index.json.
 *
 * @returns {{
 *   search: (query: string, options?: {pages?: number, children?: number}) => Array<{
 *     id: string, type: 'page'|'heading', url: string, content: string,
 *     marks: Array<[number, number]>, breadcrumbs?: string[]}>,
 *   rankPages: (query: string) => Array<{url: string, title: string, tier: number, score: number}>,
 * }}
 */
export function createSearch(index) {
  if (!index || index.v !== INDEX_VERSION) {
    throw new Error(`search.js: expected a version ${INDEX_VERSION} search index, got ${JSON.stringify(index?.v ?? null)}`);
  }
  const tokenizer = index.tokenizer;
  if (!TOKENIZERS.includes(tokenizer)) {
    throw new Error(`search.js: unknown tokenizer ${JSON.stringify(tokenizer)} in the search index`);
  }

  const base = index.base;
  const vocabulary = decodeWords(index.words);
  const postingStrings = index.postings;
  if (vocabulary.length !== postingStrings.length) {
    throw new Error(`search.js: ${vocabulary.length} words but ${postingStrings.length} posting lists in the search index`);
  }

  // Slots: each page's own slot, then one per section (see SearchIndex.php).
  const pages = [];
  const slotPages = [];
  index.pages.forEach((entry, id) => {
    const [url, title, alternative, crumbIndex] = entry;
    slotPages.push(id);
    const sections = [];
    for (let k = 4; k + 1 < entry.length; k += 2) {
      sections.push({ anchor: entry[k], heading: entry[k + 1], slot: slotPages.length });
      slotPages.push(id);
    }
    pages.push({ url, title, alternative, crumbs: crumbIndex >= 0 ? index.crumbs[crumbIndex] : null, sections, slot: slotPages.length - sections.length - 1, detail: null });
  });
  const pageCount = pages.length;
  const slotPage = Int32Array.from(slotPages);

  const decoded = new Array(vocabulary.length);
  const postingsOf = (word) => (decoded[word] ??= decodePostings(postingStrings[word]));

  // ---- Vocabulary lookups ----

  function lowerBound(term) {
    let lo = 0;
    let hi = vocabulary.length;
    while (lo < hi) {
      const mid = (lo + hi) >> 1;
      if (vocabulary[mid] < term) lo = mid + 1;
      else hi = mid;
    }
    return lo;
  }

  function exactWord(term) {
    const at = lowerBound(term);
    return at < vocabulary.length && vocabulary[at] === term ? at : -1;
  }

  // '{' sorts after every word character.
  function prefixWords(term, limit) {
    const from = lowerBound(term);
    const to = lowerBound(`${term}{`);
    const out = [];
    for (let word = from; word < to; word++) out.push(word);
    if (out.length > limit) {
      out.sort((a, b) => postingStrings[b].length - postingStrings[a].length || a - b);
      out.length = limit;
    }
    return out;
  }

  let joinedVocabulary = null;
  let wordStarts = null;

  // Words containing the term anywhere but at their start (those are prefixes).
  function infixWords(term, limit) {
    if (joinedVocabulary === null) {
      joinedVocabulary = `\n${vocabulary.join('\n')}\n`;
      wordStarts = new Int32Array(vocabulary.length);
      let position = 1;
      for (let i = 0; i < vocabulary.length; i++) {
        wordStarts[i] = position;
        position += vocabulary[i].length + 1;
      }
    }
    const out = [];
    let at = joinedVocabulary.indexOf(term, 1);
    while (at >= 0 && out.length < limit) {
      let lo = 0;
      let hi = wordStarts.length - 1;
      while (lo < hi) {
        const mid = (lo + hi + 1) >> 1;
        if (wordStarts[mid] <= at) lo = mid;
        else hi = mid - 1;
      }
      if (wordStarts[lo] !== at) {
        out.push(lo);
        at = wordStarts[lo] + vocabulary[lo].length;
      }
      at = joinedVocabulary.indexOf(term, at + 1);
    }
    return out;
  }

  function typoWords(term, prefix, limit) {
    const max = term.length >= 7 ? 2 : 1;
    const from = lowerBound(term[0]);
    const to = lowerBound(`${term[0]}{`);
    const found = [];
    for (let word = from; word < to; word++) {
      const candidate = vocabulary[word];
      if (prefix ? candidate.length < term.length - max : Math.abs(candidate.length - term.length) > max) continue;
      const distance = editDistance(term, candidate, max, prefix);
      if (distance <= max) found.push([word, distance]);
    }
    found.sort((a, b) => a[1] - b[1] || postingStrings[b[0]].length - postingStrings[a[0]].length || a[0] - b[0]);
    return found.slice(0, limit);
  }

  // ---- Query ----

  function parse(query) {
    const normal = normalize(typeof query === 'string' ? query : '', tokenizer);
    const terms = [...new Set(normal.split(SEPARATORS).filter(Boolean))].slice(0, MAX_TERMS);
    // A trailing separator means the last word is complete.
    const typing = terms.length > 0 && /[a-z0-9]$/.test(normal);
    return { terms, typing };
  }

  function hasPrefix(term) {
    const at = lowerBound(term);
    return at < vocabulary.length && vocabulary[at].startsWith(term);
  }

  // A term typed as one word that the pages write as two ("codetabs" → code, tabs):
  // split where the left part is a word and the right part a word (or, while typing
  // the last term, a word prefix). The longest left part wins.
  function splitCompounds(terms, typing) {
    const out = [];
    terms.forEach((term, i) => {
      const last = typing && i === terms.length - 1;
      if (term.length >= SPLIT_MIN && !hasPrefix(term) && infixWords(term, 1).length === 0) {
        for (let cut = term.length - 3; cut >= 3; cut--) {
          const right = term.slice(cut);
          if (exactWord(term.slice(0, cut)) >= 0 && (last ? hasPrefix(right) : exactWord(right) >= 0)) {
            out.push(term.slice(0, cut), right);
            return;
          }
        }
      }
      out.push(term);
    });
    return out.slice(0, MAX_TERMS);
  }

  function expand(terms, typing) {
    const last = terms.length - 1;
    const slots = terms.map((term, i) => ({
      term,
      prefix: (typing && i === last) || term.length >= PREFIX_MIN_NON_LAST,
      words: new Set(),
      candidates: [],
    }));
    const add = (slot, word, quality) => {
      slot.candidates.push(word, quality);
      slot.words.add(vocabulary[word]);
    };

    slots.forEach((slot, i) => {
      const { term } = slot;
      const exact = exactWord(term);
      if (exact >= 0) add(slot, exact, 1);
      if (slot.prefix) {
        const floor = typing && i === last ? 0.6 : 0.45;
        for (const word of prefixWords(term, PREFIX_LIMIT)) {
          if (word !== exact) add(slot, word, floor + (0.35 * term.length) / vocabulary[word].length);
        }
      }
      if (term.length >= INFIX_MIN) {
        for (const word of infixWords(term, INFIX_LIMIT)) add(slot, word, 0.4);
      }
      // Inflected or extended forms of an indexed word ("exportieren" finds "export").
      for (let cut = term.length - 1; cut >= Math.max(STEM_MIN, Math.ceil(term.length / 2)); cut--) {
        const word = exactWord(term.slice(0, cut));
        if (word >= 0) add(slot, word, 0.35 + (0.3 * cut) / term.length);
      }
    });

    // Neighbours written as one word satisfy both terms.
    for (let i = 0; i < last; i++) {
      const joined = terms[i] + terms[i + 1];
      const found = typing && i + 1 === last ? prefixWords(joined, PREFIX_LIMIT) : [exactWord(joined)].filter((word) => word >= 0);
      for (const word of found) {
        const quality = vocabulary[word] === joined ? 1 : 0.6 + (0.35 * joined.length) / vocabulary[word].length;
        add(slots[i], word, quality);
        add(slots[i + 1], word, quality);
      }
    }

    slots.forEach((slot, i) => {
      if (slot.candidates.length > 0 || slot.term.length < TYPO_MIN) return;
      for (const [word, distance] of typoWords(slot.term, typing && i === last, TYPO_LIMIT)) {
        add(slot, word, distance === 1 ? 0.5 : 0.3);
      }
    });

    return slots;
  }

  function matchesSlot(slot, word) {
    return slot.words.has(word) || (slot.prefix && word.startsWith(slot.term));
  }

  // ---- Per-page detail for the tier check, computed once per page on demand ----

  const plainWords = (text) => normalize(text, tokenizer).split(SEPARATORS).filter(Boolean);

  function detailOf(page) {
    if (page.detail) return page.detail;
    const titleWords = plainWords(page.title);
    const slug = page.url.slice(base.length).split('/').filter(Boolean).at(-1) ?? '';
    const parent = page.crumbs && page.crumbs.length > 1 ? page.crumbs.at(-1) : '';
    page.detail = {
      titleWords,
      headings: page.sections.map((section) => (section.heading === null ? '' : plainWords(section.heading).join(''))),
      joined: [
        titleWords.join(''),
        page.alternative === null ? '' : plainWords(page.alternative).join(''),
        plainWords(slug).join(''),
        parent ? plainWords(parent).join('') + titleWords.join('') : '',
      ].filter(Boolean),
    };
    return page.detail;
  }

  function isPhrase(sequence, terms, typing) {
    const last = terms.length - 1;
    for (let start = 0; start + terms.length <= sequence.length; start++) {
      let ok = true;
      for (let k = 0; k <= last && ok; k++) {
        const word = sequence[start + k];
        ok = typing && k === last ? word.startsWith(terms[k]) : word === terms[k];
      }
      if (ok) return true;
    }
    return false;
  }

  // Terms found in a section: 1 in its heading, 2 in its text.
  function sectionTerms(sectionHits, section, width, mask) {
    let count = 0;
    for (let i = 0; i < width; i++) if (sectionHits[section.slot * width + i] & mask) count++;
    return count;
  }

  function rank(query) {
    const parsed = parse(query);
    if (parsed.terms.length === 0) return null;
    const { typing } = parsed;
    const terms = splitCompounds(parsed.terms, typing);
    const slots = expand(terms, typing);
    const width = slots.length;
    const scores = new Float64Array(pageCount * width);
    const fields = new Uint8Array(pageCount * width);
    const sectionHits = new Uint8Array(slotPage.length * width);
    const seen = new Uint8Array(pageCount);
    const touched = [];

    slots.forEach((slot, i) => {
      const { candidates } = slot;
      for (let c = 0; c < candidates.length; c += 2) {
        const list = postingsOf(candidates[c]);
        let pagesWithWord = 0;
        for (let e = 0, previous = -1; e < list.length; e += 2) {
          if (slotPage[list[e]] !== previous) {
            previous = slotPage[list[e]];
            pagesWithWord++;
          }
        }
        const weight = candidates[c + 1] * Math.log(1 + pageCount / pagesWithWord);

        // Entries of one page are adjacent; their flags add up to one page score.
        let page = -1;
        let flags = 0;
        let texts = 0;
        const flush = () => {
          if (page < 0) return;
          const at = page * width + i;
          const score = weight * fieldScore(flags | (Math.min(texts, 7) << 3));
          if (score > scores[at]) scores[at] = score;
          fields[at] |= flags;
          if (!seen[page]) {
            seen[page] = 1;
            touched.push(page);
          }
        };
        for (let e = 0; e < list.length; e += 2) {
          const id = list[e];
          const value = list[e + 1];
          if (slotPage[id] !== page) {
            flush();
            page = slotPage[id];
            flags = 0;
            texts = 0;
          }
          flags |= value & 7;
          texts += value >> 3;
          if (id !== pages[page].slot) sectionHits[id * width + i] |= (value & FIELD_HEADING ? 1 : 0) | (value >> 3 ? 2 : 0);
        }
        flush();
      }
    });

    const candidates = touched.map((page) => {
      let matched = 0;
      let inTitleOrPath = 0;
      let score = 0;
      for (let i = 0; i < width; i++) {
        const value = scores[page * width + i];
        if (value > 0) {
          matched++;
          score += value;
          if (fields[page * width + i] & (FIELD_TITLE | FIELD_PATH)) inTitleOrPath++;
        }
      }
      return { page, matched, inTitleOrPath, score, tier: 0 };
    });
    candidates.sort((a, b) => b.matched - a.matched || b.score - a.score || a.page - b.page);
    const ranked = candidates.slice(0, DETAIL_LIMIT);

    const joinedQuery = terms.join('');
    for (const candidate of ranked) {
      const page = pages[candidate.page];
      const detail = detailOf(page);
      const all = candidate.matched === width;
      if (width > 1 && all && page.sections.some((section) => sectionTerms(sectionHits, section, width, 3) === width)) {
        candidate.score += SAME_SECTION_BONUS;
      }
      if (detail.headings.includes(joinedQuery)) candidate.score += EXACT_HEADING_BONUS;
      candidate.score = Math.min(candidate.score, 999);
      if (detail.joined.includes(joinedQuery)) candidate.tier = 6;
      else if (typing && joinedQuery.length >= 2 && detail.joined.some((joined) => joined.startsWith(joinedQuery))) candidate.tier = 5;
      else if (isPhrase(detail.titleWords, terms, typing)) candidate.tier = 5;
      else if (candidate.inTitleOrPath === width) candidate.tier = 4;
      else if (all && page.sections.some((section) => sectionTerms(sectionHits, section, width, 1) === width)) candidate.tier = 3;
      else candidate.tier = all ? 2 : 1;
    }
    ranked.sort((a, b) => b.tier - a.tier || b.score - a.score || a.page - b.page);
    return { slots, ranked, sectionHits, width };
  }

  // ---- Rows ----

  function marksOf(text, slots) {
    const marks = [];
    const hit = (piece) => words(piece, tokenizer).some((word) => slots.some((slot) => matchesSlot(slot, word)));
    for (const run of text.matchAll(RUNS)) {
      const chain = run[0];
      // A chain like "Chat-Export" is marked whole when its joined form matched.
      if (/[-_]/.test(chain) && hit(chain.replace(/[-_]/g, ''))) {
        marks.push([run.index, run.index + chain.length]);
        continue;
      }
      for (const part of chain.matchAll(PARTS)) {
        if (hit(part[0])) marks.push([run.index + part.index, run.index + part.index + part[0].length]);
      }
    }
    return marks;
  }

  function childRows(page, result, limit) {
    const { slots, sectionHits, width } = result;
    const rows = [];
    page.sections.forEach((section, order) => {
      // Text before the first heading belongs to the page row.
      if (section.anchor === null || section.heading === null) return;
      const inSection = sectionTerms(sectionHits, section, width, 3);
      if (inSection === 0) return;
      rows.push({ section, order, inSection, inHeading: sectionTerms(sectionHits, section, width, 1) });
    });
    if (rows.length === 0) return [];
    rows.sort((a, b) => b.inSection - a.inSection || b.inHeading - a.inHeading || a.order - b.order);
    const best = rows[0].inSection;
    return rows
      .filter((row) => row.inSection === best || row.inHeading > 0)
      .slice(0, limit)
      .map(({ section }) => {
        const url = `${page.url}#${section.anchor}`;
        return { id: url, type: 'heading', url, content: section.heading, marks: marksOf(section.heading, slots) };
      });
  }

  /** Result rows for the dialog: each page followed by its best matching sections. */
  function search(query, { pages: maxPages = MAX_PAGES, children = MAX_CHILDREN } = {}) {
    const result = rank(query);
    if (result === null) return [];
    const items = [];
    for (const { page: id } of result.ranked.slice(0, maxPages)) {
      const page = pages[id];
      const item = { id: page.url, type: 'page', url: page.url, content: page.title, marks: marksOf(page.title, result.slots) };
      if (page.crumbs) item.breadcrumbs = page.crumbs;
      items.push(item, ...childRows(page, result, children));
    }
    return items;
  }

  /** Ranked pages with tier and score, for tests and tools. */
  function rankPages(query) {
    const result = rank(query);
    if (result === null) return [];
    return result.ranked.map(({ page, tier, score }) => ({ url: pages[page].url, title: pages[page].title, tier, score }));
  }

  return { search, rankPages };
}

export default createSearch;
