<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';

/**
 * A slot of the `slots` key: the HTML a site's slot file returned, verbatim, in
 * `<div class="nd-slot nd-slot-<name>" data-slot="<name>">`; "" without one.
 */
function nd_slot(string $name, string $html): string
{
    return $html === '' ? '' : Html::tag('div', ['class' => 'nd-slot nd-slot-' . $name, 'data-slot' => $name], $html);
}
