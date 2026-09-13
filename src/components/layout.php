<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';

/**
 * The grid of the docs layout for
 * `nav.mode: top`.
 *
 * Three rows (header, TOC popover, main area with table of contents) and five
 * columns. The template is an inline `style`, because Tailwind can't express
 * it as a utility and `js/sidebar.js` changes the same variables.
 * `data-column-changed` enables the column width transition only while the
 * sidebar collapses or expands.
 */
function nd_layout(string $children): string
{
    $gridTemplate = '". header header header ." '
        . '"sidebar sidebar toc-popover toc-popover ." '
        . '"sidebar sidebar main toc ." 1fr '
        . '/ minmax(min-content, 1fr) var(--fd-sidebar-col) '
        . 'minmax(0, calc(var(--fd-layout-width,97rem) - var(--fd-sidebar-col) - var(--fd-toc-width))) '
        . 'var(--fd-toc-width) minmax(min-content, 1fr)';

    return Html::tag('div', [
        'id' => 'nd-notebook-layout',
        'data-sidebar-collapsed' => 'false',
        'data-column-changed' => 'false',
        'class' => 'nd-layout',
        'style' => [
            '--fd-docs-row-1' => 'var(--fd-banner-height, 0px)',
            '--fd-docs-row-2' => 'calc(var(--fd-docs-row-1) + var(--fd-header-height))',
            '--fd-docs-row-3' => 'calc(var(--fd-docs-row-2) + var(--fd-toc-popover-height))',
            '--fd-sidebar-col' => 'var(--fd-sidebar-width)',
            'grid-template' => $gridTemplate,
        ],
    ], $children);
}
