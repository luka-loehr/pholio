<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';
require_once __DIR__ . '/../lib/Tree.php';
require_once __DIR__ . '/tabs-dropdown.php';

/**
 * The section cards of the start page.
 *
 * The cards come from `home.cards.items` or, with `fromTree`, from the root
 * folders of the page tree: title, description and icon from each folder's
 * `meta.json`, the target is the docs root joined with the folder's slug. Icon
 * names may be given as lucide names (`book-open`) or PascalCase names (`BookOpen`).
 */
function nd_home_cards(array $config, Tree $tree): string
{
    $section = $config['home']['cards'];
    $items = $section['fromTree'] ? nd_home_cards_from_tree($tree, $config['baseUrl']) : $section['items'];

    $cards = '';
    foreach ($items as $item) {
        $icon = $item['icon'] === null ? '' : Icons::svg(nd_icon_name($item['icon']), 'nd-home-card-icon');
        $body = $icon
            . Html::tag('div', ['class' => 'nd-strong'], Html::e($item['title']))
            . Html::tag('p', ['class' => 'nd-home-card-text'], Html::e($item['description']))
            . Html::tag('span', ['class' => 'nd-home-card-more'], Html::e($section['linkLabel']) . ' '
                . Icons::svg('arrow-right', 'nd-home-card-arrow'));

        $cards .= Html::tag('a', [
            'href' => $item['href'],
            'class' => 'nd-home-card',
        ], $body);
    }

    $grid = Html::tag('div', ['class' => 'nd-home-grid'], $cards);

    $title = Html::tag('h2', ['class' => 'nd-home-kicker'], Html::e($section['title']));

    return Html::tag('section', ['class' => 'nd-home-section'], $title . $grid);
}

/**
 * One card per folder directly below the docs root, in tree order.
 *
 * @return list<array{title:string, description:string, href:string, icon:?string}>
 */
function nd_home_cards_from_tree(Tree $tree, string $baseUrl): array
{
    $items = [];
    foreach ($tree->root()->children as $node) {
        if ($node->type !== Node::FOLDER || $node->ref === null) {
            continue;
        }
        $items[] = [
            'title' => (string) $node->name,
            'description' => (string) ($node->description ?? ''),
            // `ref` is the folder path relative to the content directory.
            'href' => rtrim($baseUrl, '/') . '/' . $node->ref,
            'icon' => $node->icon,
        ];
    }

    return $items;
}
