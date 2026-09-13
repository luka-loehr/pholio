<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Tree.php';
require_once __DIR__ . '/home-header.php';
require_once __DIR__ . '/home-hero.php';
require_once __DIR__ . '/home-cards.php';

/**
 * The start page: Fumadocs `HomeLayout` (`layouts/home/index.js` and
 * `slots/container.js`) with the content of the reference start page. The
 * outer `main` carries the layout width, the inner one comes from the page.
 * Hero and cards are each optional (`home.hero`, `home.cards`).
 */
function nd_home_layout(array $config, Tree $tree): string
{
    $home = $config['home'];
    $content = Html::tag('main', ['class' => 'nd-home-main'],
        ($home['hero'] === null ? '' : nd_home_hero($config))
        . ($home['cards'] === null ? '' : nd_home_cards($config, $tree)));

    return Html::tag('main', [
        'id' => 'nd-home-layout',
        'class' => 'nd-home',
    ], nd_home_header($config, $config['homeUrl']) . $content);
}
