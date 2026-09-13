# `theme/`: CSS, JavaScript, fonts

Copied into every build below `asset_base`.

| Path | What lives there |
| --- | --- |
| `theme/css/notebook.css` | Entry stylesheet. Its `@import` parts are inlined by the build into one file |
| `theme/css/*.css` | The parts in cascade order: tokens, reset, fonts, animations, layout, components, catalog, prose, utilities |
| `theme/css/tokens.css` | Color tokens, and the `/* @pholio:palette */` marker where the build writes `theme.preset`, `theme.light`, `theme.dark` and `theme.palette_css` |
| `theme/presets/*.css` | Built-in color presets, one file per preset, selected with `theme.preset` |
| `theme/js/*.js` | ES modules, one per behavior: theme, dialog, search, collapsible, popover, scroll area, sidebar, tabs, table of contents, copy, hotkeys and the catalog components; `notebook.js` wires them up |
| `theme/js/i18n.js` | The interface strings JavaScript renders, in sync with `src/i18n/` |
| `theme/fonts/inter/` | Inter, split by `unicode-range` |

Icons and grammars are not here: lucide paths and the Shiki grammars live in
`vendor-data/`, and the license texts copied into each build in `licenses/`.

The CSS is plain, hand-written CSS with no build step: component classes
(`nd-*`) whose declarations are written in Tailwind v4's output form. How the
files cascade is described in [`css/README.md`](css/README.md).
