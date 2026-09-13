<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';

/**
 * The section tabs in the second header row (`LayoutHeaderTabs` from
 * `layouts/notebook/index.js`, mode `tabMode: navbar`).
 *
 * One tab per `root: true` folder; `Tree::tabsFor()` computes which one is
 * active and where it points (the current page's projection, otherwise the
 * folder's index). The row is omitted when there are no tabs, which is the case
 * on the documentation root page.
 *
 * @param list<array{title:string,url:string}> $tabs
 */
function nd_layout_tabs(array $tabs, int $selected): string
{
    if ($tabs === []) {
        return '';
    }

    $links = '';
    foreach ($tabs as $i => $tab) {
        $active = $i === $selected;
        $links .= Html::tag('a', [
            'class' => ['nd-tab', $active ? 'nd-tab-active' : null],
            'href' => $tab['url'],
        ], Html::e($tab['title']));
    }

    return Html::tag('div', [
        'class' => 'nd-tabs',
        'data-header-tabs' => true,
    ], $links);
}
