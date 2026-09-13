<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../I18n.php';
require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';
require_once __DIR__ . '/../lib/Ids.php';

/**
 * The two search triggers: the full search field and the small button.
 *
 * Both are Base UI `Dialog.Trigger`s and therefore carry
 * `data-base-ui-click-trigger`, `aria-haspopup="dialog"`, `aria-expanded` and a
 * generated id. In the docs layout the trigger sits in a context without a
 * `disabled` state and gets `tabindex="0"`; on the start page it gets
 * `aria-disabled="false"` without `tabindex` instead, as Base UI does.
 *
 * @param 'notebook'|'home' $layout
 */
function nd_search_trigger_full(array $config, string $layout): string
{
    $classes = $layout === 'home' ? 'nd-search nd-search-home' : 'nd-search nd-search-wide';

    $keys = '';
    foreach ($config['search']['hotkey'] as $key) {
        $keys .= Html::tag('kbd', ['class' => 'nd-search-key'], Html::e($key));
    }

    $children = Icons::svg('search', 'nd-search-icon')
        . Html::e(I18n::t('Search(search trigger)'))
        . Html::tag('div', ['class' => 'nd-search-keys'], $keys);

    return Html::tag('button', [
        'type' => 'button',
        'aria-expanded' => 'false',
        'aria-haspopup' => 'dialog',
        'aria-disabled' => $layout === 'home' ? 'false' : null,
        'tabindex' => $layout === 'home' ? null : '0',
        'data-base-ui-click-trigger' => true,
        'data-search-full' => true,
        'id' => Ids::next(),
        'class' => $classes,
    ], $children);
}

/** The small magnifier button for narrow windows. */
function nd_search_trigger_small(string $layout): string
{
    $classes = 'nd-btn nd-btn-ghost nd-btn-icon';

    return Html::tag('button', [
        'type' => 'button',
        'aria-expanded' => 'false',
        'aria-haspopup' => 'dialog',
        'aria-disabled' => $layout === 'home' ? 'false' : null,
        'aria-label' => I18n::t('Open Search(search trigger)(aria-label)'),
        'tabindex' => $layout === 'home' ? null : '0',
        'data-base-ui-click-trigger' => true,
        'data-search' => true,
        'id' => Ids::next(),
        'class' => $classes,
    ], Icons::svg('search'));
}
