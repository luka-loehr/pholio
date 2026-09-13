// Search like Fumadocs: a port of zbsearch 4.0.0 (Apache-2.0) and the search
// functions of fumadocs-core 16.15.9 (MIT) to plain JavaScript.
//
// Ported:
//   zbsearch  – tokenizer (languages `english` and `german`), replaceDiacritics,
//               RadixNode (insert/find/findAllWords), postings, BM25,
//               index.search, calculateResultScores, prefixExpansionDemotion,
//               getGroups, sortTokenScorePredicate, the insertion order of
//               insertMultipleAsync including the running avgFieldLength.
//   fumadocs  – buildDocuments (done ahead of time by the PHP index),
//               searchAdvanced, createContentHighlighter/highlightMarkdown.
//   remark    – the part of mdast-util-to-markdown that highlightMarkdown needs
//               (containerPhrasing, emphasis/strong, inlineCode).
//
// No DOM, no dependencies: runs in the browser and in Node (parity test).

// ---------------------------------------------------------------- Tokenizing

// From zbsearch/components/tokenizer/languages.js, copied verbatim. The splitter
// of a language is `[^FOLDABLE_LETTERS + alphabet]+` with flags `gim`.
// `verify/search-parity.mjs --selftest` asserts both against the package.
// Neither profile stems or removes stopwords (the zbsearch defaults).
const FOLDABLE_LETTERS = '\\u00C0-\\u00D6\\u00D8-\\u00F6\\u00F8-\\u017F';

const SPLITTER_ALPHABETS = {
  english: "A-Za-zàèéìòóù0-9_'-",
  // German letters are part of zbsearch's `german` alphabet; this is data, not prose.
  german: 'a-z0-9A-ZäöüÄÖÜß',
};

/** Splitter per profile, built exactly like zbsearch's `SPLITTERS`. */
export const SPLITTERS = Object.freeze(
  Object.fromEntries(
    Object.entries(SPLITTER_ALPHABETS).map(([language, alphabet]) => [
      language,
      new RegExp(`[^${FOLDABLE_LETTERS}${alphabet}]+`, 'gim'),
    ]),
  ),
);

/** The profiles this port supports, matching `SearchIndex::TOKENIZERS` in PHP. */
export const TOKENIZERS = Object.freeze(Object.keys(SPLITTER_ALPHABETS));

// CHARCODE_REPLACE_MAPPING from zbsearch/components/tokenizer/diacritics.js,
// code points 192–383; `.` means: stays as it is. ß (223) becomes s.
const DIACRITICS =
  'A|A|A|A|A|A|A|C|E|E|E|E|I|I|I|I|E|N|O|O|O|O|O|.|O|U|U|U|U|Y|P|s|a|a|a|a|a|a|a|c|e|e|e|e|i|i|i|i|e|n|o|o|o|o|o|.|o|u|u|u|u|y|p|y|A|a|A|a|A|a|C|c|C|c|C|c|C|c|D|d|D|d|E|e|E|e|E|e|E|e|E|e|G|g|G|g|G|g|G|g|H|h|H|h|I|i|I|i|I|i|I|i|I|i|I|i|J|j|K|k|k|L|l|L|l|L|l|L|l|L|l|N|n|N|n|N|n|n|N|n|O|o|O|o|O|o|O|o|R|r|R|r|R|r|S|s|S|s|S|s|S|s|T|t|T|t|T|t|U|u|U|u|U|u|U|u|U|u|U|u|W|w|Y|y|Y|Z|z|Z|z|Z|z|s'.split(
    '|',
  );

// EXTRA_FOLDINGS (Cyrillic/Arabic), for completeness.
const EXTRA_FOLDINGS = {
  1025: 'Е',
  1105: 'е',
  1570: 'ا',
  1571: 'ا',
  1573: 'ا',
  1609: 'ي',
  1649: 'ا',
};

function replaceChar(code) {
  if (code >= 192 && code <= 383) {
    const replacement = DIACRITICS[code - 192];
    return replacement === '.' ? code : replacement.charCodeAt(0);
  }
  const extra = EXTRA_FOLDINGS[code];
  return extra === undefined ? code : extra.charCodeAt(0);
}

function replaceDiacritics(str) {
  const len = str.length;
  for (let idx = 0; idx < len; idx++) {
    const code = str.charCodeAt(idx);
    if (code < 192) continue;
    const replaced = replaceChar(code);
    if (replaced === code) continue;
    const codes = new Array(len);
    for (let j = 0; j < idx; j++) codes[j] = str.charCodeAt(j);
    codes[idx] = replaced;
    for (let j = idx + 1; j < len; j++) codes[j] = replaceChar(str.charCodeAt(j));
    return String.fromCharCode(...codes);
  }
  return str;
}

/**
 * `tokenize` for `language: 'english'` or `'german'`: no stopwords, no stemmer,
 * no duplicates.
 */
export function tokenize(input, language = 'english') {
  const splitter = SPLITTERS[language];
  if (!splitter) throw new Error(`search.js: unknown tokenizer "${language}"`);
  const parts = String(input).toLowerCase().split(splitter);
  const tokens = [];
  for (const part of parts) {
    if (!part) continue;
    const token = replaceDiacritics(part);
    if (token) tokens.push(token);
  }
  return Array.from(new Set(tokens));
}

// ------------------------------------------------------------------ Radix tree

class RadixNode {
  constructor(key, subWord, end) {
    this.k = key;
    this.s = subWord;
    this.c = new Map();
    this.e = end;
    this.w = '';
    this.d = undefined;
  }

  addDocumentToPostings(postings, docID) {
    let list = this.d;
    if (list) {
      list.push(docID);
      return;
    }
    list = postings.get(this.w);
    if (!list) {
      list = [docID];
      postings.set(this.w, list);
      this.d = list;
      return;
    }
    list.push(docID);
    this.d = list;
  }

  getDocumentsFromPostings(postings) {
    if (this.d) return this.d;
    const list = postings.get(this.w);
    if (!list) return [];
    this.d = list;
    return list;
  }

  findAllWords(output, postings) {
    const stack = [this];
    while (stack.length > 0) {
      const node = stack.pop();
      if (node.e) {
        const docIDs = node.getDocumentsFromPostings(postings);
        output[node.w] = docIDs.length > 0 ? [...docIDs] : [];
      }
      const children = node.c;
      if (children.size > 0) {
        for (const child of children.values()) stack.push(child);
      }
    }
    return output;
  }

  insert(word, docId, postings) {
    let node = this;
    let i = 0;
    const wordLength = word.length;
    while (i < wordLength) {
      const currentCharacter = word[i];
      const childNode = node.c.get(currentCharacter);
      if (childNode) {
        const edgeLabel = childNode.s;
        const edgeLabelLength = edgeLabel.length;
        let j = 0;
        while (
          j < edgeLabelLength &&
          i + j < wordLength &&
          edgeLabel.charCodeAt(j) === word.charCodeAt(i + j)
        ) {
          j++;
        }
        if (j === edgeLabelLength) {
          node = childNode;
          i += j;
          if (i === wordLength) {
            if (!childNode.e) childNode.e = true;
            childNode.addDocumentToPostings(postings, docId);
            return;
          }
          continue;
        }
        const commonPrefix = edgeLabel.slice(0, j);
        const newEdgeLabel = edgeLabel.slice(j);
        const newWordLabel = word.slice(i + j);
        const inbetweenNode = new RadixNode(commonPrefix[0], commonPrefix, false);
        inbetweenNode.w = node.w + commonPrefix;
        node.c.set(commonPrefix[0], inbetweenNode);
        childNode.s = newEdgeLabel;
        childNode.k = newEdgeLabel[0];
        inbetweenNode.c.set(newEdgeLabel[0], childNode);
        childNode.w = inbetweenNode.w + newEdgeLabel;
        if (newWordLabel) {
          const newNode = new RadixNode(newWordLabel[0], newWordLabel, true);
          newNode.w = inbetweenNode.w + newWordLabel;
          inbetweenNode.c.set(newWordLabel[0], newNode);
          newNode.addDocumentToPostings(postings, docId);
        } else {
          inbetweenNode.e = true;
          inbetweenNode.addDocumentToPostings(postings, docId);
        }
        return;
      }
      const suffix = word.slice(i);
      const newNode = new RadixNode(currentCharacter, suffix, true);
      newNode.w = node.w + suffix;
      node.c.set(currentCharacter, newNode);
      newNode.addDocumentToPostings(postings, docId);
      return;
    }
    if (!node.e) node.e = true;
    node.addDocumentToPostings(postings, docId);
  }

  /** `find` with `exact: false`, `tolerance: 0`: prefix search. */
  find(term, postings) {
    let node = this;
    let i = 0;
    const termLength = term.length;
    while (i < termLength) {
      const character = term[i];
      const childNode = node.c.get(character);
      if (!childNode) return {};
      const edgeLabel = childNode.s;
      const edgeLabelLength = edgeLabel.length;
      let j = 0;
      while (
        j < edgeLabelLength &&
        i + j < termLength &&
        edgeLabel.charCodeAt(j) === term.charCodeAt(i + j)
      ) {
        j++;
      }
      if (j === edgeLabelLength) {
        node = childNode;
        i += j;
      } else if (i + j === termLength) {
        if (j === termLength - i) return childNode.findAllWords({}, postings);
        return {};
      } else {
        return {};
      }
    }
    return node.findAllWords({}, postings);
  }
}

// --------------------------------------------------------------------- Ranking

const BM25_PARAMS = { k: 1.2, b: 0.75, d: 0.5 };
const PREFIX_EXPANSION_SCORE_DEMOTION = 0.5;

function BM25(tf, matchingCount, docsCount, fieldLength, averageFieldLength, { k, b, d }) {
  const idf = Math.log(1 + (docsCount - matchingCount + 0.5) / (matchingCount + 0.5));
  return (idf * (d + tf * (k + 1))) / (tf + k * (1 - b + (b * fieldLength) / averageFieldLength));
}

function bm25Idf(documentFrequency, docsCount) {
  return Math.log(1 + (docsCount - documentFrequency + 0.5) / (documentFrequency + 0.5));
}

function prefixExpansionDemotion(tokenDf, wordDf, docsCount) {
  if (tokenDf === undefined) return PREFIX_EXPANSION_SCORE_DEMOTION;
  const wordIdf = bm25Idf(wordDf, docsCount);
  if (!wordIdf) return PREFIX_EXPANSION_SCORE_DEMOTION;
  return PREFIX_EXPANSION_SCORE_DEMOTION * Math.min(1, bm25Idf(tokenDf, docsCount) / wordIdf);
}

function sortTokenScorePredicate(a, b) {
  if (b[1] === a[1]) return a[0] - b[0];
  return b[1] - a[1];
}

// ------------------------------------------------------------ Highlighting

function escapeRegExp(input) {
  return input.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function buildRegexFromQuery(q) {
  const trimmed = q.trim();
  if (trimmed.length === 0) return null;
  const terms = Array.from(new Set(trimmed.split(/\s+/).filter(Boolean)));
  if (terms.length === 0) return null;
  return new RegExp(`(${terms.map(escapeRegExp).join('|')})`, 'gi');
}

const ASCII_PUNCTUATION = /[!-/:-@[-`{-~]/;

const NAMED_REFERENCES = {
  amp: '&',
  lt: '<',
  gt: '>',
  quot: '"',
  apos: "'",
  nbsp: ' ',
};

/** A character reference, or `null` when there is none at this position. */
function decodeReference(value, start) {
  const match = /^&(#[Xx][0-9A-Fa-f]{1,6}|#\d{1,7}|[A-Za-z][A-Za-z0-9]{1,31});/.exec(
    value.slice(start),
  );
  if (!match) return null;
  const body = match[1];
  let char;
  if (body[0] === '#') {
    const code =
      body[1] === 'x' || body[1] === 'X'
        ? Number.parseInt(body.slice(2), 16)
        : Number.parseInt(body.slice(1), 10);
    if (!Number.isFinite(code) || code === 0 || code > 0x10ffff) return null;
    char = String.fromCodePoint(code);
  } else {
    char = NAMED_REFERENCES[body];
    if (char === undefined) return null;
  }
  return { value: char, length: match[0].length };
}

const HTML_TAG =
  /^<(?:[A-Za-z][A-Za-z0-9-]*(?:\s+[A-Za-z_:][A-Za-z0-9_.:-]*(?:\s*=\s*(?:[^\s"'=<>`]+|'[^']*'|"[^"]*"))?)*\s*\/?>|\/[A-Za-z][A-Za-z0-9-]*\s*>|!--[\s\S]*?-->)/;

/**
 * Inline parser for the search index contents: character escapes, character
 * references, code spans, raw HTML and emphasis (the CommonMark delimiter
 * algorithm). Links and images never occur in the index.
 */
function parseInline(value) {
  const nodes = [];
  const delimiters = [];
  let index = 0;
  let buffer = '';

  const flush = () => {
    if (buffer !== '') {
      nodes.push({ type: 'text', value: buffer });
      buffer = '';
    }
  };

  while (index < value.length) {
    const char = value[index];

    if (char === '\\') {
      const next = value[index + 1];
      if (next && ASCII_PUNCTUATION.test(next)) {
        buffer += next;
        index += 2;
        continue;
      }
      buffer += char;
      index += 1;
      continue;
    }

    if (char === '&') {
      const reference = decodeReference(value, index);
      if (reference) {
        buffer += reference.value;
        index += reference.length;
        continue;
      }
      buffer += char;
      index += 1;
      continue;
    }

    if (char === '`') {
      let run = 0;
      while (value[index + run] === '`') run++;
      const closing = new RegExp('(^|[^`])`{' + run + '}([^`]|$)');
      const rest = value.slice(index + run);
      const match = closing.exec(rest);
      if (match) {
        const end = match.index + match[1].length;
        let code = rest.slice(0, end);
        if (
          code.length > 2 &&
          /^[ \r\n]/.test(code) &&
          /[ \r\n]$/.test(code) &&
          /[^ \r\n]/.test(code)
        ) {
          code = code.slice(1, -1);
        }
        flush();
        nodes.push({ type: 'inlineCode', value: code.replace(/\r?\n|\r/g, ' ') });
        index += run + end + run;
        continue;
      }
      buffer += '`'.repeat(run);
      index += run;
      continue;
    }

    if (char === '<') {
      const match = HTML_TAG.exec(value.slice(index));
      if (match) {
        flush();
        nodes.push({ type: 'html', value: match[0] });
        index += match[0].length;
        continue;
      }
      buffer += char;
      index += 1;
      continue;
    }

    if (char === '*' || char === '_') {
      let run = 0;
      while (value[index + run] === char) run++;
      const before = index === 0 ? '\n' : value[index - 1];
      const after = index + run >= value.length ? '\n' : value[index + run];
      const beforeWhite = /\s/.test(before);
      const afterWhite = /\s/.test(after);
      const beforePunct = !beforeWhite && isPunctuation(before);
      const afterPunct = !afterWhite && isPunctuation(after);
      const leftFlanking = !afterWhite && (!afterPunct || beforeWhite || beforePunct);
      const rightFlanking = !beforeWhite && (!beforePunct || afterWhite || afterPunct);
      const canOpen = char === '*' ? leftFlanking : leftFlanking && (!rightFlanking || beforePunct);
      const canClose =
        char === '*' ? rightFlanking : rightFlanking && (!leftFlanking || afterPunct);

      flush();
      const node = { type: 'text', value: char.repeat(run) };
      nodes.push(node);
      delimiters.push({
        index: nodes.length - 1,
        char,
        count: run,
        original: run,
        canOpen,
        canClose,
        node,
      });
      index += run;
      continue;
    }

    buffer += char;
    index += 1;
  }
  flush();

  processEmphasis(nodes, delimiters);

  return mergeText(nodes);
}

function isPunctuation(char) {
  return /[!-/:-@[-`{-~]|\p{P}|\p{S}/u.test(char);
}

/** The `process emphasis` step from CommonMark. */
function processEmphasis(nodes, delimiters) {
  let closerIndex = 0;
  while (closerIndex < delimiters.length) {
    const closer = delimiters[closerIndex];
    if (!closer.canClose || closer.count === 0) {
      closerIndex++;
      continue;
    }
    let openerIndex = closerIndex - 1;
    let opener = null;
    while (openerIndex >= 0) {
      const candidate = delimiters[openerIndex];
      if (
        candidate.count > 0 &&
        candidate.canOpen &&
        candidate.char === closer.char &&
        !((closer.canOpen || candidate.canClose) &&
          closer.original % 3 !== 0 &&
          (candidate.original + closer.original) % 3 === 0)
      ) {
        opener = candidate;
        break;
      }
      openerIndex--;
    }
    if (!opener) {
      closerIndex++;
      continue;
    }

    const use = opener.count >= 2 && closer.count >= 2 ? 2 : 1;
    const children = nodes.slice(opener.index + 1, closer.index).filter((node) => node !== null);
    const wrapper = {
      type: use === 2 ? 'strong' : 'emphasis',
      children: mergeText(children.filter((node) => node.type !== 'text' || node.value !== '')),
    };
    opener.count -= use;
    closer.count -= use;
    opener.node.value = opener.char.repeat(opener.count);
    closer.node.value = closer.char.repeat(closer.count);

    for (let i = opener.index + 1; i < closer.index; i++) nodes[i] = null;
    nodes[closer.index - 1] = wrapper;
    // Delimiters in between are used up.
    for (let i = openerIndex + 1; i < closerIndex; i++) delimiters[i].count = 0;
    if (closer.count === 0) closerIndex++;
  }

  for (let i = nodes.length - 1; i >= 0; i--) {
    if (nodes[i] === null || (nodes[i].type === 'text' && nodes[i].value === '')) nodes.splice(i, 1);
  }
}

function mergeText(nodes) {
  const out = [];
  for (const node of nodes) {
    if (node === null) continue;
    const last = out[out.length - 1];
    if (node.type === 'text' && last && last.type === 'text') {
      last.value += node.value;
      continue;
    }
    out.push(node);
  }
  return out;
}

/** Block level: either an HTML block (component tag) or a paragraph. */
function parseContent(value) {
  if (/^<[A-Za-z!/]/.test(value)) {
    const match = HTML_TAG.exec(value);
    if (match && match[0].length === value.length) {
      return [{ type: 'html', value }];
    }
  }
  return [{ type: 'paragraph', children: parseInline(value) }];
}

function classifyCharacter(char) {
  if (char === undefined || char === '') return undefined;
  if (/[\r\n \t]/.test(char) || /\s/.test(char)) return 1;
  if (/\p{P}|\p{S}/u.test(char)) return 2;
  return undefined;
}

/** `encodeInfo` from mdast-util-to-markdown. */
function encodeInfo(outside, inside, marker) {
  const outsideKind = classifyCharacter(outside);
  const insideKind = classifyCharacter(inside);

  if (outsideKind === undefined) {
    return insideKind === undefined
      ? marker === '_'
        ? { inside: true, outside: true }
        : { inside: false, outside: false }
      : insideKind === 1
        ? { inside: true, outside: true }
        : { inside: false, outside: true };
  }

  if (outsideKind === 1) {
    return insideKind === undefined
      ? { inside: false, outside: false }
      : insideKind === 1
        ? { inside: true, outside: true }
        : { inside: false, outside: false };
  }

  return insideKind === undefined
    ? { inside: false, outside: false }
    : insideKind === 1
      ? { inside: true, outside: false }
      : { inside: false, outside: false };
}

function encodeCharacterReference(code) {
  return '&#x' + code.toString(16).toUpperCase() + ';';
}

function peek(node) {
  switch (node.type) {
    case 'html':
      return '<';
    case 'inlineCode':
      return '`';
    case 'strong':
    case 'emphasis':
      return '*';
    default:
      return '';
  }
}

/**
 * `containerPhrasing` from mdast-util-to-markdown, here with `peek`, because
 * `highlightMarkdown` uses the unmodified remark stringifier.
 */
function containerPhrasing(children, info, state) {
  const results = [];
  let before = info.before;
  let encodeAfter;

  for (let index = 0; index < children.length; index++) {
    const child = children[index];
    const after = index + 1 < children.length ? peek(children[index + 1]) : info.after;

    let value = handleNode(child, { before, after }, state);

    if (encodeAfter && encodeAfter === value.slice(0, 1)) {
      value = encodeCharacterReference(encodeAfter.charCodeAt(0)) + value.slice(1);
    }

    const encodingInfo = state.attention;
    state.attention = undefined;
    encodeAfter = undefined;

    if (encodingInfo) {
      if (results.length > 0 && encodingInfo.before && before === results[results.length - 1].slice(-1)) {
        results[results.length - 1] =
          results[results.length - 1].slice(0, -1) + encodeCharacterReference(before.charCodeAt(0));
      }
      if (encodingInfo.after) encodeAfter = after;
    }

    results.push(value);
    before = value.slice(-1);
  }

  return results.join('');
}

function attentionHandler(node, info, state, marker) {
  let between = containerPhrasing(node.children, { before: marker, after: marker[0] }, state);

  const open = encodeInfo(info.before.slice(-1), between.slice(0, 1), marker[0]);
  if (open.inside) {
    between = encodeCharacterReference(between.charCodeAt(0)) + between.slice(1);
  }
  const close = encodeInfo(info.after.slice(0, 1), between.slice(-1), marker[0]);
  if (close.inside) {
    between = between.slice(0, -1) + encodeCharacterReference(between.charCodeAt(between.length - 1));
  }

  state.attention = { after: close.outside, before: open.outside };
  return marker + between + marker;
}

function inlineCodeHandler(node) {
  let value = node.value || '';
  let sequence = '`';
  while (new RegExp('(^|[^`])' + sequence + '([^`]|$)').test(value)) sequence += '`';
  if (
    /[^ \r\n]/.test(value) &&
    ((/^[ \r\n]/.test(value) && /[ \r\n]$/.test(value)) || /^`|`$/.test(value))
  ) {
    value = ' ' + value + ' ';
  }
  return sequence + value + sequence;
}

function handleNode(node, info, state) {
  switch (node.type) {
    case 'html':
    case 'text':
      return node.value;
    case 'inlineCode':
      return inlineCodeHandler(node);
    case 'strong':
      return attentionHandler(node, info, state, '**');
    case 'emphasis':
      return attentionHandler(node, info, state, '*');
    case 'paragraph':
      return containerPhrasing(node.children, info, state);
    default:
      return '';
  }
}

function highlightInTree(nodes, regex) {
  for (const node of nodes) {
    if (node.type === 'text') {
      const content = node.value;
      let out = '';
      let i = 0;
      regex.lastIndex = 0;
      for (const match of content.matchAll(regex)) {
        if (i < match.index) out += content.substring(i, match.index);
        out += `<mark>${match[0]}</mark>`;
        i = match.index + match[0].length;
      }
      if (i < content.length) out += content.substring(i);
      node.type = 'html';
      node.value = out;
      continue;
    }
    if (node.children) highlightInTree(node.children, regex);
  }
}

/** `createContentHighlighter(query).highlightMarkdown(content)`. */
export function createContentHighlighter(query) {
  const regex = buildRegexFromQuery(query);
  return {
    highlightMarkdown(content) {
      if (!regex) return content;
      const tree = parseContent(content);
      highlightInTree(tree, regex);
      const state = { attention: undefined };
      const parts = tree.map((node) => handleNode(node, { before: '\n', after: '\n' }, state));
      return parts.join('\n\n').trim();
    },
  };
}

// ------------------------------------------------------------------- Search index

const TYPES = ['page', 'heading', 'text'];

/**
 * Builds the search from `search-index.json`.
 *
 * @param {{base: string, tokenizer?: string, pages: Array, docs: Array}} indexJson
 *        `tokenizer` selects the splitter profile; an index without it is `english`.
 * @returns {{search: (query: string) => Array}}
 */
export function createSearch(indexJson) {
  const language = indexJson.tokenizer ?? 'english';
  if (!Object.hasOwn(SPLITTERS, language)) {
    throw new Error(`search.js: unknown tokenizer "${language}" in the search index`);
  }
  const pages = indexJson.pages;
  const docs = [];
  const byId = new Map();

  for (const entry of indexJson.docs) {
    const [pageIndex, type, number, anchor, content] = entry;
    const page = pages[pageIndex];
    const id = number === null ? page.u : `${page.u}-${number}`;
    const doc = {
      id,
      pageId: page.u,
      type: TYPES[type],
      content,
      url: anchor === null ? page.u : `${page.u}#${anchor}`,
      breadcrumbs: type === 0 ? page.b ?? undefined : undefined,
    };
    docs.push(doc);
    byId.set(id, doc);
  }

  // Index like `insertMultipleAsync`: document by document, internal id = position.
  const postings = new Map();
  const root = new RadixNode('', '', false);
  const frequencies = [];
  const fieldLengths = [];
  let avgFieldLength = 0;

  for (let internalId = 0; internalId < docs.length; internalId++) {
    const tokens = tokenize(docs[internalId].content, language);
    const docsCount = internalId + 1;
    avgFieldLength = (avgFieldLength * (docsCount - 1) + tokens.length) / docsCount;
    fieldLengths[internalId] = tokens.length;
    const freq = Object.create(null);
    frequencies[internalId] = freq;
    for (const token of tokens) {
      if (Object.hasOwn(freq, token)) {
        freq[token] += 1;
      } else {
        freq[token] = 1;
        root.insert(token, internalId, postings);
      }
    }
  }

  const docsCount = docs.length;

  /** `index.search` with `threshold: 1`, `tolerance: 0`, `exact: false`, boost 1. */
  function rank(term) {
    const tokens = tokenize(term, language);
    if (tokens.length === 0) return [];

    const resultsMap = new Map();

    for (const token of tokens) {
      const searchResult = root.find(token, postings);
      const termsFound = Object.keys(searchResult);
      for (const word of termsFound) {
        const ids = searchResult[word];
        const boost =
          word === token
            ? 1
            : prefixExpansionDemotion(searchResult[token]?.length, ids.length, docsCount);
        const termOccurrences = postings.get(word)?.length ?? 0;
        for (const internalId of ids) {
          const tf = frequencies[internalId]?.[word] ?? 0;
          const score = BM25(
            tf,
            termOccurrences,
            docsCount,
            fieldLengths[internalId],
            avgFieldLength,
            BM25_PARAMS,
          );
          resultsMap.set(
            internalId,
            resultsMap.has(internalId) ? resultsMap.get(internalId) + score * boost : score * boost,
          );
        }
      }
    }

    const results = Array.from(resultsMap.entries()).sort((a, b) => b[1] - a[1]);
    return results.sort(sortTokenScorePredicate);
  }

  /** `getGroups` with `properties: ['page_id']`, `maxResult: 8`. */
  function group(sorted) {
    const perValue = new Map();
    const values = [];

    for (let position = 0; position < sorted.length; position++) {
      const doc = docs[sorted[position][0]];
      const key = doc.pageId;
      let bucket = perValue.get(key);
      if (!bucket) {
        bucket = [];
        perValue.set(key, bucket);
      }
      if (bucket.length >= 8) continue;
      if (bucket.length === 0) values.push(key);
      bucket.push(position);
    }

    return values.map((value) => ({
      value,
      result: perValue.get(value).map((position) => ({
        document: docs[sorted[position][0]],
        score: sorted[position][1],
      })),
    }));
  }

  /** `searchAdvanced` from fumadocs-core: the page first, its hits below it. */
  function search(query) {
    const term = typeof query === 'string' ? query : '';
    if (term.length === 0) return [];

    const sorted = rank(term);
    const groups = group(sorted);
    const highlighter = createContentHighlighter(term);
    // `searchAdvanced` sets `limit: 60`, but the endpoint options override it
    // with `limit: undefined` afterwards, so the API returns every hit.
    const limit = Infinity;
    const list = [];

    for (const item of groups) {
      if (list.length >= limit) break;
      const page = byId.get(item.value);
      if (!page) continue;
      const entry = {
        id: item.value,
        type: 'page',
        content: highlighter.highlightMarkdown(page.content),
        url: page.url,
      };
      if (page.breadcrumbs !== undefined) entry.breadcrumbs = page.breadcrumbs;
      list.push(entry);

      for (const hit of item.result) {
        if (list.length >= limit) break;
        if (hit.document.type === 'page') continue;
        const child = {
          id: hit.document.id,
          content: highlighter.highlightMarkdown(hit.document.content),
          type: hit.document.type,
          url: hit.document.url,
        };
        if (hit.document.breadcrumbs !== undefined) child.breadcrumbs = hit.document.breadcrumbs;
        list.push(child);
      }
    }

    return list;
  }

  return { search };
}

export default createSearch;
