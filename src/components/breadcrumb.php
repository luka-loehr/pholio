<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';

/**
 * The breadcrumb above the title (`layouts/notebook/page/slots/breadcrumb.js`).
 *
 * `Tree::breadcrumb()` decides which stations appear: the root folder resets
 * the list, and a folder is dropped when its index page follows right after it.
 * When nothing is left, the whole block is omitted.
 *
 * @param list<array{name:string, url:?string}> $items
 */
function nd_breadcrumb(array $items): string
{
    if ($items === []) {
        return '';
    }

    $out = '';
    $last = count($items) - 1;
    foreach ($items as $i => $item) {
        if ($i !== 0) {
            $out .= Icons::svg('chevron-right', 'nd-crumb-sep');
        }
        $classes = ['nd-crumb-item', $i === $last ? 'nd-crumb-current' : null];
        $out .= $item['url'] !== null
            ? Html::tag('a', [
                'href' => $item['url'],
                'class' => [...$classes, 'nd-crumb-link'],
            ], Html::e($item['name']))
            : Html::tag('span', ['class' => $classes], Html::e($item['name']));
    }

    return Html::tag('div', ['class' => 'nd-crumb'], $out);
}
