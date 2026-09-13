# theme/css — how the stylesheet is put together

Plain CSS, no preprocessor. `notebook.css` is the entry file; the build joins
its `@import` parts into one file in the same order, so the browser makes one
request. The `@import` form works directly in a browser during development.

## Cascade

`notebook.css` declares the layer order before any import:

    @layer properties, theme, base, components, utilities;

Layers rank by the order they are first declared, lowest first. `properties`
has to be in that list: if `tokens.css` opened it on its own, it would be the
last declared and so the strongest, and its `--tw-*` fallback values (such as
`--tw-border-style: solid`) would beat the values utilities set later.

Unlayered rules win against every layer. The theme relies on that for the site
palette, `html { scrollbar-gutter: stable }`, the Shiki rules and the image zoom.

## Palette

`tokens.css` carries the default (neutral) colors. The line

    /* @pholio:palette */

marks where the build writes the site palette: unlayered, after the default
dark palette and the sidebar overrides, before the `@property` registrations.
It writes, in this order:

1. the built-in preset named by `theme.preset`, from `theme/presets/<name>.css`
   (the default, `neutral`, adds nothing);
2. `:root { --color-fd-* }` and `.dark { … }` from `theme.light` and `theme.dark`;
3. the contents of the file named in `theme.palette_css`.

Later declarations win, so a site can pick a preset and still override single
tokens or add its own rules.

## Files

| File | Content |
| --- | --- |
| `notebook.css` | Entry file: the layer order and the imports in cascade order |
| `tokens.css` | `@layer properties` fallbacks of the `--tw-*` variables (Safari), `@layer theme` (Tailwind theme, default colors, status and diff colors, animation shorthands), the unlayered default dark palette and sidebar overrides, the palette marker, the `@property` registrations |
| `reset.css` | Tailwind v4 preflight in `@layer base` with the theme's border and body colors; unlayered `scrollbar-gutter` and scroll lock rules |
| `fonts.css` | Inter `@font-face` blocks per subset, the metric-adjusted `Inter Fallback`, the font family on `html` |
| `animations.css` | Every `@keyframes`: `pulse`, sidebar, dialog, popover and fade in and out |
| `layout.css` | `@layer components`: grid, header, section tabs, sidebar and drawer, article frame, table of contents column and popover frame, start page scaffold |
| `components.css` | `@layer components`: buttons, search trigger and dialog, theme switch, section switcher, page menu, breadcrumbs, footer navigation, TOC entries, headings, callouts, cards, images, tables, hero and section cards; a few `@layer utilities` and unlayered rules at the end |
| `catalogue.css` | `@layer components`: tabs, code tabs, accordion, file tree, type table, banner, inline table of contents, code block shell; image zoom unlayered |
| `prose.css` | Steps, the `.prose` typography rules and scroll container scrollbars in `@layer utilities`; the `.shiki` code rules unlayered |
| `utilities.css` | `@layer utilities`: `.nd-hidden`, `.nd-invisible` and the width of the article children |

When editing `prose.css`, check the braces of the `@media (min-width: 40rem)`
block around the steps: with one missing the file stays valid, but every
`.prose` rule slides into the media query and stops applying on narrow screens.

## Component classes

Markup carries component classes, not utilities: `nd-<component>` for the root
and `nd-<component>-<part>` for its parts (`nd-sidebar`, `nd-sidebar-item`,
`nd-toc`, `nd-tocpop`, `nd-search`, `nd-callout`, `nd-card`, …). Each rule group
names in its comment the Tailwind utilities its declarations are written from:

    /* .nd-page-desc  <-  mb-8 text-fd-muted-foreground text-lg */

The declarations follow Tailwind v4's output for those utilities, including the
`@supports (color: color-mix(in lab, …))` pairs, the `@media (hover: hover)`
wrappers of hover states and the `rtl:` selectors.

State lives on attributes, not on modifier classes: `data-open`/`data-closed`,
`data-active`, `data-collapsed`, `data-hovered`, `data-state`, `aria-selected`,
`aria-expanded` and pseudo-classes. JavaScript sets the attributes and the CSS
hooks onto them. A class stands in only where no attribute exists, for example
`nd-tab-active` on the active section tab, `nd-card-link` on a card with a target
and `nd-theme-icon-active` on the active theme icon; `.nd-hidden` and
`.nd-invisible` are the only visibility switches.

## Why `@layer components`

The `.prose` typography rules live in `utilities`. Component rules that must
lose against them, such as the vertical margin of screenshots and images that
`.prose :where(figure)` and `.prose :where(img)` override, therefore live in
`components`. The few rules that must win against `.prose`, the width of the
article children, live in `utilities.css`, which is imported after
`prose.css`. A table of contents entry doesn't carry `prose` at all and gets the
declarations it needs directly in `.nd-toc-item`.
