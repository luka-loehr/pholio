<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';

/**
 * The nav title on the left of the header, identical in both layouts.
 *
 * Outer classes from `layouts/shared/index.js` (`NavTitle`), the content (logo
 * plus wordmark) from the reference's shared layout options. Without a logo only
 * the wordmark is printed.
 */
function nd_nav_title(array $config): string
{
    $logo = $config['nav']['logo'] === null ? '' : Html::voidTag('img', [
        'src' => $config['nav']['logo'],
        'alt' => '',
        'width' => $config['nav']['logoSize'],
        'height' => $config['nav']['logoSize'],
        'class' => 'nd-nav-logo',
    ]);
    $inner = Html::tag('span', ['class' => 'nd-nav-title-inner'], $logo
        . Html::tag('span', ['class' => 'nd-strong'], Html::e($config['nav']['title'])));

    return Html::tag('a', [
        'class' => 'nd-nav-title',
        'href' => $config['nav']['url'],
    ], $inner);
}
