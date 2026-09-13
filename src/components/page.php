<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../I18n.php';
require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/breadcrumb.php';
require_once __DIR__ . '/footer.php';

/**
 * The article itself: breadcrumb, title, description, body, "last updated" line
 * and footer navigation.
 *
 * In the "last updated" line the label (`Last updated(page)`) and the value are
 * two separate text nodes, split by an empty comment.
 *
 * @param array{title:string, description:?string, updated:?string, full:bool} $page
 * @param list<array{name:string, url:?string}> $breadcrumb
 * @param array<string, array{name:string,url:string,description:?string}> $footerItems
 * @param string $actions the page menu (components/page-actions.php); with it the title and
 *        the menu share one row, which wraps on narrow screens
 */
function nd_page(array $page, array $breadcrumb, string $body, array $footerItems, string $actions = ''): string
{
    $children = nd_breadcrumb($breadcrumb);
    $title = Html::tag('h1', ['class' => 'nd-page-title'], Html::e($page['title']));
    $children .= $actions === '' ? $title : Html::tag('div', ['class' => 'nd-page-heading'], $title . $actions);
    if ($page['description'] !== null) {
        $children .= Html::tag('p', ['class' => 'nd-page-desc'], Html::e($page['description']));
    }
    if ($page['updated'] !== null && $page['updated'] !== '') {
        $children .= Html::tag(
            'p',
            ['class' => 'nd-page-stand'],
            Html::e(I18n::t('Last updated(page)')) . '<!---->' . Html::e($page['updated'])
        );
    }
    $children .= Html::tag('div', ['class' => 'nd-prose prose'], $body);
    $children .= nd_footer($footerItems);

    $article = Html::tag('article', [
        'id' => 'nd-page',
        'data-layout-content' => true,
        'data-full' => $page['full'] ? 'true' : 'false',
        // nd-page-full replaces the children's width when `full` is set.
        'class' => ['nd-page', $page['full'] ? 'nd-page-full' : null],
    ], $children);

    return Html::tag('main', ['class' => 'nd-main', 'data-layout-main' => true], $article);
}
