# theme/css — where every rule comes from

Everything in this directory is derived from the frozen reference stylesheet,
the reference export's compiled stylesheet
`css/[root-of-the-server]__1br6yta._.css`: 4655 lines, Tailwind v4 output of
`next build` of the Fumadocs reference build. The line numbers below refer to
exactly this file, so a review can cross-check with `sed -n 'FROM,TOp'`.

## Cascade

The reference stylesheet doesn't declare its layers as a list; it fixes the
order by first appearance:

| Line | Rule | Priority |
| --- | --- | --- |
| 2 | `@layer properties {` | lowest |
| 75 | `@layer theme {` | |
| 173 | `@layer base {` | |
| 432 | `@layer components;` | |
| 434 | `@layer utilities {` | highest |

It leaves some rules **unlayered**; unlayered rules win against every layer.
The site palette and `html { scrollbar-gutter: stable }` depend on exactly
that. The theme keeps layer names and layer membership. `notebook.css`
declares the order up front:

    @layer properties, theme, base, components, utilities;

`properties` has to come first. If it were missing from the list, `tokens.css`
would open the layer itself and it would be the last declared, and so the
strongest: the `--tw-*` fallback values in it (such as
`--tw-border-style: solid`) would then beat the values utilities set later.
`verify/css-values.mjs` checks this order too.

## Site palette

`tokens.css` carries the Fumadocs defaults only. The line

    /* @pholio:palette */

marks the place of the site palette: unlayered, after the default dark palette
and the sidebar overrides, before the `@property` registrations. The build
replaces it with `:root { --color-fd-* }` and `.dark { … }` generated from
`theme.light` and `theme.dark`, followed by the contents of the file named in
`theme.palette_css`. A palette file therefore lands at the same cascade slot
the reference build's palette has in the reference stylesheet (lines
4090–4136).

## Files

| File | Reference lines | Content |
| --- | --- | --- |
| `tokens.css` | 2–73 | `@layer properties`: fallback initial values of the `--tw-*` variables (Safari branch) |
| | 75–171 | `@layer theme`: Tailwind theme + `fumadocs-ui/css/lib/default-colors.css` + `@supports (color: lab(…))` follow-ups |
| | 3894–3926 | unlayered: default `.dark` palette, `.dark #nd-sidebar` (`neutral.css`), `--fd-sidebar-drawer-offset` including `[dir="rtl"]` |
| | 4090–4136 | not in the file: the reference build's palette from `app/theme.css` (`data-preset`) for `:root` and `.dark`, plus `#nd-sidebar` and `.dark #nd-sidebar`; a site supplies its own through the palette marker |
| | 4147–4502 | 66 `@property` registrations of the `--tw-*` variables |
| `reset.css` | 173–430 | `@layer base`: Tailwind v4 preflight, at the end the two additions from `fumadocs-ui/css/lib/base.css` (`border-color: var(--color-fd-border, currentColor)`, `body { background-color/color }`) |
| | 4138–4145 | unlayered: `html { scrollbar-gutter: stable }` and `html > body[data-scroll-locked]` from `app/global.css` |
| `fonts.css` | 4579–4654 | the seven Inter `@font-face` blocks from `next/font/google`, `Inter Fallback` with the four metrics, and the class rule `font-family: Inter, Inter Fallback`, here directly on `html` |
| `animations.css` | 4504–4576 | the nine `@keyframes`: `pulse`, `fd-sidebar-in/out`, `fd-dialog-in/out`, `fd-popover-in/out`, `fd-fade-in/out` |
| `prose.css` | 451–485 | `.fd-step:before`, `.fd-steps` (+ `@media (min-width: 40rem)`, which reaches to 485) from `base.css` |
| | 777–1210 | the 76 `.prose` rules from `@fumadocs/tailwind/typography`, plus `.prose-no-margin` |
| | 1276–1292 | `.fd-scroll-container::-webkit-scrollbar*` |
| | 3935–4088 | the `.shiki` rules from `fumadocs-ui/css/lib/shiki.css` (unlayered) |
| `layout.css` | 434–3892 (per rule in the comment) | `@layer components`: grid, header, section tabs, sidebar, article frame, TOC column, TOC popover frame, start page scaffold |
| `components.css` | 434–3892 (per rule in the comment) | `@layer components`: buttons, search trigger, theme toggle, section switcher, breadcrumbs, footer navigation, TOC entries, headings, callout, cards, images, tables, hero and section cards |
| | 3884–3890 | `@layer utilities`: `button[data-search-full]`, `figure.shiki` |
| | 3928–3933 | unlayered: `.fd-page-tree-item-name` |
| `catalogue.css` | 434–3892 (per rule in the comment) | `@layer components`: tabs, code tabs, accordion, file tree, type table, banner, inline table of contents, code block shell; image zoom unlayered |
| `utilities.css` | 444, 1310 | `@layer utilities`: `.nd-hidden`, `.nd-invisible`; plus `:is(.nd-page > *)` and `:is(.nd-page-full > *)` from line 3017 |
| `notebook.css` | — | entry file, `@import` in cascade order; the build joins the parts into one file for delivery |

## Not taken over

The rest of the `@layer utilities` block (lines 434–3892, the Tailwind
utilities) isn't copied; it is translated element by element into `nd-*`
rules (see below).

`--fd-layout-width` is **not** defined globally in the reference. Only the
start page sets it through a utility to `1400px` (line 2953); the notebook
layout uses the fallback `var(--fd-layout-width, 97rem)`. That is why it
appears in no token block.

## Checks

    node verify/css-values.mjs --reference <reference.css> --palette <palette.css>

The script reads the reference stylesheet, collects all custom properties on
the token selectors (`:root`, `:root, :host`, `.dark`, `#nd-sidebar`,
`.dark #nd-sidebar`, `[dir="rtl"]`, `*, ::before, ::after, ::backdrop`), all
`@property` registrations and all `@keyframes`, and compares them with
`tokens.css` (palette inserted at the marker) and `animations.css`. The final
value is the last declaration in document order, so the `@supports`
follow-ups count correctly. It also compares the layer order of the reference
(order of first appearance) with the `@layer` statement in `notebook.css`, and
every rule `prose.css` takes over, by selector **and** at-rule context. The
last point isn't a luxury: if a closing brace goes missing while copying a
line range, the file stays syntactically valid, but the rules slide into an
`@media` or `@supports` block and only apply sometimes.

Expected output with the reference build's palette: 184 tokens, 66
`@property`, 9 `@keyframes`,
`@layer: properties, theme, base, components, utilities`, 112 prose rules,
0 differences.

    node verify/css-values.mjs --compare <delivered notebook.css> --palette <palette.css>

compares a delivered `notebook.css` rule line by rule line with `theme/css`
joined the way the build joins it, ignoring comments and blank lines.

## Two traps when updating the line ranges

Both have happened before and are now caught by `css-values.mjs`:

1. The `@media (min-width: 40rem)` block of `.fd-steps` ends at line **485**,
   not 484. With 451–484 all 76 `.prose` rules sat inside the media block and
   didn't apply below 640 px at all.
2. The `.shiki` rules start at line **3935**, not 3932. Up to 3933 there is
   still `.fd-page-tree-item-name`, which belongs in `components.css`; with
   3932 its closing brace moved into the file and arithmetically made up for
   the missing brace from point 1, so a plain brace count considered both
   mistakes fine.

## Component classes instead of utilities

`layout.css` and `components.css` aren't a copy of a range of the reference
stylesheet but a re-sorting of it: every element of the reference carries a
list of utility classes, in this theme one or more `nd-*` classes. Which list
maps to which classes is recorded in `class-map.json` in the verify tooling
(159 entries, 160 `nd-` classes); the golden DOM comparison uses this table to
check that every element carries exactly the intended classes.

Every rule group names in its comment the utilities it comes from, for
example

    /* .nd-page-desc  <-  mb-8 text-fd-muted-foreground text-lg */

The declarations below are unchanged the ones Tailwind generated for exactly
these utilities, in the order of their line numbers in the reference
stylesheet. The cascade within an element thus stays the same as in the
reference. The quirks of the output come along too: the
`@supports (color: color-mix(in lab, …))` pairs with a hex value before them,
the `@media (hover: hover)` wrappers of the `hover:` variants, the `rtl:`
selectors and the `:is(#nd-notebook-layout:has(…), #nd-home-layout:has(…))`
form of the `layout:` variant (the selectors of the three layouts that don't
exist here are dropped).

### Names

`nd-<component>` for the root, `nd-<component>-<part>` for the parts. The
prefixes are `nd-layout`, `nd-header`, `nd-nav`, `nd-sidebar`, `nd-scroll`,
`nd-toc`, `nd-tocpop`, `nd-main`, `nd-page`, `nd-prose`, `nd-crumb`,
`nd-pager`, `nd-btn`, `nd-search`, `nd-theme`, `nd-tab`, `nd-tabsdrop`,
`nd-heading`, `nd-callout`, `nd-card`, `nd-cards`, `nd-figure`, `nd-image`,
`nd-table`, `nd-dialog`, `nd-home`, `nd-hero`, `nd-strong`.

States live on the `data-`/`aria-` attributes and pseudo-classes that the
reference already puts on the element (`[data-active="true"]`,
`[data-popup-open]`, `[data-transparent="false"]`, `:hover`, `:empty`,
`:first-child`). Three places are emitted as a class by the reference and as
something else by this theme:

| Reference | Theme | Reason |
| --- | --- | --- |
| `-rotate-90` on the folder chevron | `:not([data-panel-open]) > .nd-sidebar-chevron` | the trigger carries the state anyway |
| `pt-0` / `pb-0` on the first and last TOC entry | `.nd-toc-item:first-of-type` / `:last-of-type` | js/toc.js puts the thumb track (`div`) as the first child in front of the entries (`a`); `:first-child` would no longer apply after that |
| `prose` on the TOC entry | five declarations directly in `.nd-toc-item` | prose.css lives in `utilities` and would otherwise beat the component layer |

Two places are distinguished by the reference only through the class list,
without an attribute; there the theme has a second component class:
`nd-tab-active` for the active section tab and `nd-card-link` for a card with
a target.

### Why `@layer components`

The reference's utilities live in `utilities`, so above the `.prose` rules,
but only those that come after them in the stylesheet (from line 1212). The
ones before (margins, position, visibility, lines 434–776) lose against
`.prose`. The theme reproduces that with the layer: everything that must lose
against prose.css lives in `components`. That affects `my-6` on screenshot and
image, which `.prose :where(figure)` and `.prose :where(img)` with
`margin: 2em` beat, exactly as in the reference. The two rules that must win
against prose.css the other way round live in `utilities.css`, because only
that file is included after prose.css: the width of the article children.

### States that only JavaScript establishes

At the end of the sidebar section, `layout.css` contains the rules for the
collapsed and the hovered sidebar. They hang on `data-collapsed`,
`data-hovered` and `data-column-changed`, not on classes JavaScript rewrites.
`js/sidebar.js` still builds the class list itself today; those tables can be
dropped from it. The same goes for `.nd-sidebar-hoverzone`.
