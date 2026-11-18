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
require_once __DIR__ . '/header.php';

/**
 * The header of the start page.
 *
 * On the outside a Base UI `NavigationMenu.Root`; while closed only
 * `header#nd-nav[data-closed]` with its `nav` element is in the DOM, and Base UI
 * mounts the collapsible menu only on opening.
 *
 * Below `lg` the menu is a Base UI collapsible. Its panel sits as
 * `<template data-collapsible-panel>` in `nav` right after the row; js/collapsible.js
 * mounts it and sets the phases. While open, `header#nd-nav` carries `data-open`
 * and the trigger `data-panel-open`; shadow, rounding and arrow in layout.css
 * key off these attributes.
 */
function nd_home_header(array $config, string $url): string
{
    // Text links sit in the row from `sm`; icon-only links are buttons at the
    // end of the row from `lg` and in the menu foot below that.
    $items = '';
    $icons = '';
    foreach ($config['links'] as $link) {
        if ($link['iconOnly']) {
            $icons .= Html::tag('li', ['class' => 'nd-home-nav-item'], nd_home_icon_link($link, $url));
            continue;
        }
        $items .= Html::tag('li', ['class' => 'nd-home-nav-item'], Html::tag(
            'a',
            nd_nav_link_attrs($link, $url, 'nd-home-nav-link'),
            nd_nav_link_label($link),
        ));
    }
    $list = Html::tag('ul', ['class' => 'nd-home-nav-list'], $items);

    // search.enabled false: no search field, no small trigger.
    $search = $config['search']['enabled'] ?? true;
    $wide = Html::tag('div', [
        'class' => 'nd-home-nav-wide',
    ], ($search ? nd_search_trigger_full($config, 'home') : '') . nd_theme_switch()
        . Html::tag('ul', ['class' => 'nd-home-nav-icons'], $icons));

    $menuTrigger = Html::tag('button', [
        'type' => 'button',
        'aria-disabled' => 'false',
        'aria-expanded' => 'false',
        'aria-label' => I18n::t('Toggle Menu(home layout header)(aria-label)'),
        'class' => ND_BUTTON_BASE . ' nd-btn-ghost nd-btn-sm',
    ], Icons::svg('chevron-down', 'nd-home-menu-chevron'));

    $narrow = Html::tag('div', ['class' => 'nd-home-nav-narrow'], ($search ? nd_search_trigger_small('home') : '') . $menuTrigger);

    $row = Html::tag('div', [
        'class' => 'nd-home-nav-row',
    ], nd_nav_title($config) . $list . $wide . $narrow);

    $nav = Html::tag('nav', ['class' => 'nd-home-nav'], $row . nd_home_menu_panel($config, $url));

    return Html::tag('header', [
        'id' => 'nd-nav',
        'data-closed' => true,
        'class' => 'nd-home-header',
    ], $nav);
}

/**
 * An icon-only nav link of the start page: a ghost icon button named by the
 * link title.
 *
 * @param array{title:string, href:string, active:string, external:bool, icon:?string, iconOnly:bool} $link
 */
function nd_home_icon_link(array $link, string $url): string
{
    return Html::tag(
        'a',
        nd_nav_link_attrs($link, $url, ND_BUTTON_BASE . ' nd-btn-ghost nd-btn-icon nd-home-icon-link'),
        nd_nav_link_label($link),
    );
}

/**
 * The collapsible menu below `lg` as a template: nav links (visible only below
 * `sm`, above that they sit in the row) and a footer with the icon-only links,
 * separator and theme switch. Fixed panel id `nd-home-menu-panel`, because the page has only one such
 * menu and js/collapsible.js sets the `aria-controls` reference.
 */
function nd_home_menu_panel(array $config, string $url): string
{
    $links = '';
    $icons = '';
    foreach ($config['links'] as $link) {
        if ($link['iconOnly']) {
            $icons .= nd_home_icon_link($link, $url);
            continue;
        }
        $links .= Html::tag('a', nd_nav_link_attrs($link, $url, 'nd-home-menu-link'), nd_nav_link_label($link));
    }

    $foot = Html::tag('div', ['class' => 'nd-home-menu-foot'], $icons . Html::tag('div', [
        'role' => 'separator',
        'class' => 'nd-home-menu-sep',
    ], '') . nd_theme_switch());

    $panel = Html::tag('div', [
        'data-open' => true,
        'id' => 'nd-home-menu-panel',
        'class' => 'nd-home-menu-panel',
        'style' => [
            '--collapsible-panel-height' => 'auto',
            '--collapsible-panel-width' => 'auto',
        ],
    ], Html::tag('div', ['class' => 'nd-home-menu'], $links . $foot));

    return Html::tag('template', ['data-collapsible-panel' => true], $panel);
}
