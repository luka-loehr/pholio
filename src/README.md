# `src/`: the generator

PHP 8.2, no Composer dependencies, run at build time only. Unknown input aborts
the build instead of degrading silently.

| Path | What lives there |
| --- | --- |
| `Cli.php` | `pholio build`, `check` and `dev`: argument parsing and the mapping of errors to exit codes |
| `Config.php` | Loads `pholio.config.php`, validates it against the schema and normalizes it. Its docblock documents the internal shape every other file reads |
| `Builder.php` | One build run: pages, start page, theme assets with the palette inserted, `copy` directories, search index, `.htaccess` |
| `Htaccess.php` | The generated `.htaccess`: hardening, redirects, slashless URLs |
| `DevServer.php` | Router for PHP's built-in server, used by `pholio dev` |
| `Fs.php` | Writing, copying and the two-way comparison behind `pholio check` |
| `Exceptions.php` | `ConfigException` (exit 2), `ContentException` and `MarkdownException` (3), `IoException` (4) |
| `I18n.php`, `i18n/*.php` | Interface strings per language: `en.php` defines every key, `de.php` translates them |
| `lib/Markdown.php`, `lib/Frontmatter.php` | Strict parser for the Markdown subset and the component tags, producing an AST |
| `lib/Tree.php` | Page tree from `meta.json`: root areas, breadcrumbs, previous and next |
| `lib/Ids.php`, `lib/Slug.php`, `lib/Toc.php` | `github-slugger` ids and the table of contents |
| `lib/Highlight.php`, `lib/Highlight/` | TextMate grammars translated to PCRE2, with the GitHub light and dark themes |
| `lib/Render.php`, `lib/RenderCatalogue.php` | AST to HTML through the components |
| `lib/SearchIndex.php` | Search index documents and the tokenizer profile |
| `lib/Html.php`, `lib/Icons.php` | Escaping and attributes, lucide icons |
| `components/*.php` | One file per component, pure functions: props in, HTML string out |
| `templates/document.php` | The `<html>` shell: head, theme bootstrap, stylesheet, scripts |

Public configuration keys are snake_case and documented in
[`docs/configuration.md`](../docs/configuration.md); the internal shape uses
camelCase so the two can't be confused.
