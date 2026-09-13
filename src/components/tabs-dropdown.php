<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';
require_once __DIR__ . '/../lib/Ids.php';

/**
 * The section picker at the top of the sidebar (`SidebarTabsDropdown` from
 * `components/sidebar/tabs/dropdown.js`).
 *
 * Only the Base UI `Popover.Trigger` is visible. The popup hangs off a portal
 * and doesn't exist in the DOM while closed. So that js/notebook.js can build it
 * without a second data source, the generator places its content as
 * `<template data-nd-tabs-popup>` right after every trigger, in the desktop
 * sidebar and in the drawer: below 768 px sidebar-restore.js removes the desktop
 * sidebar before the first paint, and a single template would go with it
 * (structure as reference export `states/tabs-popup-template.html`); the golden
 * DOM comparison doesn't see templates.
 *
 * In the desktop sidebar the button additionally carries `nd-tabsdrop-navbar`
 * (`lg:hidden`) in mode `tabMode: navbar`, because from `lg` the tabs sit in the
 * header. In the drawer the class is missing, just as in the reference.
 *
 * @param array{title:string, description:?string, icon:?string, folder:Node} $group selected section
 * @param list<array{title:string, description:?string, icon:?string, url:string, folder:Node}> $options all sections of the group
 * @param string|null $id fixed id (drawer); otherwise the next hydration id
 */
function nd_tabs_dropdown(array $group, array $options = [], bool $desktop = true, ?string $id = null): string
{
    $icon = $group['icon'] === null
        ? ''
        : Icons::svg(nd_icon_name($group['icon']));
    // `empty:hidden` hides the box when the folder has no icon.
    $iconBox = Html::tag('div', ['class' => 'nd-tabsdrop-iconbox'], Html::tag('div', [
        'class' => 'nd-tabsdrop-iconinner',
    ], $icon));

    $texts = Html::tag('div', [], Html::tag('p', ['class' => 'nd-tabsdrop-title'], Html::e($group['title']))
        . Html::tag('p', ['class' => 'nd-tabsdrop-desc'], Html::e($group['description'] ?? '')));

    $chevron = Icons::svg('chevrons-up-down', 'nd-tabsdrop-chevron');

    $button = Html::tag('button', [
        'type' => 'button',
        'aria-expanded' => 'false',
        'aria-haspopup' => 'dialog',
        'data-base-ui-click-trigger' => true,
        // In the drawer components/sidebar.php assigns a fixed id.
        'id' => $id ?? Ids::next(),
        'tabindex' => '0',
        'class' => ['nd-tabsdrop', $desktop ? 'nd-tabsdrop-navbar' : null],
    ], $iconBox . $texts . $chevron);

    if ($options === []) {
        return $button;
    }

    return $button . nd_tabs_popup_template($group, $options);
}

/**
 * Content of the popup: one option per section, the check mark visible only on
 * the selected one (the others carry `nd-invisible`, `invisible` in the reference).
 *
 * @param array{folder:Node} $selected
 * @param list<array{title:string, description:?string, icon:?string, url:string, folder:Node}> $options
 */
function nd_tabs_popup_template(array $selected, array $options): string
{
    $items = '';
    foreach ($options as $option) {
        $active = $option['folder'] === $selected['folder'];
        $icon = ($option['icon'] ?? null) === null ? '' : Icons::svg(nd_icon_name((string) $option['icon']));

        $items .= Html::tag('a', [
            'class' => 'nd-tabsdrop-option',
            'href' => $option['url'],
        ], Html::tag('div', ['class' => 'nd-tabsdrop-option-iconbox'], Html::tag('div', [
            'class' => 'nd-tabsdrop-iconinner',
        ], $icon))
            . Html::tag('div', [], Html::tag('p', ['class' => 'nd-tabsdrop-option-title'], Html::e($option['title']))
                . Html::tag('p', ['class' => 'nd-tabsdrop-option-desc'], Html::e($option['description'] ?? '')))
            . Icons::svg('check', $active ? 'nd-tabsdrop-check' : 'nd-tabsdrop-check nd-invisible'));
    }

    return Html::tag('template', ['data-nd-tabs-popup' => true], $items);
}

/** `Users` becomes `users`, `ShieldCheck` becomes `shield-check`: meta.json uses the React names. */
function nd_icon_name(string $name): string
{
    $kebab = preg_replace('/(?<!^)[A-Z]/', '-$0', $name);

    return strtolower((string) $kebab);
}
