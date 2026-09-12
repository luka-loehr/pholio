# `verify/` — proving the rebuild is identical

Empty on purpose. This is the only place in the repository where Node is
allowed, because nothing here is ever shipped: it is development tooling that
compares a Pholio build against the reference implementation.

| Path | What it does |
| --- | --- |
| `verify/export-reference.mjs` | Freezes the reference: server-rendered and hydrated HTML per page, table of contents, search answers, icons, fonts, compiled CSS, page tree |
| `verify/golden-dom.mjs` | Element tree and attribute diff per page, with a documented allow-list for unavoidable differences such as hydration ids |
| `verify/computed-style.mjs` | About 70 CSS properties per element, at three widths, light and dark, including hover and focus states |
| `verify/pixels.mjs` | Full-page screenshot diff, tolerance zero outside text antialiasing |
| `verify/behaviour.mjs` | Keyboard, focus order, scroll lock, click-outside, animation keyframes via `getAnimations()` |
| `verify/queries.json` | The fixed question set the search comparison must answer identically |
| `verify/class-map.json` | Utility class to `nd-*` component class translation, so the DOM diff keeps working after the CSS is rewritten |

All four stages must be green. A stage reports a diff, never a score.
