# `src/` — the generator

Empty on purpose. The PHP generator is moved here from
its original repository with `git subtree split`, so that its
one-file-per-commit history arrives intact rather than as one squashed import.

Layout once the move has happened:

| Path | What lives there |
| --- | --- |
| `src/lib/Markdown.php` | Strict parser for the Markdown subset plus component tags, producing an AST |
| `src/lib/Tree.php` | Page tree from `meta.json`: tabs, path, breadcrumb, previous/next, projection |
| `src/lib/Slug.php` | `github-slugger`-compatible ids, including the `-1`, `-2` duplicate suffixes |
| `src/lib/Toc.php` | Headings to table-of-contents entries (depth, url, title) |
| `src/lib/SearchIndex.php` | Index documents, shaped like Fumadocs' `buildDocuments` |
| `src/lib/Html.php` | Escaping, attribute builder, internal/external link detection |
| `src/lib/Icons.php`, `src/lib/I18n.php` | Vendored lucide paths, UI translations |
| `src/components/*.php` | One file per component, pure functions: `string nd_callout(array $props, string $children)` |
| `src/templates/document.php` | The `<html>` shell: head, theme bootstrap, fonts, CSS, JS module |

Rules that hold for every file here: PHP runs at build time only, there are no
Composer dependencies, and unknown input aborts the build instead of degrading
silently.
