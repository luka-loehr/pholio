<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';

/**
 * Step sequence, `Steps`/`Step` from `fumadocs-ui/dist/components/steps.js`.
 *
 * Both classes keep their names: their rules (`.fd-steps`, `.fd-step:before`
 * with the counter) live in `fumadocs-ui/css/lib/base.css` and are carried over
 * into theme/css/prose.css.
 */
function nd_steps(string $children): string
{
    return Html::tag('div', ['class' => 'fd-steps'], $children);
}

function nd_step(string $children): string
{
    return Html::tag('div', ['class' => 'fd-step'], $children);
}
