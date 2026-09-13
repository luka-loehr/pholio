# Highlight samples

The reference for the PHP syntax highlighter (`src/lib/Highlight.php`). Each sample `<lang>/<name>.<ext>` has
its reference `<name>.<ext>.expected.html` (or `.expected.error.txt`) next to it, produced by the real
`rehypeCode` of the reference core package (Shiki, JavaScript regex engine) from `verify/node_modules`. An optional
`<name>.<ext>.meta.json` sets `lang`, `info` and `mode` (`fence` or `dynamic`); see the header of
`../highlight-oracle.mjs`.

## Case matrix

Every language directory covers the same structural cases:

| Case | Files | What it exercises |
| --- | --- | --- |
| Basics | `01-*` | Core grammar, a leading blank line, tab indentation, comments, numbers, string escapes |
| Notation | `02-*` | `[!code focus]`, `highlight`, `highlight:3`, `++`, `--`, `word:x`, `word:x:2` transformers |
| CRLF | `03-crlf.*` | Windows line endings (`.gitattributes` keeps the bytes) |
| Long lines and Unicode | `04-*` | Lines of 2,500+ characters, non-BMP characters, emoji with ZWJ, NBSP, trailing whitespace, blank-line runs |
| Unterminated | `05-*` | A string, comment or here-document still open at the end of a file without a final newline |

`json/05-invalid.json` and `json/06-content-tree.json` add invalid JSON and a deep real-world tree,
`text/05-no-trailing-newline.txt` and `text/06-guide.txt` do the same for plain text. `php/04-heredoc.php`
covers heredoc, nowdoc and the embedded-SQL function calls that trigger the V8 bug below. `diff/05-multi.diff`
combines a mail patch, several files and `\ No newline at end of file`.

`catalogue/` holds the code blocks of a component catalog page (titles, `{1,3-4}` line highlights, tabs,
`lineNumbers`, one `DynamicCodeBlock`). `special/` holds the edge cases of the info string: an unknown language
(error reference), no language, the aliases `sh`, `yml`, `mjs` and `plaintext`, an empty block, mixed meta
(`lineNumbers=5 noCopy title="a b.php" {2}`) and a `title` next to a `tab`.

## Producing references

```sh
node verify/highlight-oracle.mjs                      # all samples, writes here
node verify/highlight-oracle.mjs --only sql/01        # matching samples only
node verify/highlight-oracle.mjs --out /tmp/oracle    # somewhere else (drift check)
```

Dependencies come from `verify/node_modules` (`cd verify && npm ci`), or `--node-modules <dir>` /
`PHOLIO_VERIFY_NODE_MODULES`. The parent process starts **a fresh Node process for every sample**
(`--single <path>`, at most 8 in parallel). Child exit codes: 0 reference written, 2 error reference written,
1 aborted. The oracle also sets `tokenizeTimeLimit: 0`. Both are needed so that a reference depends on its input
only:

1. **V8 bug (process history).** V8 14.6 (Node 26.8) matches a modifier group `(?i:...)` that contains an
   alternation case-sensitively on the unoptimized native RegExp path. oniguruma-to-es emits exactly that shape,
   for example for the SQL function patterns `(?i)\b(ascii|char|...|sum|upper|...)\b\s*\(`. Whether a pattern
   takes that path depends on the RegExp work the process did before. With all samples in one shared process,
   `php/04-heredoc.php`, `sql/01-schema.sql` and `sql/04-long-unicode.sql` leave `CHAR(`, `SUM(` and `UPPER(`
   uncolored; in isolation they are colored, and the committed references are the isolated output.
2. **Shiki's time limit.** By default Shiki stops tokenizing a line after 500 ms and emits the rest uncolored.
   The long lines in the `04-*` samples can exceed that when several oracle processes run in parallel, which would
   make the result depend on machine load.

`known-differences.json` is therefore empty. A new entry needs a proof (`proof.isolated`), which
`tests/HighlightTest.php` runs itself.

### Minimal reproduction of the V8 bug

`v8-repro.mjs`:

```js
const re = new RegExp(String.raw`(?i:(ascii|char|charindex)\b\p{space}*\()`, 'dgv');
console.log(re.exec(' CHAR(')?.index ?? null);
```

```console
$ node v8-repro.mjs
1
$ node --no-regexp-optimization v8-repro.mjs
null
```

The correct result is `1`. Controls give `1` with `--no-regexp-optimization` as well:
`(?i:char\b\p{space}*\()` (no alternation) and `(?:(ascii|char|charindex)\b\p{space}*\()` with the `i` flag
instead of a modifier group.

## PHP side

`php tests/HighlightTest.php [--only <substring>] [--diff]` compares the PHP output byte for byte with the
committed references. With Node and `verify/node_modules`, a fresh oracle run must also reproduce the committed
references, and every entry in `known-differences.json` is proven by an isolated oracle run. Without them those
checks print `SKIP:`.

PHP needs **PCRE2 >= 10.43** (`php -r 'echo PCRE_VERSION;'`). Older PCRE2 versions can't compile variable-length
lookbehinds; the engine then disables such patterns with a warning, and parity with the references is lost.
