<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';
require_once __DIR__ . '/../lib/Ids.php';

/**
 * File tree, `Files`/`Folder`/`File` from `fumadocs-ui/dist/components/files.js`
 * on `components/ui/collapsible.js` (Base UI `Collapsible`).
 *
 * A folder is a collapsible root `div[data-open|data-closed]` with trigger and
 * panel. While closed, Base UI doesn't mount the panel; it then sits in
 * `<template data-collapsible-panel>` (the same form as the sidebar folders,
 * read by js/collapsible.js). A folder opened by `defaultOpen` renders its panel
 * with `animation-name:none` (shouldPreventMountAnimation).
 */

function nd_files_tree(string $children): string
{
    return Html::tag('div', ['class' => 'nd-files not-prose'], $children);
}

/** @param array{name:string, icon?:?string} $props */
function nd_file(array $props): string
{
    $icon = isset($props['icon']) && $props['icon'] !== '' ? Icons::svg($props['icon']) : Icons::svg('file');

    return Html::tag('div', ['class' => 'nd-files-item'], $icon . Html::e($props['name']));
}

/** @param array{name:string, defaultOpen?:bool, disabled?:bool} $props */
function nd_folder(array $props, string $children): string
{
    $open = (bool) ($props['defaultOpen'] ?? false);
    $disabled = (bool) ($props['disabled'] ?? false);
    $panelId = Ids::next();

    $trigger = Html::tag('button', [
        'type' => 'button',
        'data-panel-open' => $open,
        'data-disabled' => $disabled,
        'tabindex' => '0',
        'aria-disabled' => $disabled ? 'true' : 'false',
        'aria-controls' => $open ? $panelId : null,
        'aria-expanded' => $open ? 'true' : 'false',
        'class' => 'nd-files-item nd-files-folder',
    ], Icons::svg($open ? 'folder-open' : 'folder') . Html::e($props['name']));

    $inner = Html::tag('div', ['class' => 'nd-files-children'], $children);

    if ($open) {
        $panel = Html::tag('div', [
            'data-open' => true,
            'id' => $panelId,
            'style' => [
                '--collapsible-panel-height' => 'auto',
                '--collapsible-panel-width' => 'auto',
                'animation-name' => 'none',
            ],
            'class' => 'nd-collapsible-panel',
        ], $inner);
    } else {
        $panel = Html::tag(
            'template',
            ['data-collapsible-panel' => true],
            Html::tag('div', ['id' => $panelId, 'class' => 'nd-collapsible-panel'], $inner)
        );
    }

    return Html::tag('div', [
        'data-open' => $open,
        'data-closed' => !$open,
        'data-disabled' => $disabled,
    ], $trigger . $panel);
}
