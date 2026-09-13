<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../I18n.php';
require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';

/**
 * The page footer navigation (`layouts/notebook/page/slots/footer.js`).
 *
 * The order is the flat page list of the current root folder
 * (`Tree::footerItems()`). The block stays in the DOM even without a previous
 * or next page; it is then empty and single-column.
 *
 * @param array{previous?:array{name:string,url:string,description:?string}, next?:array{name:string,url:string,description:?string}} $items
 */
function nd_footer(array $items): string
{
    $previous = $items['previous'] ?? null;
    $next = $items['next'] ?? null;

    $cards = '';
    if ($previous !== null) {
        $cards .= nd_footer_item($previous, 0);
    }
    if ($next !== null) {
        $cards .= nd_footer_item($next, 1);
    }

    return Html::tag('div', [
        'class' => ['nd-pager', $previous !== null && $next !== null ? null : 'nd-pager-single'],
    ], $cards);
}

/** @param array{name:string,url:string,description:?string} $item */
function nd_footer_item(array $item, int $index): string
{
    $icon = Icons::svg($index === 0 ? 'chevron-left' : 'chevron-right', 'nd-pager-icon');
    $head = Html::tag('div', [
        'class' => ['nd-pager-head', $index === 1 ? 'nd-pager-head-next' : null],
    ], $icon . Html::tag('p', [], Html::e($item['name'])));

    $description = $item['description']
        ?? I18n::t($index === 0 ? 'Previous Page(pagination)' : 'Next Page(pagination)');

    return Html::tag('a', [
        'href' => $item['url'],
        'class' => ['nd-pager-item', $index === 1 ? 'nd-pager-item-next' : null],
    ], $head . Html::tag('p', ['class' => 'nd-pager-desc'], Html::e($description)));
}
