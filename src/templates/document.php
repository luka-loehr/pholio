<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';

/**
 * The <html> shell of every page.
 *
 * Order in <head> as in the reference: charset, viewport, the scrollbar style
 * that Base UI inserts (only on pages with a ScrollArea, which means every docs
 * page), then the description. The golden DOM comparison removes `<title>`,
 * `<script>`, stylesheets and `<meta>` other than charset/viewport/description;
 * they are here anyway because they belong to the page. Favicons and the
 * manifest are `<link>`s the reference doesn't have; a site that configures
 * them lists them in its own golden DOM allow file next to
 * verify/allow/golden-dom.json.
 *
 * No inline script (Content Security Policy without 'unsafe-inline'):
 * - `theme-init.js` in <head>, classic and synchronous, sets the class
 *   `light`/`dark` and `color-scheme` on <html> before the first paint (the
 *   next-themes bootstrap script in the reference). The static initial state
 *   (light) is on <html>, so the page stays usable without JavaScript.
 * - `sidebar-restore.js` is placed by components/sidebar.php after the drawer.
 * - `notebook.js` as a module at the end of <body>.
 *
 * @param array{
 *   lang:string, preset:?string, fontClass:string, title:string, description:?string,
 *   scrollArea:bool, assetBase:string, baseUrl:string, searchIndexUrl:string,
 *   icons:list<array{rel:string, type:?string, sizes:?string, href:string}>,
 *   manifest:?string, themeColor:?string, markdownUrl?:?string, robots?:?string, jsonLd?:?string
 * } $head `preset` null omits `data-preset`; `icons`, `manifest` and `themeColor`
 *   come from the `head` config and are emitted in this order after the search index,
 *   followed by the agent additions (lib/AgentSite.php): the alternate link to the
 *   page's Markdown twin, `<meta name="robots">` and the JSON-LD block.
 */

/** The style Base UI puts into <head> when a ScrollArea first renders. */
const ND_SCROLLBAR_STYLE = '.base-ui-disable-scrollbar{scrollbar-width:none}'
    . '.base-ui-disable-scrollbar::-webkit-scrollbar{display:none}';

function nd_document(array $head, string $body): string
{
    $assets = rtrim($head['assetBase'], '/');

    $out = '<!DOCTYPE html>';
    $out .= '<html' . Html::attrs([
        'lang' => $head['lang'],
        'data-preset' => $head['preset'] ?? null,
        // theme-init.js sets the class `light` and `color-scheme` at runtime.
        'class' => [$head['fontClass'], 'light'],
        // A finished string instead of Html::style(): in the reference the theme
        // script writes this value through the CSSOM (`style.colorScheme`), and the
        // browser serialises it as `color-scheme: light;` with a space and a
        // semicolon. Style attributes rendered by React are compact (`prop:value`)
        // and stay with the shared serialiser.
        'style' => 'color-scheme: light;',
    ]) . '>';

    $out .= '<head>';
    $out .= '<meta charset="utf-8">';
    $out .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
    if ($head['scrollArea']) {
        $out .= '<style>' . ND_SCROLLBAR_STYLE . '</style>';
    }
    if ($head['description'] !== null && $head['description'] !== '') {
        $out .= '<meta name="description" content="' . Html::e($head['description']) . '">';
    }
    $out .= '<title>' . Html::e($head['title']) . '</title>';
    // Before the first paint: theme class on <html>.
    $out .= '<script src="' . Html::e($assets . '/js/theme-init.js') . '"></script>';
    // The theme as one file: the builder concatenates theme/css/*.css in the
    // order of notebook.css and places the result here.
    $out .= '<link rel="stylesheet" href="' . Html::e($assets . '/css/notebook.css') . '">';
    // Where js/search-dialog.js fetches the search index.
    $out .= '<meta name="nd-search-index" content="' . Html::e($head['searchIndexUrl']) . '">';
    foreach ($head['icons'] as $icon) {
        $out .= Html::voidTag('link', [
            'rel' => $icon['rel'],
            'type' => $icon['type'] ?? null,
            'sizes' => $icon['sizes'] ?? null,
            'href' => $icon['href'],
        ]);
    }
    if ($head['manifest'] !== null) {
        $out .= Html::voidTag('link', ['rel' => 'manifest', 'href' => $head['manifest']]);
    }
    if ($head['themeColor'] !== null) {
        $out .= Html::voidTag('meta', ['name' => 'theme-color', 'content' => $head['themeColor']]);
    }
    if (($head['markdownUrl'] ?? null) !== null) {
        $out .= Html::voidTag('link', ['rel' => 'alternate', 'type' => 'text/markdown', 'href' => $head['markdownUrl']]);
    }
    if (($head['robots'] ?? null) !== null) {
        $out .= Html::voidTag('meta', ['name' => 'robots', 'content' => $head['robots']]);
    }
    if (($head['jsonLd'] ?? null) !== null) {
        // A data block, not a script: the Content-Security-Policy's script-src does not apply.
        $out .= '<script type="application/ld+json">' . $head['jsonLd'] . '</script>';
    }
    $out .= '</head>';

    $out .= '<body class="nd-body">';
    // Base UI puts this placeholder for its portals at the start of the body.
    $out .= '<div hidden=""></div>';
    $out .= $body;
    $out .= '<script type="module" src="' . Html::e($assets . '/js/notebook.js') . '"></script>';
    $out .= '</body></html>';

    return $out;
}
