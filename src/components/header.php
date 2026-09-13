<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../I18n.php';
require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';
require_once __DIR__ . '/../lib/Tree.php';
require_once __DIR__ . '/nav-title.php';
require_once __DIR__ . '/search-trigger.php';
require_once __DIR__ . '/theme-switch.php';
require_once __DIR__ . '/layout-tabs.php';

/**
 * Base class of the buttons without padding
 * and radius: every size class sets those itself (`nd-btn-icon`,
 * `nd-btn-icon-sm`, `nd-btn-icon-xs`, `nd-btn-sm`), the variant sets the colour
 * (`nd-btn-ghost`, `nd-btn-secondary`). Rules in theme/css/components.css.
 */
const ND_BUTTON_BASE = 'nd-btn';

/**
 * The header of the docs layout, mode
 * `nav.mode: top`.
 *
 * Row 1 (`[data-header-body]`) holds the nav title on the left, the full search
 * field in the middle and the link groups on the right. Row 2 are the section
 * tabs; without them the class `lg:layout:[--fd-header-height:--spacing(24)]` on
 * the header goes as well, because the header is then 14 instead of 24 units high.
 *
 * @param list<array{title:string,url:string}> $tabs
 */
function nd_header(array $config, string $url, array $tabs, int $selected): string
{
    $links = '';
    foreach ($config['links'] as $link) {
        $links .= Html::tag('a', [
            'class' => 'nd-nav-link',
            'data-active' => nd_nav_link_active($link, $url) ? 'true' : 'false',
            'href' => $link['href'],
            'target' => $link['external'] ? '_blank' : null,
            'rel' => $link['external'] ? 'noreferrer noopener' : null,
        ], Html::e($link['title']));
    }

    $sidebarTrigger = Html::tag('button', [
        'type' => 'button',
        'aria-controls' => 'nd-sidebar-mobile',
        'aria-expanded' => 'false',
        'aria-label' => I18n::t('Open Sidebar(aria-label)'),
        'class' => ND_BUTTON_BASE . ' nd-btn-ghost nd-btn-icon nd-header-trigger',
    ], Icons::svg('sidebar'));

    $collapseTrigger = Html::tag('button', [
        'type' => 'button',
        'aria-label' => I18n::t('Collapse Sidebar(sidebar)(aria-label)'),
        'data-collapsed' => 'false',
        'class' => ND_BUTTON_BASE . ' nd-btn-secondary nd-btn-icon-sm nd-header-trigger',
    ], Icons::svg('sidebar'));

    $right = Html::tag('div', ['class' => 'nd-nav-links'], $links)
        . Html::tag('div', ['class' => 'nd-header-mobile'], nd_search_trigger_small('notebook') . $sidebarTrigger)
        . Html::tag('div', ['class' => 'nd-header-desktop'], nd_theme_switch() . $collapseTrigger);

    $body = Html::tag('div', ['class' => 'nd-header-start'], nd_nav_title($config))
        . nd_search_trigger_full($config, 'notebook')
        . Html::tag('div', ['class' => 'nd-header-end'], $right);

    $rows = Html::tag('div', [
        'class' => 'nd-header-body',
        'data-header-body' => true,
    ], $body) . nd_layout_tabs($tabs, $selected);

    // Without section tabs the header is only 14 instead of 24 units high; the
    // class that raises --fd-header-height from `lg` goes as well.
    return Html::tag('header', [
        'id' => 'nd-subnav',
        'data-transparent' => 'false',
        'class' => ['nd-header', $tabs === [] ? null : 'nd-header-tabs'],
    ], $rows);
}

/**
 * Whether a nav link is current: `exact` matches the URL itself, `prefix` also
 * every URL below it.
 *
 * @param array{href:string, active:string} $link
 */
function nd_nav_link_active(array $link, string $url): bool
{
    return Tree::isActive($link['href'], $url, $link['active'] === 'prefix');
}
