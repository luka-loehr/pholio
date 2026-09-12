# `theme/` — CSS, JS, fonts, icons

Empty on purpose. The theme arrives together with the generator.

| Path | What lives there |
| --- | --- |
| `theme/css/notebook.css` | One file, cascade order: tokens, reset, fonts, animations, layout, components, prose, utilities |
| `theme/js/*.js` | ES modules, one per behaviour: theme, dialog, search, collapsible, popover, scroll-area, sidebar, toc, toc-popover, copy, hotkeys |
| `theme/fonts/inter/*.woff2` | Inter, subset by `unicode-range`, plus the fallback metrics |
| `theme/icons/*.svg` | The lucide icons actually used, extracted as path lists |
| `theme/LICENSES/` | One licence file per vendored asset: Inter (OFL), lucide (ISC), the values derived from Tailwind (MIT), zbsearch (Apache-2.0) |

The CSS is hand-written, value by value, from the compiled Tailwind output of
the reference build. It is not claimed to match; the computed-style diff in
`verify/` proves it does.
