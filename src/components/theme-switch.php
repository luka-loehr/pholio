<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../I18n.php';
require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';

/**
 * The theme switch, mode `light-dark`: one button with sun and moon; the active icon carries
 * `bg-fd-accent text-fd-accent-foreground`.
 *
 * The generator marks the sun as active (initial theme light), because the theme
 * script in `templates/document.php` also starts from light; js/theme.js
 * corrects it once the stored theme is known.
 *
 * lucide-react renders these two icons with `fill="currentColor"`; `Icons::svg`
 * returns the lucide default `fill="none"`, so exactly that attribute is replaced.
 */
function nd_theme_switch(): string
{
    $sun = nd_icon_filled('sun', 'nd-theme-icon nd-theme-icon-active');
    $moon = nd_icon_filled('moon', 'nd-theme-icon');

    return Html::tag('button', [
        'class' => 'nd-theme',
        'aria-label' => I18n::t('Toggle Theme(theme switcher)(aria-label)'),
        'data-theme-toggle' => true,
    ], $sun . $moon);
}

/** A lucide icon with `fill="currentColor"` instead of the default `fill="none"`. */
function nd_icon_filled(string $name, string $extraClass): string
{
    $svg = Icons::svg($name, $extraClass);
    $replaced = preg_replace('/ fill="none"/', ' fill="currentColor"', $svg, 1);
    if ($replaced === null) {
        throw new \LogicException('cannot replace the fill attribute: ' . $name);
    }

    return $replaced;
}
