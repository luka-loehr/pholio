<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';

/**
 * Step sequence (`Steps`/`Step`).
 *
 * The rules for both classes (`.fd-steps`, `.fd-step:before` with the counter)
 * live in theme/css/prose.css.
 */
function nd_steps(string $children): string
{
    return Html::tag('div', ['class' => 'fd-steps'], $children);
}

function nd_step(string $children): string
{
    return Html::tag('div', ['class' => 'fd-step'], $children);
}
