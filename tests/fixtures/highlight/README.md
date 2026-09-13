# Highlight samples

Regression samples for the PHP syntax highlighter (`src/lib/Highlight.php`). Each sample `<lang>/<name>.<ext>` has
its expected output `<name>.<ext>.expected.html` (or `.expected.error.txt`) next to it. An optional
`<name>.<ext>.meta.json` sets `lang`, `info` (the code fence meta) and `mode` (`fence` or `dynamic`, the latter for
`DynamicCodeBlock`).

## Case matrix

Every language directory covers the same structural cases:

| Case | Files | What it exercises |
| --- | --- | --- |
| Basics | `01-*` | Core grammar, a leading blank line, tab indentation, comments, numbers, string escapes |
| Notation | `02-*` | `[!code focus]`, `highlight`, `highlight:3`, `++`, `--`, `word:x`, `word:x:2` notations |
| CRLF | `03-crlf.*` | Windows line endings (`.gitattributes` keeps the bytes) |
| Long lines and Unicode | `04-*` | Lines of 2,500+ characters, non-BMP characters, emoji with ZWJ, NBSP, trailing whitespace, blank-line runs |
| Unterminated | `05-*` | A string, comment or here-document still open at the end of a file without a final newline |

`json/05-invalid.json` and `json/06-content-tree.json` add invalid JSON and a deep real-world tree,
`text/05-no-trailing-newline.txt` and `text/06-guide.txt` do the same for plain text. `php/04-heredoc.php`
covers heredoc, nowdoc and embedded SQL. `diff/05-multi.diff` combines a mail patch, several files and
`\ No newline at end of file`.

`catalogue/` holds the code blocks of a component page (titles, `{1,3-4}` line highlights, tabs, `lineNumbers`,
one `DynamicCodeBlock`). `special/` holds the edge cases of the info string: an unknown language (an error), no
language, the aliases `sh`, `yml`, `mjs` and `plaintext`, an empty block, mixed meta
(`lineNumbers=5 noCopy title="a b.php" {2}`) and a `title` next to a `tab`.

`scripts/lint.sh` skips this directory, because some samples are invalid PHP on purpose.

## Running and updating

```sh
php tests/HighlightTest.php                    # all samples
php tests/HighlightTest.php --only sql/01      # matching samples only
php tests/HighlightTest.php --diff             # longer excerpt around the first difference
php tests/HighlightTest.php --update           # rewrite the expected files from the current output
```

After an intended change to a grammar, a theme or the highlighter, run `--update` and review every changed
expected file with `git diff` before committing it.

The comparison needs **PCRE2 >= 10.43** (`php -r 'echo PCRE_VERSION;'`). Older PCRE2 versions can't compile
variable-length lookbehinds; the highlighter then simplifies those patterns with a warning, and the test skips
itself (or fails with `PHOLIO_REQUIRE_PCRE2=1`).
