<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../I18n.php';
require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';
require_once __DIR__ . '/../lib/Ids.php';
require_once __DIR__ . '/../lib/Tree.php';
require_once __DIR__ . '/tabs-dropdown.php';
require_once __DIR__ . '/theme-switch.php';

/** `itemVariants` from `layouts/notebook/slots/sidebar.js`, one class per variant. */
const ND_ITEM_BASE = 'nd-sidebar-item';
const ND_ITEM_LINK = 'nd-sidebar-item-link';
const ND_ITEM_BUTTON = 'nd-sidebar-item-button';
const ND_ITEM_HIGHLIGHT = 'nd-sidebar-item-mark';

/** `getItemOffset`: indentation by depth. */
function nd_item_offset(int $depth): string
{
    return 'calc(' . (2 + 3 * $depth) . ' * var(--spacing))';
}

/**
 * The sidebar of the notebook layout (`layouts/notebook/slots/sidebar.js`) in
 * both variants.
 *
 * From 768 px the reference renders only the desktop sidebar, below that only the
 * drawer (`SidebarDrawer` behind `SidebarContent`). The static page doesn't know
 * the window width and therefore prints both: the desktop sidebar open in its
 * placeholder, then the overlay as a template and the closed drawer
 * (`data-state="closed"`, `nd-invisible`). Right after them comes
 * `js/sidebar-restore.js` as a classic script: with both variants parsed, it
 * restores open folders and the scroll position before the first paint and
 * removes the variant that doesn't match the width.
 *
 * The sidebar always shows the children of the current page's root folder.
 *
 * @param list<array{active:Node, options:list<array<string,mixed>>}> $groups tab groups from Tree::tabsFor()
 */
function nd_sidebar(array $config, Tree $tree, string $url, array $groups): string
{
    $path = $tree->pathTo($url);

    $aside = Html::tag('aside', [
        'id' => 'nd-sidebar',
        'data-collapsed' => 'false',
        'data-hovered' => 'false',
        'class' => 'nd-sidebar',
    ], nd_sidebar_head($groups, $path, true, '')
        . nd_sidebar_scroll($config, $tree, $url, $path)
        // Footer for icon links; without such links it stays empty and hidden.
        . Html::tag('div', ['class' => 'nd-sidebar-foot'], ''));

    $desktop = Html::tag('div', [
        'data-sidebar-placeholder' => true,
        'class' => 'nd-sidebar-placeholder',
    ], $aside);

    return $desktop
        . nd_sidebar_drawer($config, $tree, $url, $groups, $path)
        . '<script src="' . Html::e(rtrim($config['assetBase'], '/') . '/js/sidebar-restore.js') . '"></script>';
}

/**
 * Head of a variant: in the desktop sidebar only the section picker (with
 * `nd-tabsdrop-navbar` and the popup template), in the drawer first the close
 * button, then the same picker without `lg:hidden`.
 *
 * @param list<array{active:Node, options:list<array<string,mixed>>}> $groups
 * @param list<Node> $path
 */
function nd_sidebar_head(array $groups, array $path, bool $desktop, string $leading): string
{
    $out = $leading;
    foreach ($groups as $group) {
        $selected = nd_selected_tab($group['options'], $path);
        if ($selected !== null) {
            $out .= nd_tabs_dropdown($selected, $group['options'], $desktop);
        }
    }

    return Html::tag('div', ['class' => 'nd-sidebar-head'], $out);
}

/**
 * The ScrollArea part of a variant: nav links (in the header from `lg`, here
 * below that) and the page tree.
 *
 * @param list<Node> $path
 */
function nd_sidebar_scroll(
    array $config,
    Tree $tree,
    string $url,
    array $path,
    ?\Closure $ids = null,
    ?string $viewportId = null,
): string {
    $menu = '';
    $last = count($config['links']) - 1;
    foreach ($config['links'] as $i => $link) {
        $menu .= nd_sidebar_item(
            $link['title'],
            $link['href'],
            Tree::isActive($link['href'], $url, $link['active'] === 'prefix'),
            0,
            $link['external'],
            null,
            'nd-sidebar-menu' . ($i === $last ? ' nd-sidebar-menu-last' : ''),
        );
    }

    $tocTree = $menu . nd_sidebar_nodes($tree->rootFor($url)->children, 0, $path, $url, $ids);
    $viewport = Html::tag('div', [
        'class' => 'base-ui-disable-scrollbar nd-scroll-viewport',
        'data-id' => $viewportId ?? Ids::next() . '-viewport',
        'role' => 'presentation',
        'tabindex' => '-1',
        'style' => ['overflow' => 'scroll'],
    ], Html::tag('div', ['class' => 'nd-sidebar-list'], $tocTree));

    return Html::tag('div', [
        'class' => 'nd-scroll',
        'role' => 'presentation',
        'style' => [
            'position' => 'relative',
            '--scroll-area-corner-width' => '0px',
            '--scroll-area-corner-height' => '0px',
        ],
    ], $viewport);
}

/**
 * The drawer below 768 px (`SidebarDrawerOverlay` + `SidebarDrawerContent`),
 * closed: overlay as a template, `aside#nd-sidebar-mobile` with
 * `data-state="closed"` and `nd-invisible`. Structure as the reference's drawer
 * at 390 px (reference export `dom-390.html`). Ids come from the page counter,
 * as in the reference; verify/golden-dom.mjs takes ids inside allowed extra
 * nodes out of the numbering of the rest of the page.
 *
 * @param list<array{active:Node, options:list<array<string,mixed>>}> $groups
 * @param list<Node> $path
 */
function nd_sidebar_drawer(array $config, Tree $tree, string $url, array $groups, array $path): string
{
    $overlay = Html::tag('template', ['data-sidebar-overlay' => true], Html::tag('div', [
        'class' => 'nd-drawer-overlay',
    ], ''));

    $close = Html::tag('button', [
        'type' => 'button',
        'aria-label' => I18n::t('Open Sidebar(aria-label)'),
        'aria-expanded' => 'false',
        'aria-controls' => 'nd-sidebar-mobile',
        'class' => 'nd-btn nd-btn-ghost nd-drawer-close',
    ], Icons::svg('x'));

    $aside = Html::tag('aside', [
        'id' => 'nd-sidebar-mobile',
        'data-state' => 'closed',
        'class' => 'nd-invisible nd-drawer',
    ], nd_sidebar_head($groups, $path, false, $close)
        . nd_sidebar_scroll($config, $tree, $url, $path)
        . Html::tag('div', ['class' => 'nd-drawer-foot'], nd_theme_switch()));

    return $overlay . $aside;
}

/** `findLast(isLayoutTabActive)`: the tab whose folder lies on the path. */
function nd_selected_tab(array $options, array $path): ?array
{
    $found = null;
    foreach ($options as $option) {
        foreach ($path as $node) {
            if ($node === $option['folder']) {
                $found = $option;
            }
        }
    }

    return $found ?? ($options[0] ?? null);
}

/**
 * The nodes of the page tree (`createPageTreeRenderer`).
 *
 * @param list<Node> $nodes
 * @param list<Node> $path
 */
function nd_sidebar_nodes(array $nodes, int $depth, array $path, string $url, ?\Closure $ids = null): string
{
    $out = '';
    foreach ($nodes as $node) {
        if ($node->type === Node::SEPARATOR) {
            $out .= nd_sidebar_separator($node, $depth);
            continue;
        }
        if ($node->type === Node::PAGE) {
            $out .= nd_sidebar_item(
                (string) $node->name,
                (string) $node->url,
                Tree::isActive((string) $node->url, $url),
                $depth,
                $node->external === true,
                $node->icon,
            );
            continue;
        }
        $out .= nd_sidebar_folder($node, $depth, $path, $url, $ids);
    }

    return $out;
}

/** `SidebarSeparator`: a paragraph with top spacing, at depth 0 not before the first child. */
function nd_sidebar_separator(Node $node, int $depth): string
{
    return Html::tag('p', [
        'class' => ['nd-sidebar-sep', $depth === 0 ? 'nd-sidebar-sep-top' : null],
        'style' => ['padding-inline-start' => nd_item_offset($depth)],
    ], nd_node_icon($node->icon) . Html::e((string) $node->name));
}

/** `SidebarItem`: a link in the page tree or a nav link from the configuration. */
function nd_sidebar_item(
    string $name,
    string $href,
    bool $active,
    int $depth,
    bool $external,
    ?string $icon,
    ?string $extraClass = null,
): string {
    $leading = $icon !== null ? nd_node_icon($icon) : ($external ? Icons::svg('external-link') : '');

    return Html::tag('a', [
        'class' => [ND_ITEM_BASE, ND_ITEM_LINK, $depth >= 1 ? ND_ITEM_HIGHLIGHT : null, $extraClass],
        'style' => ['padding-inline-start' => nd_item_offset($depth)],
        'data-active' => $active ? 'true' : 'false',
        'href' => $href,
        'target' => $external ? '_blank' : null,
        'rel' => $external ? 'noreferrer noopener' : null,
    ], $leading . Html::e($name));
}

/**
 * `SidebarFolder`: a Base UI collapsible. A folder is open when it lies on the
 * path, carries `defaultOpen` or isn't collapsible (`defaultOpenLevel` is 0, so
 * it never matters). A closed folder doesn't render its children at all; Base UI
 * mounts the panel only on opening.
 *
 * @param list<Node> $path
 */
function nd_sidebar_folder(Node $node, int $depth, array $path, string $url, ?\Closure $ids = null): string
{
    // Id source: hydration ids in the desktop sidebar, fixed ids in the drawer.
    $next = $ids ?? static fn (): string => Ids::next();
    $collapsible = $node->collapsible !== false;
    $active = in_array($node, $path, true);
    $open = !$collapsible || $active || ($node->defaultOpen ?? false);
    $panelId = $open ? $next() : null;

    $label = nd_node_icon($node->icon) . Html::e((string) $node->name);

    if ($node->index !== null) {
        $trigger = Html::tag('a', [
            'class' => [ND_ITEM_BASE, ND_ITEM_LINK, $depth >= 1 ? ND_ITEM_HIGHLIGHT : null, 'nd-sidebar-trigger'],
            'style' => ['padding-inline-start' => nd_item_offset($depth)],
            'data-active' => Tree::isActive((string) $node->index->url, $url) ? 'true' : 'false',
            'href' => (string) $node->index->url,
            'aria-controls' => $open ? $panelId : null,
            'data-panel-open' => $open ? true : null,
        ], $label . ($collapsible ? nd_chevron($open) : ''));
    } else {
        $trigger = Html::tag('button', [
            'type' => 'button',
            'aria-controls' => $open ? $panelId : null,
            'aria-disabled' => 'false',
            'aria-expanded' => $open ? 'true' : 'false',
            'data-panel-open' => $open ? true : null,
            'tabindex' => '0',
            'class' => [ND_ITEM_BASE, $collapsible ? ND_ITEM_BUTTON : null, 'nd-sidebar-trigger'],
            'style' => ['padding-inline-start' => nd_item_offset($depth)],
        ], $label . ($collapsible ? nd_chevron($open) : ''));
    }

    // Open: the panel is in the DOM. Closed: Base UI mounts it only on opening;
    // the generator therefore puts it into the folder as a template, exactly as it
    // would render open (without `animation-name`, which is added only after the
    // opening animation). js/collapsible.js mounts it.
    $panel = $open
        ? nd_sidebar_panel($node, $depth, $path, $url, (string) $panelId, true, $ids)
        : Html::tag('template', ['data-collapsible-panel' => true], nd_sidebar_panel($node, $depth, $path, $url, $next(), false, $ids));

    // `data-folder-id`: stable key of the folder for the stored open state
    // (js/sidebar.js, js/sidebar-restore.js), the same on every page.
    return Html::tag('div', [
        'data-folder-id' => $node->id,
        $open ? 'data-open' : 'data-closed' => true,
    ], $trigger . $panel);
}

/**
 * The panel of a folder (Base UI `Collapsible.Panel`).
 *
 * @param list<Node> $path
 */
function nd_sidebar_panel(
    Node $node,
    int $depth,
    array $path,
    string $url,
    string $id,
    bool $mounted,
    ?\Closure $ids = null,
): string
{
    $style = [
        '--collapsible-panel-height' => 'auto',
        '--collapsible-panel-width' => 'auto',
    ];
    if ($mounted) {
        // After the opening animation Base UI switches the animation off.
        $style['animation-name'] = 'none';
    }

    return Html::tag('div', [
        'id' => $id,
        'data-open' => true,
        'class' => [
            'nd-sidebar-panel',
            // `depth` in the original is the folder depth (here $depth + 1);
            // the reference draws the vertical line only at depth 1.
            $depth === 0 ? 'nd-sidebar-panel-line' : null,
        ],
        'style' => $style,
    ], nd_sidebar_nodes($node->children, $depth + 1, $path, $url, $ids));
}

/** The arrow of a folder; `data-icon` tells a click on the arrow from a click on the text. */
function nd_chevron(bool $open): string
{
    // components.css rotates the closed arrow through the trigger's
    // data-panel-open, not through a state class.
    $svg = Icons::svg('chevron-down', 'nd-sidebar-chevron');

    return str_replace('aria-hidden="true"', 'data-icon="true" aria-hidden="true"', $svg);
}

/** Icon of a node; meta.json and frontmatter use the React names (`Users`). */
function nd_node_icon(?string $icon): string
{
    return $icon === null || $icon === '' ? '' : Icons::svg(nd_icon_name($icon));
}
