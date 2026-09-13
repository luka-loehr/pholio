# `theme/`: CSS, JavaScript, fonts

Copied into every build below `asset_base`.

| Path | What lives there |
| --- | --- |
| `theme/css/notebook.css` | Entry stylesheet. Its `@import` parts are inlined by the build into one file |
| `theme/css/*.css` | The parts in cascade order: tokens, reset, fonts, animations, layout, components, catalogue, prose, utilities |
| `theme/css/tokens.css` | Colour tokens, and the `/* @pholio:palette */` marker where the build writes `theme.light`, `theme.dark` and `theme.palette_css` |
| `theme/js/*.js` | ES modules, one per behaviour: theme, dialog, search, collapsible, popover, scroll area, sidebar, tabs, table of contents, copy, hotkeys and the catalogue components; `notebook.js` wires them up |
| `theme/js/i18n.js` | The interface strings JavaScript renders, in sync with `src/i18n/` |
| `theme/fonts/inter/` | Inter, split by `unicode-range` |

Icons and grammars are not here: lucide paths and the Shiki grammars live in
`vendor-data/`, and the licence texts copied into each build in `licenses/`.

The CSS is hand-written, value by value, from the compiled Tailwind output of
the reference build. It is not claimed to match; the computed-style comparison
in `verify/` measures it.
