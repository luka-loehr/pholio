# Class map of the JS modules: utility classes → nd classes

The reference build styles its DOM with Tailwind utility classes. The generator
emits `nd-*` classes instead (`verify/class-map.json`), with the rules in
`theme/css/layout.css`, `components.css` and `utilities.css`. This file records,
per module in `theme/js/`, which `nd-*` class, attribute or selector replaces the
utility classes the reference toggles or queries.

Ground rules:

- **State through attributes, not classes.** Where the reference sets an attribute
  (`data-open`, `data-closed`, `data-state`, `data-panel-open`, `data-popup-open`,
  `data-hovering`, `data-collapsed`, `data-hovered`, `data-column-changed`,
  `aria-selected`), the CSS rule hooks onto it. The module only sets the attribute and
  leaves the class list alone.
- **A second component class only where the reference distinguishes the state by the
  class list alone** (no attribute exists). Then the module toggles exactly that one
  `nd-` class.
- **Visibility switches:** `invisible` → `nd-invisible`, `hidden` → `nd-hidden`
  (both in `utilities.css`, layer `utilities`, beating every component rule).
- **Elements a module creates itself** get the `nd-` class named below instead of
  the utility list. Their CSS lives in `layout.css`/`components.css`.
- **Selectors** point at `nd-` classes, attributes or ids, never at utility classes.

Column "reference" = utility tokens or selector as in the reference build; column
"port" = what the module does instead.

## Selectors that target utility classes in the reference

| Module | Reference | Port |
| --- | --- | --- |
| `copy.js` | `root.querySelectorAll('.group\\/heading')` | `root.querySelectorAll('.nd-heading')` |
| `notebook.js` | `document.querySelectorAll('header a[class*="border-b-2"]')` | `document.querySelectorAll('header a.nd-tab')` (only relevant without `<template data-nd-tabs-popup>`) |
| `search-dialog.js` | `document.querySelector('div[role="presentation"][class*="backdrop-blur-xs"]')` | `document.querySelector('div[role="presentation"].nd-dialog-backdrop')` |

## collapsible.js

| Place | Reference | Port |
| --- | --- | --- |
| `CLASSES.chevronClosed` | toggle `-rotate-90`, `rtl:rotate-90` on the folder chevron | **none.** `components` rule `:not([data-panel-open]) > .nd-sidebar-chevron { rotate: -90deg }` (+ RTL). The module only sets `data-panel-open` on the trigger. |
| Panel from `<template data-collapsible-panel>` | classes come from the template | the template carries `nd-sidebar-panel` (+ `nd-sidebar-panel-line` at folder depth 1) or `nd-collapsible-panel`; nothing to do |

## sidebar.js

| Place | Reference | Port |
| --- | --- | --- |
| `asideBaseOpen`, `asideBaseCollapsed`, `aside.className = …` | `absolute flex flex-col w-full start-0 inset-y-0 items-end text-sm duration-250 *:w-(--fd-sidebar-width)` or without `w-full inset-y-0` | **Don't write the class list**; it stays `nd-sidebar`. Only set `data-collapsed`, `data-hovered`, `data-collapse-transition` on the `aside` and `data-column-changed` on the layout. |
| `asideCollapsedHead` + `asideCollapsedTail` | `inset-y-2 rounded-xl bg-fd-card`, `border w-(--fd-sidebar-width)` | `#nd-sidebar[data-collapsed="true"]` (layout.css) |
| `asideTransitionTransform` | `transition-transform` | `#nd-sidebar[data-collapsed="true"]:not([data-collapse-transition])` |
| `asideChanged` | `transition-[width,inset-block,translate,background-color]` | `#nd-sidebar[data-collapse-transition]`; sidebar.js sets the attribute when `data-collapsed` flips and removes it on the next state change (same lifetime as the reference class). `data-column-changed` only drives `transition-[grid-template-columns]` on the layout. |
| `asideHovered` | `shadow-lg translate-x-2 rtl:-translate-x-2` | `#nd-sidebar[data-collapsed="true"][data-hovered="true"]` |
| `asideAway` | `-translate-x-(--fd-sidebar-width) rtl:translate-x-full` | `#nd-sidebar[data-collapsed="true"]:not([data-hovered="true"])` |
| `hoverZone` | `absolute start-0 inset-y-0 w-4` | `zone.className = 'nd-sidebar-hoverzone'` |
| `drawerInvisible` | prepend `invisible` to the drawer classes, strip it again by regex | `drawer.classList.toggle('nd-invisible', hidden)` |
| Drawer classes (generator markup) | `fixed text-[0.9375rem] flex flex-col shadow-lg border-s end-0 inset-y-0 w-[85%] max-w-[380px] z-40 bg-fd-background data-[state=open]:animate-fd-sidebar-in data-[state=closed]:animate-fd-sidebar-out` | `nd-drawer`; the animation hangs on `data-state` |
| Overlay from `<template data-sidebar-overlay>` | `fixed z-40 inset-0 backdrop-blur-xs data-[state=open]:animate-fd-fade-in data-[state=closed]:animate-fd-fade-out` | `nd-drawer-overlay`; comes from the template, nothing to do |
| Drawer head, close button, foot | `flex flex-col gap-3 p-4 pb-2 empty:hidden`; button `… p-1.5 [&_svg]:size-4.5 ms-auto text-fd-muted-foreground`; foot `flex-row text-fd-muted-foreground items-center border-t p-4 pt-2 justify-end flex` | `nd-sidebar-head`; `nd-btn nd-btn-ghost nd-drawer-close`; `nd-drawer-foot` (all from the generator) |
| Other selectors | attributes and ids | unchanged |
| Ids in the drawer | reference: hydration ids (`_r_8_`, `_r_9_`, `_r_d_`) | generator: hydration ids from the page counter as well (`base-ui-_R_N_`); references via `aria-controls` and `[data-id$="-viewport"]` |
| Folder key | – | every folder wrapper (`div[data-open]`/`div[data-closed]`, also in templates and in the drawer) carries `data-folder-id` = path in the page tree (`guides/setup`) |

## scroll-area.js

| Place | Reference | Port |
| --- | --- | --- |
| `scrollbarBase` + `scrollbarVertical` | `flex select-none transition-opacity`, `h-full w-1.5` | `scrollbar.className = 'nd-scroll-bar'` (once, on creation) |
| `scrollbarHidden` | `opacity-0` while not hovered | **none.** `.nd-scroll-bar:not([data-hovering]) { opacity: 0 }`; the module already sets `data-hovering` |
| `thumb` | `relative flex-1 rounded-full bg-fd-border` | `thumb.className = 'nd-scroll-thumb'` |
| Selectors | `[data-id$="-viewport"]`, `:hover`, ids | unchanged |

## toc.js

| Place | Reference | Port |
| --- | --- | --- |
| `thumbTrack` | `absolute top-0 inset-s-0 origin-center rtl:-scale-x-100` | `wrapper.className = 'nd-toc-thumb'` |
| `thumbSvg` | `absolute transition-[clip-path]` | `svg.setAttribute('class', 'nd-toc-thumb-svg')` |
| `thumbPath` | `stroke-fd-primary` | `path.setAttribute('class', 'nd-toc-thumb-path')` |
| Selectors | attributes, ids, structure | unchanged (`[data-toc-popover-content]` carries the panel template) |

## toc-popover.js

Open/closed is `data-open` / `data-closed` on `[data-toc-popover]` (set by collapsible.js as in Base UI).

| Place | Reference | Port |
| --- | --- | --- |
| `headerOpen` | `shadow-lg` on `header` | **none.** `[data-toc-popover][data-open] .nd-tocpop-header` |
| `headerBackground` | `bg-fd-background/80` | **none**, already part of `nd-tocpop-header` |
| `progressOpen` | `text-fd-primary` on the progress circle | **none.** `[data-toc-popover][data-open] .nd-tocpop-progress` |
| `chevronOpen` | `rotate-180` | **none.** `[data-toc-popover][data-open] .nd-tocpop-chevron` |
| `titleOpen` | `text-fd-foreground` on the page title | **none.** `[data-toc-popover][data-open] .nd-tocpop-label-page` |
| `titleHidden` | `opacity-0 -translate-y-full pointer-events-none` on the page title | `titleSpan.classList.toggle('nd-tocpop-label-up', showItem)` (no attribute in the reference) |
| `headingHidden` | `opacity-0 translate-y-full pointer-events-none` on the active heading | `headingSpan.classList.toggle('nd-tocpop-label-down', !showItem)` |
| Selectors | structure, attributes, ids | unchanged |

Generator initial state: page title `nd-tocpop-label-page nd-tocpop-label-up`, active heading `nd-tocpop-label-active`.

## theme.js

| Place | Reference | Port |
| --- | --- | --- |
| icon state | toggle `bg-fd-accent`, `text-fd-accent-foreground`, `text-fd-muted-foreground` on the icons | `svgIcon.classList.toggle('nd-theme-icon-active', active)`; the base class `nd-theme-icon` stays (no attribute in the reference) |
| theme | `light` / `dark` on `<html>` | unchanged (functional class) |
| Selectors | `[data-theme-toggle]` | unchanged |

## dialog.js

No classes. Only sets `data-open`, `data-closed`, `data-starting-style`, `data-ending-style`; the animations of the `nd-dialog` and `nd-dialog-backdrop` rules hang on them. Nothing to do.

## search-dialog.js

| Place | Reference | Port |
| --- | --- | --- |
| backdrop selector | see above | `div[role="presentation"].nd-dialog-backdrop` |
| backdrop fallback | `fixed inset-0 z-50 backdrop-blur-xs bg-fd-overlay data-open:animate-fd-fade-in data-closed:animate-fd-fade-out` | `nd-dialog-backdrop` |
| footer fallback | `bg-fd-secondary/50 p-3 empty:hidden` | `nd-dialog-footer` |
| `POPUP_CLASS` | `fixed left-1/2 top-4 md:top-[calc(50%-250px)] z-50 w-[calc(100%-1rem)] max-w-screen-sm -translate-x-1/2 rounded-xl border bg-fd-popover text-fd-popover-foreground shadow-2xl overflow-hidden data-closed:animate-fd-dialog-out data-open:animate-fd-dialog-in focus-visible:outline-none *:border-b *:has-[+:last-child[data-empty=true]]:border-b-0 *:data-[empty=true]:border-b-0 *:last:border-b-0` | `nd-dialog` |
| title | `hidden` | `nd-hidden` |
| header row | `flex flex-row items-center gap-2 p-3` | `nd-search-head` |
| magnifier | `size-5 text-fd-muted-foreground` | `nd-search-head-icon` |
| input | `w-0 flex-1 bg-transparent text-lg placeholder:text-fd-muted-foreground focus-visible:outline-none` | `nd-search-input` |
| `CLOSE_BUTTON_CLASS` | buttonVariants base + `border gap-1 px-2 py-1.5 text-xs font-mono text-fd-muted-foreground` | `nd-btn nd-search-close` |
| loading state | toggle `animate-pulse`, `duration-400` on the magnifier | `searchIcon.classList.toggle('nd-search-loading', on)` (no attribute in the reference) |
| results wrapper | `overflow-hidden h-(--fd-animated-height) transition-[height]` | `nd-search-results` (`data-empty` stays) |
| `VIEWPORT_CLASS` / `VIEWPORT_CLASS_EMPTY` | `w-full flex flex-col overflow-y-auto max-h-[460px] p-1`, or without `flex` and with `hidden` | `nd-search-list`; empty: `viewport.classList.toggle('nd-hidden', items === null)` |
| result button | `relative select-none shrink-0 px-2.5 py-2 text-start text-sm overflow-hidden rounded-lg` | `nd-search-item` |
| active result | toggle `bg-fd-accent`, `text-fd-accent-foreground` | **none.** `.nd-search-item[aria-selected="true"]`; `aria-selected` is already set |
| breadcrumbs | `inline-flex items-center text-fd-muted-foreground text-xs empty:hidden` | `nd-search-crumbs` |
| breadcrumb separator | `ChevronRight` with `size-4 rtl:rotate-180` | `lucide lucide-chevron-right nd-search-crumb-sep` |
| vertical line | `absolute inset-s-3 inset-y-0 w-px bg-fd-border` | `nd-search-item-line` |
| hash | `absolute inset-s-6 top-2.5 size-4 text-fd-muted-foreground` | `nd-search-item-hash` |
| content | `min-w-0` + `font-medium` (page) / `ps-8 font-medium` (heading) / `ps-4 text-fd-popover-foreground/80` (text) | `nd-search-item-body` + `nd-search-item-page` / `nd-search-item-heading` / `nd-search-item-text` |
| paragraph | `min-w-0` | `nd-search-md-p` |
| match mark | `text-fd-primary underline` | `nd-search-mark` |
| bold | `text-fd-accent-foreground font-medium` | `nd-search-strong` |
| code | `border rounded-md px-px bg-fd-secondary text-fd-secondary-foreground` | `nd-search-code` |
| component tag | wrapper `inline-flex max-w-full items-center border p-0.5 rounded-md bg-fd-card text-fd-card-foreground divide-x divide-fd-border`; name `rounded-sm px-0.5 me-1 bg-fd-primary font-medium text-xs text-fd-primary-foreground border-none` | `nd-search-tag`; `nd-search-tag-name` |
| tag field | `truncate text-xs text-fd-muted-foreground px-1`; key `text-fd-card-foreground` | `nd-search-tag-field`; `nd-search-tag-key` |
| tag children | `ps-1` | `nd-search-tag-children` |
| no results | `py-12 text-center text-sm text-fd-muted-foreground` | `nd-search-empty` |
| Selectors | `svg.lucide-search`, attributes, ids | unchanged |

## popover.js

| Place | Reference | Port |
| --- | --- | --- |
| `positionerClass` (default) | `z-50` | default `'nd-popover-positioner'` |
| `popupClass` | from the caller | see notebook.js |
| States | `data-popup-open`, `data-open`, `data-closed`, `data-side`, `data-align`, `data-starting-style` | unchanged; the `nd-popover` animation hangs on `data-open`/`data-closed` |

## page-actions.js

`MarkdownCopyButton` and `ViewOptionsPopover` from the reference UI's
`layouts/shared/page-actions.js`, in the row of the docs template. The class lists are
in `class-map.json`; the rules in `theme/css/components.css`.

| Place | Reference | Port |
| --- | --- | --- |
| Row | `flex flex-row gap-2 items-center border-b pb-6` | `nd-page-actions`, plus `flex-wrap -mt-4 mb-8` because the description above keeps `mb-8` |
| Copy button | `buttonVariants({ color: 'secondary', size: 'sm' })` + `gap-2 [&_svg]:size-3.5 [&_svg]:text-fd-muted-foreground` | `nd-btn nd-btn-secondary nd-btn-text-sm nd-page-copy` |
| Open button | `buttonVariants({ color: 'secondary', size: 'sm' })` + `gap-2 data-[popup-open]:bg-fd-accent data-[popup-open]:text-fd-accent-foreground` | `nd-btn nd-btn-secondary nd-btn-text-sm nd-page-open`; `data-popup-open` is set by popover.js |
| Checked icon | React state swaps `Copy` for `Check` for 1500 ms (`useCopyButton`) | both icons in the markup, `data-checked` on the button for 1500 ms, CSS shows `nd-page-copy-done` |
| `popupClass` | `PopoverContent` with `flex flex-col` | `'nd-popover nd-page-actions-popup'` |
| Menu item | `text-sm p-2 rounded-lg inline-flex items-center gap-2 hover:text-fd-accent-foreground hover:bg-fd-accent [&_svg]:size-4` | `nd-page-actions-item` |
| Not in the reference | | Copy llms.txt URL item; ArrowDown, ArrowUp, Home and End between items |

## notebook.js

| Place | Reference | Port |
| --- | --- | --- |
| `POPUP_CLASS` | `z-50 origin-(--transform-origin) overflow-y-auto max-h-(--available-height) min-w-[240px] max-w-[98vw] rounded-xl border bg-fd-popover/60 backdrop-blur-lg text-sm text-fd-popover-foreground shadow-lg focus-visible:outline-none data-closed:animate-fd-popover-out data-open:animate-fd-popover-in flex flex-col gap-1 w-(--anchor-width) p-1 fd-scroll-container` | `'nd-popover nd-tabsdrop-popup fd-scroll-container'` |
| tab selector | see above | `header a.nd-tab` |
| option | `flex items-center gap-2 rounded-lg p-1.5 hover:bg-fd-accent hover:text-fd-accent-foreground` | `nd-tabsdrop-option` |
| icon box | `shrink-0 size-9 md:mb-auto md:size-5 empty:hidden` (+ inside `size-full [&_svg]:size-full max-md:p-1.5 max-md:rounded-md max-md:border max-md:bg-fd-secondary`) | `nd-tabsdrop-option-iconbox` (+ inside `nd-tabsdrop-iconinner`) |
| title | `text-sm font-medium leading-none` | `nd-tabsdrop-option-title` |
| description | `text-[0.8125rem] text-fd-muted-foreground mt-1 empty:hidden` | `nd-tabsdrop-option-desc` |
| check mark | `shrink-0 ms-auto size-3.5 text-fd-primary`, inactive additionally `invisible` | `nd-tabsdrop-check`, inactive additionally `nd-invisible` |
| template | `template[data-nd-tabs-popup]` | unchanged; the generator writes it with exactly these nd classes |

## copy.js

| Place | Reference | Port |
| --- | --- | --- |
| heading selector | `.group\\/heading` | `.nd-heading` |
| icon swap | `icon('copy-check', '')`, `icon('link', '')` without extra classes | unchanged (size comes from `nd-btn-icon-xs` on the button) |
| code buttons | `button[data-copy-code]`, `figure, div` | unchanged |

## hotkeys.js, util.js, theme-init.js, sidebar-restore.js

No utility classes. `util.icon()` only adds the classes the caller passes.

## nd classes introduced for the modules

| Class | File | Utilities / rule |
| --- | --- | --- |
| `nd-drawer` | layout.css | `fixed text-[0.9375rem] flex flex-col shadow-lg border-s end-0 inset-y-0 w-[85%] max-w-[380px] z-40 bg-fd-background data-[state=open]:animate-fd-sidebar-in data-[state=closed]:animate-fd-sidebar-out` |
| `nd-drawer-overlay` | layout.css | `fixed z-40 inset-0 backdrop-blur-xs data-[state=open]:animate-fd-fade-in data-[state=closed]:animate-fd-fade-out` |
| `nd-drawer-close` | layout.css | `rounded-md p-1.5 [&_svg]:size-4.5 ms-auto text-fd-muted-foreground` (with `nd-btn nd-btn-ghost`) |
| `nd-drawer-foot` | layout.css | `flex flex-row items-center justify-end border-t p-4 pt-2 text-fd-muted-foreground` |
| `nd-tabsdrop-navbar` | components.css | `lg:hidden` (split off the section switcher; absent in the drawer) |
| `nd-scroll-bar`, `nd-scroll-thumb` | layout.css | see scroll-area.js; `.nd-scroll-bar:not([data-hovering])` → `opacity: 0` |
| `nd-toc-thumb`, `nd-toc-thumb-svg`, `nd-toc-thumb-path` | components.css | see toc.js |
| `nd-collapsible-panel`, `nd-tocpop-content` | layout.css | collapsible panel; `flex flex-col px-4 max-h-[50vh] md:px-6` |
| `nd-tocpop-label-up`, `nd-tocpop-label-down` | components.css | `-translate-y-full` or `translate-y-full`, each with `opacity-0 pointer-events-none` |
| `[data-toc-popover][data-open] …` | components.css | `shadow-lg` (header), `text-fd-primary` (circle), `rotate-180` (chevron), `text-fd-foreground` (page title) |
| `nd-popover-positioner`, `nd-popover` | components.css | see popover.js / notebook.js |
| `nd-tabsdrop-popup`, `nd-tabsdrop-option`, `-option-iconbox`, `-option-title`, `-option-desc`, `nd-tabsdrop-check` | components.css | see notebook.js |
| `nd-dialog`, `nd-search-*` | components.css | see search-dialog.js; `.nd-search-item[aria-selected="true"]` |

## Start page menu below lg (Collapsible in `header#nd-nav`)

| Place | Reference | Port |
| --- | --- | --- |
| Panel | mounted on opening | `<template data-collapsible-panel>` in `nav.nd-home-nav` directly after the row; inside `div#nd-home-menu-panel.nd-home-menu-panel[data-open]` > `div.nd-home-menu` > `a.nd-home-menu-link` (one per menu link, `data-active`) + `div.nd-home-menu-foot` > `div.nd-home-menu-sep[role=separator]` + theme switcher |
| Root open/closed | `header#nd-nav` `data-closed` → `data-open` | same; rule `#nd-nav[data-open] .nd-home-nav` (below `lg`: `shadow-lg`, `rounded-b-2xl`) |
| Trigger | `aria-expanded`, `data-panel-open`, `aria-controls` | same; `aria-controls="nd-home-menu-panel"`; the chevron rotates via `[data-panel-open] > .nd-home-menu-chevron` |
| Class toggles | `max-lg:shadow-lg max-lg:rounded-b-2xl` on `nav`, `rotate-180` on the chevron | **none**: only the attributes are set |
| Phases | `data-starting-style`, `data-ending-style`, `--collapsible-panel-height` | same (js/collapsible.js); CSS in `.nd-home-menu-panel` |

## Section switcher popup in the drawer

`<template data-nd-tabs-popup>` stands after **every** trigger, including the one in the drawer (`#nd-sidebar-mobile`). notebook.js uses the template directly next to each trigger.
