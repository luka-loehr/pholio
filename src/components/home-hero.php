<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';

/**
 * The hero of the start page, as in the reference start page: a background
 * image (light and dark as two images), two gradients on top, then kicker,
 * headline, lead paragraph and the buttons.
 *
 * Configuration: `home.hero` in the internal shape. Images and the icon are
 * optional; each omitted one leaves out its `img`. `headline` separates lines
 * with "\n", which become `<br>`.
 *
 * Buttons keep the spacing of the reference JSX: a primary button is label,
 * space, icon; a secondary button is icon, space, label. Without an icon only
 * the label remains.
 */
function nd_home_hero(array $config): string
{
    $hero = $config['home']['hero'];

    $images = '';
    if ($hero['image'] !== null) {
        $images .= Html::voidTag('img', [
            'src' => $hero['image'],
            'alt' => '',
            'class' => 'nd-hero-img nd-hero-img-light',
        ]);
    }
    if ($hero['imageDark'] !== null) {
        $images .= Html::voidTag('img', [
            'src' => $hero['imageDark'],
            'alt' => '',
            'class' => 'nd-hero-img nd-hero-img-dark',
        ]);
    }

    $gradients = Html::tag('div', ['class' => 'nd-hero-wash'], '')
        . Html::tag('div', ['class' => 'nd-hero-fade'], '');

    $icon = $hero['icon'] === null ? '' : Html::voidTag('img', [
        'src' => $hero['icon'],
        'alt' => '',
        'width' => $hero['iconSize'],
        'height' => $hero['iconSize'],
        'class' => 'nd-hero-icon',
    ]);
    $kicker = Html::tag('div', ['class' => 'nd-hero-kicker'], $icon
        . Html::tag('div', ['class' => 'nd-home-kicker'], Html::e($hero['kicker'])));

    $headline = Html::tag('h1', [
        'class' => 'nd-hero-title',
    ], implode('<br>', array_map(static fn (string $line): string => Html::e($line), explode("\n", $hero['headline']))));

    $lead = Html::tag('p', ['class' => 'nd-hero-lead'], Html::e($hero['lead']));

    $buttons = '';
    foreach ($hero['buttons'] as $button) {
        $buttons .= nd_home_hero_button($button);
    }

    $content = Html::tag('div', ['class' => 'nd-hero-inner'], $kicker . $headline . $lead
        . Html::tag('div', ['class' => 'nd-hero-actions'], $buttons));

    return Html::tag('section', ['class' => 'nd-hero'], $images . $gradients . $content);
}

/** @param array{label:string, href:string, variant:string, icon:?string} $button */
function nd_home_hero_button(array $button): string
{
    $label = Html::e($button['label']);
    $icon = $button['icon'] === null ? null : Icons::svg($button['icon'], 'nd-hero-btn-icon');

    if ($button['variant'] === 'secondary') {
        $children = $icon === null ? $label : $icon . ' ' . $label;
    } else {
        $children = $icon === null ? $label : $label . ' ' . $icon;
    }

    return Html::tag('a', [
        'href' => $button['href'],
        'class' => 'nd-hero-btn nd-hero-btn-' . $button['variant'],
    ], $children);
}
