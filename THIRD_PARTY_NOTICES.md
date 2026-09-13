# Third-party notices

Pholio is MIT licensed (see [`LICENSE`](LICENSE)). The generator ships, or is
derived from, the third-party material listed here. Each entry names the exact
version, the licence, the upstream source and the verbatim licence text in
[`licenses/`](licenses/).

The original authors of this material are not the authors of Pholio. Their
copyright notices and licence terms apply to their work as set out below.

## Shipped verbatim

These files are copied into every generated site or into the generator
unchanged. Every generated site also receives the licence texts from
[`licenses/`](licenses/) as `<assets>/LICENSES/*.txt`.

| Component | Version | Licence | Source | Shipped as | Licence text |
| --- | --- | --- | --- | --- | --- |
| Lucide icons | `lucide-react` 1.45.0 | ISC; icons derived from Feather are MIT | https://github.com/lucide-icons/lucide | SVG path data of the complete icon set (1834 icons, 428 aliases) in `vendor-data/lucide/icons.json` with the licence alongside; the icons a page uses are inlined into its HTML | [`licenses/lucide-ISC.txt`](licenses/lucide-ISC.txt) |
| Inter | 4.001 (Google Fonts `v20`, git `66647c0bb`) | SIL Open Font License 1.1 | https://github.com/rsms/inter | Seven variable woff2 subsets, unmodified, renamed only | [`licenses/inter-OFL-1.1.txt`](licenses/inter-OFL-1.1.txt) |
| TextMate grammars | as bundled in `@shikijs/langs` 4.4.3 | see the grammar table below | https://github.com/shikijs/shiki | JSON grammars for syntax highlighting at build time | see below |
| TextMate themes | as bundled in `@shikijs/themes` 4.4.3 | MIT | https://github.com/shikijs/shiki | JSON colour themes for syntax highlighting | see below |

Lucide copyright: © 2026 Lucide Icons and Contributors; the icons listed in the
licence file are © 2013-present Cole Bemis (Feather). Inter copyright: © 2016
The Inter Project Authors. The Inter files are distributed unmodified under
their original name, as the OFL requires, and the licence accompanies every
copy.

### Grammars

The grammars are byte-for-byte what `@shikijs/langs` 4.4.3 ships. That release
was built from `tm-grammars` 1.32.3; Shiki's only change to the upstream JSON is
an added `aliases` list on `javascript`, `shellscript`, `typescript` and `yaml`.
The packaging in both `@shikijs/langs` and `tm-grammars` is MIT, © 2021 Pine Wu
and © 2023 Anthony Fu ([`licenses/shiki-MIT.txt`](licenses/shiki-MIT.txt)).

| Grammar | Upstream file (pinned commit) | Licence | Copyright | Licence text |
| --- | --- | --- | --- | --- |
| CSS | [microsoft/vscode `extensions/css/syntaxes/css.tmLanguage.json` @ `af60048`](https://github.com/microsoft/vscode/blob/af600487b1e94374d9f48f57cbf2cad24656b07f/extensions/css/syntaxes/css.tmLanguage.json) | MIT | © 2015-present Microsoft Corporation | [`licenses/vscode-MIT.txt`](licenses/vscode-MIT.txt) |
| Diff | [microsoft/vscode `extensions/diff/syntaxes/diff.tmLanguage.json` @ `4549bd2`](https://github.com/microsoft/vscode/blob/4549bd26c7b799284e0ebd8dc1e0310e6a8707a1/extensions/diff/syntaxes/diff.tmLanguage.json) | MIT | © 2015-present Microsoft Corporation | [`licenses/vscode-MIT.txt`](licenses/vscode-MIT.txt) |
| HTML | [microsoft/vscode `extensions/html/syntaxes/html.tmLanguage.json` @ `4532436`](https://github.com/microsoft/vscode/blob/45324363153075dab0482312ae24d8c068d81e4f/extensions/html/syntaxes/html.tmLanguage.json) | MIT | © 2015-present Microsoft Corporation | [`licenses/vscode-MIT.txt`](licenses/vscode-MIT.txt) |
| Java | [microsoft/vscode `extensions/java/syntaxes/java.tmLanguage.json` @ `3c86ede`](https://github.com/microsoft/vscode/blob/3c86ede5f554f6e196c832394e126b291a1de606/extensions/java/syntaxes/java.tmLanguage.json) | MIT | © 2015-present Microsoft Corporation | [`licenses/vscode-MIT.txt`](licenses/vscode-MIT.txt) |
| JavaScript | [microsoft/vscode `extensions/javascript/syntaxes/JavaScript.tmLanguage.json` @ `2105419`](https://github.com/microsoft/vscode/blob/210541906e5a96ab39f9c753f921b1bd35f4138b/extensions/javascript/syntaxes/JavaScript.tmLanguage.json) | MIT | © 2015-present Microsoft Corporation | [`licenses/vscode-MIT.txt`](licenses/vscode-MIT.txt) |
| JSON | [microsoft/vscode `extensions/json/syntaxes/JSON.tmLanguage.json` @ `d6af489`](https://github.com/microsoft/vscode/blob/d6af4893ed9a3545163a4cb748fa5548bd1e51a5/extensions/json/syntaxes/JSON.tmLanguage.json) | MIT | © 2015-present Microsoft Corporation | [`licenses/vscode-MIT.txt`](licenses/vscode-MIT.txt) |
| PHP | [microsoft/vscode `extensions/php/syntaxes/php.tmLanguage.json` @ `af60048`](https://github.com/microsoft/vscode/blob/af600487b1e94374d9f48f57cbf2cad24656b07f/extensions/php/syntaxes/php.tmLanguage.json) | MIT | © 2015-present Microsoft Corporation | [`licenses/vscode-MIT.txt`](licenses/vscode-MIT.txt) |
| Shell | [microsoft/vscode `extensions/shellscript/syntaxes/shell-unix-bash.tmLanguage.json` @ `9473445`](https://github.com/microsoft/vscode/blob/9473445f7d3dcb5c579f42ece8b6c18c43c63ed3/extensions/shellscript/syntaxes/shell-unix-bash.tmLanguage.json) | MIT | © 2015-present Microsoft Corporation | [`licenses/vscode-MIT.txt`](licenses/vscode-MIT.txt) |
| SQL | [microsoft/vscode `extensions/sql/syntaxes/sql.tmLanguage.json` @ `af60048`](https://github.com/microsoft/vscode/blob/af600487b1e94374d9f48f57cbf2cad24656b07f/extensions/sql/syntaxes/sql.tmLanguage.json) | MIT | © 2015-present Microsoft Corporation | [`licenses/vscode-MIT.txt`](licenses/vscode-MIT.txt) |
| TypeScript | [microsoft/vscode `extensions/typescript-basics/syntaxes/TypeScript.tmLanguage.json` @ `2105419`](https://github.com/microsoft/vscode/blob/210541906e5a96ab39f9c753f921b1bd35f4138b/extensions/typescript-basics/syntaxes/TypeScript.tmLanguage.json) | MIT | © 2015-present Microsoft Corporation | [`licenses/vscode-MIT.txt`](licenses/vscode-MIT.txt) |
| XML | [microsoft/vscode `extensions/xml/syntaxes/xml.tmLanguage.json` @ `10a1d2a`](https://github.com/microsoft/vscode/blob/10a1d2a50a2882f5ae85bdb51eb04d3064fb9de9/extensions/xml/syntaxes/xml.tmLanguage.json) | MIT | © 2015-present Microsoft Corporation | [`licenses/vscode-MIT.txt`](licenses/vscode-MIT.txt) |
| YAML | [textmate/yaml.tmbundle `Syntaxes/YAML.tmLanguage` @ `e54ceae`](https://github.com/textmate/yaml.tmbundle/blob/e54ceae3b719506dba7e481a77cea4a8b576ae46/Syntaxes/YAML.tmLanguage) | MIT | © 2015 FichteFoll | [`licenses/yaml-tmbundle-MIT.txt`](licenses/yaml-tmbundle-MIT.txt) |

The YAML grammar has no licence entry in the `tm-grammars` metadata or NOTICE.
Its licence comes from the upstream bundle itself: `Syntaxes/YAML-license.txt`
next to the grammar at the pinned commit, which the bundle's README declares
authoritative for that file.

### Themes

| Theme | Upstream file (pinned commit) | Licence | Copyright | Licence text |
| --- | --- | --- | --- | --- |
| GitHub Light | [primer/github-vscode-theme `src/theme.js` @ `f7a67d6`](https://github.com/primer/github-vscode-theme/blob/f7a67d67fc2302a0ec36ddfb7bdd57142f4575e8/src/theme.js) | MIT | © 2020 Primer | [`licenses/github-vscode-theme-MIT.txt`](licenses/github-vscode-theme-MIT.txt) |
| GitHub Dark | [primer/github-vscode-theme `src/theme.js` @ `f7a67d6`](https://github.com/primer/github-vscode-theme/blob/f7a67d67fc2302a0ec36ddfb7bdd57142f4575e8/src/theme.js) | MIT | © 2020 Primer | [`licenses/github-vscode-theme-MIT.txt`](licenses/github-vscode-theme-MIT.txt) |

Both themes are identical to `tm-themes` 1.12.3, which `@shikijs/themes` 4.4.3
bundles unchanged.

## Design origin and derived values

No code from these projects is shipped. Pholio reproduces their interface and
derives values from their compiled output, so their notices are carried here.

| Component | Version | Licence | Source | What is derived | Licence text |
| --- | --- | --- | --- | --- | --- |
| Fumadocs (`fumadocs-ui`, `fumadocs-core`, `@fumadocs/tailwind`) | 16.15.9, 16.15.9, 0.1.1 | MIT, © 2023 Fuma | https://github.com/fuma-nama/fumadocs | The Notebook theme's layout, component structure, class logic, CSS token values, keyframes, prose typography; page-tree, table-of-contents and search behaviour, ported to PHP and vanilla JavaScript | [`licenses/fumadocs-MIT.txt`](licenses/fumadocs-MIT.txt) |
| Base UI (`@base-ui/react`) | 1.8.0 | MIT, © 2019 Material-UI SAS | https://github.com/mui/base-ui | Behaviour and the names and order of state attributes of dialog, popover, collapsible, scroll area, tabs, accordion and navigation menu, reimplemented in vanilla JavaScript | [`licenses/base-ui-MIT.txt`](licenses/base-ui-MIT.txt) |
| Tailwind CSS (`tailwindcss`) | 4.3.3 | MIT, © Tailwind Labs, Inc. | https://github.com/tailwindlabs/tailwindcss | The preflight block, theme variables, `@property` registrations and layer order, taken from compiled output | [`licenses/tailwindcss-MIT.txt`](licenses/tailwindcss-MIT.txt) |
| zbsearch | 4.0.0 | Apache-2.0, © 2023 ZBSearchSearch Inc | https://github.com/micheleriva/zbsearch | Tokenisation, BM25 ranking parameters and result ordering, reimplemented in PHP and vanilla JavaScript | [`licenses/zbsearch-Apache-2.0.txt`](licenses/zbsearch-Apache-2.0.txt) |

**Notice of changes (Apache-2.0, section 4b).** Pholio contains no zbsearch
source files. Its search is an independent reimplementation in PHP and
JavaScript written to reproduce zbsearch's observable ranking behaviour.

## Not shipped

Development tooling in `verify/` uses Node and Playwright to compare builds
against a reference. It is never part of a generated site or of the generator,
and it installs its own dependencies with their own licences, pinned in
[`verify/package.json`](verify/package.json). `verify/tools/build-lucide-data.mjs`
reads the pinned `lucide-react` from there to regenerate `vendor-data/lucide/icons.json`.
