<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';

/**
 * What the closed search dialog leaves in the DOM.
 *
 * Base UI mounts the dialog's backdrop and frame into a portal at the end of
 * `body`; while closed, only the hidden backdrop and the empty footer remain.
 * The dialog itself is built by `js/dialog.js` and `js/search-dialog.js`.
 */
function nd_search_dialog_portal(): string
{
    $backdrop = Html::tag('div', [
        'role' => 'presentation',
        'data-closed' => true,
        'hidden' => true,
        'class' => 'nd-dialog-backdrop',
        // Order as in the reference (Base UI sets userSelect before WebkitUserSelect);
        // verify/behaviour.mjs compares the style attribute as a string.
        'style' => ['user-select' => 'none', '-webkit-user-select' => 'none'],
    ], '');

    $footer = Html::tag('div', ['class' => 'nd-dialog-footer'], '');

    return $backdrop . $footer;
}
