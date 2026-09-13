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
// Query terms: stopwords ("wie", "ich", "the", …) are dropped unless nothing else is
// left. Each remaining term matches
//   exact word · prefix (always for the last term while it is being typed, and for
//   terms of four or more letters) · joined neighbours ("chat export" finds
//   "chatexport") and a one-word term split into two words ("codetabs" finds
//   "code tabs") · infix inside compounds ("export" finds "datenexport") · shorter
//   indexed words ("exportieren" finds "export") and words sharing all but the last
//   three letters ("herunterladen" finds "herunterladbar") · typos (edit distance 1,
//   or 2 from seven letters, same first letter), tried for every term without an
//   exact, prefix or joined match.
//
// Ranking of pages (results are grouped by page):
//   tier 3  the query equals the title, the frontmatter heading, the slug or
//           "<parent breadcrumb> <title>"
//   tier 2  the title starts with or contains the query as a phrase
//   tier 1  every term is in the title or the keywords (the path may add terms)
//   tier 0  everything else
//   Inside a tier, the score: per term the fields it occurs in (title ≫ keywords ≫
//   heading ≈ description ≫ text with length normalisation ≫ path), weighted by the
//   term's rarity (BM25 idf) and how well the word matched; summed over terms and
//   multiplied by the square of the share of the query the page covers. Pages where
//   one section, one heading or the title and description hold most of the query,
//   and pages with a heading that reads exactly like the query, are boosted.
//   All weights are in WEIGHTS below.
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

// Page slot flags.
export const FIELD_TITLE = 1;
export const FIELD_PATH = 2;
export const FIELD_DESCRIPTION = 4;
export const FIELD_KEYWORDS = 8;
// Section slot flag; the text count sits in bits 3–5.
export const FIELD_HEADING = 4;

export const MAX_PAGES = 8;
export const MAX_CHILDREN = 3;

// Query words that carry no meaning of their own. German queries mix in English.
const STOPWORDS_ENGLISH = 'a an and are as at be by can do does for from how i in is it my me of on or the this that to what when where why with you your';
const STOPWORDS_GERMAN = 'aber alle als also am an auch auf aus bei bin bis bitte da dann das dass dem den der des dich die dir doch du ein eine einem einen einer eines er es fur gibt hat habe haben hier ich ihr im in ist ja jetzt kann kannst konnen man mein meine meinem meinen meiner meines mich mir mit muss nach nicht noch nochmal nur ob oder sich sie sind so soll um und uns von vor war was welche welcher welches wenn werden wer wie wieso wird wo zu zum zur ins beim vom uber dies diese dieser dieses jede jeder jedes kein keine etwas sehr immer wieder';
const STOPWORDS = {
  english: STOPWORDS_ENGLISH,
  german: `${STOPWORDS_GERMAN} ${STOPWORDS_ENGLISH}`,
};

const MAX_TERMS = 8;
const PREFIX_MIN_NON_LAST = 4;   // earlier terms match as prefixes from this length
const PREFIX_LIMIT = 400;        // prefix expansions per term, most frequent first
const INFIX_MIN = 4;
const INFIX_LIMIT = 48;
const TYPO_MIN = 4;
const TYPO_LIMIT = 12;
const SPLIT_MIN = 6;             // one-word terms from this length may split into two words
const STEM_MIN = 5;              // shortest indexed word a longer term may extend
const SHARED_STEM_MIN = 8;       // terms from this length also match words sharing all but their last three letters
const SHARED_STEM_LIMIT = 48;
const TAIL_TERM_MIN = 8;         // terms from this length also match an indexed word they end with ("systemprompt" → "prompt")
const TAIL_MIN = 4;
const ENDINGS = ['ern', 'en', 'er', 'es', 'e', 'n', 's']; // stripped before a short prefix match ("logs" → "logging")
const ENDING_STEM_MIN = 3;
const ENDING_GROWTH = 4;         // letters a word may have beyond the stripped stem
const ENDING_LIMIT = 48;
const DETAIL_LIMIT = 60;         // pages that get the tier check and the boosts

const QUALITY_TYPO = [1, 0.5, 0.3];

/**
 * Ranking weights. `createSearch(index, { weights })` overrides single values, for
 * tuning tools; the dialog always uses these.
 */
export const WEIGHTS = Object.freeze({
  // Match quality of a word for a term (1 = exact).
  qualityInfix: 0.36,
  qualitySharedStem: 0.45,
  qualityEnding: 0.6,
  // Field weights per term. The title needs little here: titleBoost and the tiers already
  // put title matches first, and a high value let generic title words outrank specific text.
  title: 2,
  keywords: 8,
  heading: 2.5,
  description: 5.6,
  path: 1.5,
  text: 1.5,                     // text saturates towards text × (textK1 + 1)
  textK1: 1.2,                   // BM25 saturation of the text block count
  textB: 0.75,                   // BM25 length normalisation by the page's text blocks
  // Page score multipliers.
  coveragePower: 0.5,            // score × coverage^power, coverage = idf share of the query found
  sectionBoost: 1.8,             // × (1 + boost × share²) for the section holding most of the query
  headingBoost: 0.5,             // the same for one heading
  summaryBoost: 1,               // the same for title, keywords and description together
  titleBoost: 1,                 // × (1 + boost × share) for the query share in the title and keywords
  pathBoost: 0.5,                // × (1 + boost × share) for the query share in the breadcrumbs and URL, with a title match
  exactHeadingBoost: 0.8,        // × (1 + boost) for a heading that reads exactly like the query
});

// ------------------------------------------------------------------ Normalising

const FOLD_MAP = new Map(FOLDING.split(' ').map((entry) => [entry[0], entry.slice(1)]));
const FOLD_CHARS = /[ß-ſ]/g;
const COMBINING = /[̀-ͯ]+/g;
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
 *   rankPages: (query: string) => Array<{url: string, title: string, tier: number, score: number,
 *     terms: Array<{term: string, score: number, fields: number}>}>,
 *   explain: (query: string) => Array<{term: string, weight: number, words: Array<[string, number]>}>,
 * }}
 */
export function createSearch(index, { weights = {} } = {}) {
  const W = { ...WEIGHTS, ...weights };
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
  const stopwords = new Set(STOPWORDS[tokenizer].split(' ').map((word) => normalize(word, tokenizer)));

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
  const lengths = Float64Array.from(index.lengths ?? pages.map(() => 1));
  const averageLength = lengths.reduce((sum, length) => sum + length, 0) / Math.max(1, pageCount) || 1;

  const decoded = new Array(vocabulary.length);
  const postingsOf = (word) => (decoded[word] ??= decodePostings(postingStrings[word]));

  // BM25 idf over pages, per word.
  const idfs = new Float64Array(vocabulary.length).fill(-1);
  function idfOf(word) {
    if (idfs[word] < 0) {
      const list = postingsOf(word);
      let pagesWithWord = 0;
      for (let e = 0, previous = -1; e < list.length; e += 2) {
        if (slotPage[list[e]] !== previous) {
          previous = slotPage[list[e]];
          pagesWithWord++;
        }
      }
      idfs[word] = Math.log(1 + (pageCount - pagesWithWord + 0.5) / (pagesWithWord + 0.5));
    }
    return idfs[word];
  }

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
      if (distance > 0 && distance <= max) found.push([word, distance]);
    }
    found.sort((a, b) => a[1] - b[1] || postingStrings[b[0]].length - postingStrings[a[0]].length || a[0] - b[0]);
    return found.slice(0, limit);
  }

  // ---- Query ----

  function parse(query) {
    const normal = normalize(typeof query === 'string' ? query : '', tokenizer);
    const all = [...new Set(normal.split(SEPARATORS).filter(Boolean))];
    const content = all.filter((term) => !stopwords.has(term));
    // A trailing separator means the last word is complete; a dropped stopword there too.
    const typing = all.length > 0 && /[a-z0-9]$/.test(normal) && (content.length === 0 || content.at(-1) === all.at(-1));
    return { all: all.slice(0, MAX_TERMS), terms: (content.length > 0 ? content : all).slice(0, MAX_TERMS), typing };
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
      strong: false,              // an exact, prefix or joined match exists
      qualities: new Map(),       // word id → best quality
      words: new Set(),
      candidates: [],
    }));
    const add = (slot, word, quality) => {
      if (quality > (slot.qualities.get(word) ?? 0)) slot.qualities.set(word, quality);
    };

    slots.forEach((slot, i) => {
      const { term } = slot;
      const exact = exactWord(term);
      if (exact >= 0) {
        add(slot, exact, 1);
        slot.strong = true;
      }
      if (slot.prefix) {
        const floor = typing && i === last ? 0.6 : 0.45;
        for (const word of prefixWords(term, PREFIX_LIMIT)) {
          if (word === exact) continue;
          add(slot, word, floor + (0.35 * term.length) / vocabulary[word].length);
          slot.strong = true;
        }
      }
      if (term.length >= INFIX_MIN) {
        for (const word of infixWords(term, INFIX_LIMIT)) add(slot, word, W.qualityInfix);
      }
      // Inflected or extended forms of an indexed word ("exportieren" finds "export").
      for (let cut = term.length - 1; cut >= Math.max(STEM_MIN, Math.ceil(term.length / 2)); cut--) {
        const word = exactWord(term.slice(0, cut));
        if (word >= 0) add(slot, word, 0.35 + (0.3 * cut) / term.length);
      }
      // Other endings of the same stem ("herunterladen" finds "herunterladbare").
      if (term.length >= SHARED_STEM_MIN) {
        for (const word of prefixWords(term.slice(0, Math.max(STEM_MIN, term.length - 3)), SHARED_STEM_LIMIT)) {
          add(slot, word, W.qualitySharedStem);
        }
      }
      // The last part of a compound ("lehrerfazit" finds "fazit").
      if (term.length >= TAIL_TERM_MIN) {
        for (let cut = 3; term.length - cut >= TAIL_MIN; cut++) {
          const word = exactWord(term.slice(cut));
          if (word >= 0) {
            add(slot, word, 0.3 + (0.3 * (term.length - cut)) / term.length);
            break;
          }
        }
      }
      // Plural and inflection endings: the stem as a short prefix ("logs" finds "logging").
      for (const ending of ENDINGS) {
        const stem = term.slice(0, term.length - ending.length);
        if (!term.endsWith(ending) || stem.length < ENDING_STEM_MIN) continue;
        for (const word of prefixWords(stem, ENDING_LIMIT)) {
          if (vocabulary[word].length <= stem.length + ENDING_GROWTH) add(slot, word, W.qualityEnding);
        }
        break;
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
        slots[i].strong = true;
        slots[i + 1].strong = true;
      }
    }

    slots.forEach((slot, i) => {
      if (slot.strong || slot.term.length < TYPO_MIN) return;
      for (const [word, distance] of typoWords(slot.term, typing && i === last, TYPO_LIMIT)) {
        add(slot, word, QUALITY_TYPO[distance]);
      }
    });

    for (const slot of slots) {
      for (const [word, quality] of slot.qualities) {
        slot.candidates.push(word, quality);
        slot.words.add(vocabulary[word]);
      }
    }
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

  // Share of the query weight a section holds, with `mask` 1 heading, 3 heading or text.
  function sectionShare(sectionHits, section, slotWeights, totalWeight, mask) {
    let share = 0;
    for (let i = 0; i < slotWeights.length; i++) if (sectionHits[section.slot * slotWeights.length + i] & mask) share += slotWeights[i];
    return share / totalWeight;
  }

  function fieldScore(pageFlags, heading, texts, page) {
    let score = (pageFlags & FIELD_TITLE ? W.title : 0)
      + (pageFlags & FIELD_KEYWORDS ? W.keywords : 0)
      + (pageFlags & FIELD_DESCRIPTION ? W.description : 0)
      + (pageFlags & FIELD_PATH ? W.path : 0)
      + (heading ? W.heading : 0);
    if (texts > 0) {
      const norm = 1 - W.textB + (W.textB * lengths[page]) / averageLength;
      score += (W.text * texts * (W.textK1 + 1)) / (texts + W.textK1 * norm);
    }
    return score;
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
    // A term's weight: quality times the rarity of its best matching word.
    const slotWeights = new Float64Array(width);
    const seen = new Uint8Array(pageCount);
    const touched = [];

    slots.forEach((slot, i) => {
      const { candidates } = slot;
      // Every matched word of a term counts with the term's rarity, the rarity of its best
      // match: a rare loose variant ("datenschutzerklarung" for "datenschutz") must not outweigh it.
      let bestQuality = 0;
      let slotIdf = 0;
      for (let c = 0; c < candidates.length; c += 2) {
        const quality = candidates[c + 1];
        if (quality < bestQuality) continue;
        const idf = idfOf(candidates[c]);
        if (quality > bestQuality || idf > slotIdf) {
          bestQuality = quality;
          slotIdf = idf;
        }
      }
      slotWeights[i] = bestQuality * slotIdf;
      for (let c = 0; c < candidates.length; c += 2) {
        const list = postingsOf(candidates[c]);
        const weight = candidates[c + 1] * slotIdf;

        // Entries of one page are adjacent: its own slot first, then its sections.
        let page = -1;
        let pageFlags = 0;
        let heading = false;
        let texts = 0;
        const flush = () => {
          if (page < 0) return;
          const at = page * width + i;
          const score = weight * fieldScore(pageFlags, heading, texts, page);
          if (score > scores[at]) scores[at] = score;
          fields[at] |= pageFlags;
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
            pageFlags = 0;
            heading = false;
            texts = 0;
          }
          if (id === pages[page].slot) {
            pageFlags = value;
            continue;
          }
          if (value & FIELD_HEADING) heading = true;
          texts += value >> 3;
          sectionHits[id * width + i] |= (value & FIELD_HEADING ? 1 : 0) | (value >> 3 ? 2 : 0);
        }
        flush();
      }
    });

    // Terms no page contains cannot tell pages apart; they do not count against coverage.
    const totalWeight = slotWeights.reduce((sum, weight) => sum + weight, 0) || 1;
    const candidates = touched.map((page) => {
      let score = 0;
      let covered = 0;
      let matched = 0;
      for (let i = 0; i < width; i++) {
        if (scores[page * width + i] > 0) {
          score += scores[page * width + i];
          covered += slotWeights[i];
          matched++;
        }
      }
      return { page, matched, score: score * (covered / totalWeight) ** W.coveragePower, tier: 0 };
    });
    candidates.sort((a, b) => b.score - a.score || a.page - b.page);
    const ranked = candidates.slice(0, DETAIL_LIMIT);

    const joinedTerms = terms.join('');
    const joinedAll = parsed.all.join('');
    const activeSlots = slotWeights.filter((weight) => weight > 0).length;
    for (const candidate of ranked) {
      const page = pages[candidate.page];
      const detail = detailOf(page);
      if (width > 1) {
        let section = 0;
        let heading = 0;
        for (const s of page.sections) {
          section = Math.max(section, sectionShare(sectionHits, s, slotWeights, totalWeight, 3));
          heading = Math.max(heading, sectionShare(sectionHits, s, slotWeights, totalWeight, 1));
        }
        let summary = 0;
        for (let i = 0; i < width; i++) {
          if (fields[candidate.page * width + i] & (FIELD_TITLE | FIELD_KEYWORDS | FIELD_DESCRIPTION)) summary += slotWeights[i];
        }
        summary /= totalWeight;
        candidate.score *= (1 + W.sectionBoost * section ** 2) * (1 + W.headingBoost * heading ** 2) * (1 + W.summaryBoost * summary ** 2);
      }
      let titleShare = 0;
      let pathShare = 0;
      for (let i = 0; i < width; i++) {
        const flags = fields[candidate.page * width + i];
        if (flags & (FIELD_TITLE | FIELD_KEYWORDS)) titleShare += slotWeights[i];
        else if (flags & FIELD_PATH) pathShare += slotWeights[i];
      }
      candidate.score *= 1 + W.titleBoost * (titleShare / totalWeight);
      // The topic page of a section: one term names the section, another the page ("datenschutz … projekt").
      if (titleShare > 0) candidate.score *= 1 + W.pathBoost * (pathShare / totalWeight);
      if (detail.headings.includes(joinedTerms) || detail.headings.includes(joinedAll)) candidate.score *= 1 + W.exactHeadingBoost;

      let inTitle = 0;
      let inTitleOrPath = 0;
      for (let i = 0; i < width; i++) {
        const flags = fields[candidate.page * width + i];
        if (flags & (FIELD_TITLE | FIELD_KEYWORDS)) inTitle++;
        if (flags & (FIELD_TITLE | FIELD_KEYWORDS | FIELD_PATH)) inTitleOrPath++;
      }
      if (detail.joined.includes(joinedTerms) || detail.joined.includes(joinedAll)) candidate.tier = 3;
      else if (typing && width <= 3 && joinedAll.length >= 2 && detail.joined.some((joined) => joined.startsWith(joinedAll))) candidate.tier = 2;
      else if (isPhrase(detail.titleWords, parsed.all, typing) || isPhrase(detail.titleWords, terms, typing)) candidate.tier = 2;
      else if (candidate.matched === width && activeSlots === width && inTitle > 0 && inTitleOrPath === width) candidate.tier = 1;
    }
    ranked.sort((a, b) => b.tier - a.tier || b.score - a.score || a.page - b.page);
    return { slots, ranked, sectionHits, width, scores, fields, slotWeights };
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

  /** Ranked pages with tier, score and each term's score and page fields, for tests and tools. */
  function rankPages(query) {
    const result = rank(query);
    if (result === null) return [];
    const { slots, width, scores, fields } = result;
    return result.ranked.map(({ page, tier, score }) => ({
      url: pages[page].url,
      title: pages[page].title,
      tier,
      score,
      terms: slots.map((slot, i) => ({ term: slot.term, score: scores[page * width + i], fields: fields[page * width + i] })),
    }));
  }

  /** The query terms after stopwords and compound splitting, each with its weight and best matched words. */
  function explain(query) {
    const result = rank(query);
    if (result === null) return [];
    return result.slots.map((slot, i) => {
      const matched = [];
      for (let c = 0; c < slot.candidates.length; c += 2) matched.push([vocabulary[slot.candidates[c]], slot.candidates[c + 1]]);
      matched.sort((a, b) => b[1] - a[1]);
      return { term: slot.term, weight: result.slotWeights[i], words: matched.slice(0, 12) };
    });
  }

  return { search, rankPages, explain };
}

export default createSearch;
