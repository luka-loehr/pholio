<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../Exceptions.php';
require_once __DIR__ . '/../I18n.php';
require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';

/**
 * The page menu next to the page title: a "Copy page" button joined to a chevron
 * that opens a menu with three actions, Copy page, Open in ChatGPT and Open in
 * Claude. Each item has its icon in a small tile, a title and a muted
 * description. Modelled on Mintlify's page menu, built from the theme's secondary button colors and its popover.
 * js/page-actions.js wires it up.
 *
 * The two halves of the trigger sit in one bordered group with `overflow:
 * hidden`, so they share one border and radius and a 1 px divider. The menu is a
 * `<template data-page-actions-popup>` after the trigger, like the section
 * switcher's, and js/popover.js mounts it. The chat links get their `href` in
 * JavaScript: the prompt names absolute URLs, which only the browser knows when
 * `site.url` is not set. Claude's prompt names the Markdown twin; ChatGPT's names
 * the page itself and mentions the twin, because ChatGPT's reader handles HTML
 * reliably and Markdown only as plain text.
 *
 * The OpenAI and Claude marks are the Simple Icons SVGs in vendor-data/brands/,
 * inlined with their path data unchanged (THIRD_PARTY_NOTICES.md).
 *
 * @param array{markdownUrl:string, llmsTxtUrl:?string} $actions
 */
function nd_page_actions(array $actions): string
{
    $copyIcons = Icons::svg('copy', 'nd-page-copy-idle') . Icons::svg('check', 'nd-page-copy-done');

    $copy = Html::tag('button', [
        'type' => 'button',
        'data-page-copy' => true,
        'class' => 'nd-btn nd-btn-text-sm nd-page-copy',
    ], $copyIcons
        . Html::tag('span', ['class' => 'nd-page-copy-label'], Html::e(I18n::t('Copy page(page actions)')))
        . Html::tag('span', ['class' => 'nd-page-copy-copied'], Html::e(I18n::t('Copied(page actions)'))));

    $open = Html::tag('button', [
        'type' => 'button',
        'aria-haspopup' => 'dialog',
        'aria-expanded' => 'false',
        'aria-label' => I18n::t('More actions(page actions)(aria-label)'),
        'data-page-actions-trigger' => true,
        'class' => 'nd-btn nd-page-open',
    ], Icons::svg('chevron-down', 'nd-page-open-chevron'));

    $item = static fn(string $tag, array $attrs, string $icon, string $title, string $description, bool $external): string => Html::tag(
        $tag,
        $attrs + ['class' => 'nd-page-actions-item'],
        Html::tag('span', ['class' => 'nd-page-actions-tile'], $icon)
        . Html::tag('span', ['class' => 'nd-page-actions-text'],
            Html::tag('span', ['class' => 'nd-page-actions-title'], Html::e($title) . ($external ? Icons::svg('arrow-up-right', 'nd-page-actions-external') : ''))
            . Html::tag('span', ['class' => 'nd-page-actions-desc'], Html::e($description))),
    );
    $blank = ['rel' => 'noreferrer noopener', 'target' => '_blank'];
    $ask = I18n::t('Ask questions about this page(page actions)');

    $items = $item('button', ['type' => 'button', 'data-page-action' => 'copy'], $copyIcons, I18n::t('Copy page(page actions)'), I18n::t('Copy page as Markdown for LLMs(page actions)'), false)
        . $item('a', ['href' => 'https://chatgpt.com/', 'data-page-action' => 'chatgpt'] + $blank, nd_brand_mark('openai'), I18n::t('Open in ChatGPT(page actions)'), $ask, true)
        . $item('a', ['href' => 'https://claude.ai/new', 'data-page-action' => 'claude'] + $blank, nd_brand_mark('claude'), I18n::t('Open in Claude(page actions)'), $ask, true);

    return Html::tag('div', [
        'class' => 'nd-page-actions',
        'data-page-actions' => true,
        'data-markdown-url' => $actions['markdownUrl'],
        'data-prompt' => I18n::t('Read {url}, I want to ask questions about it.(page actions)'),
        'data-prompt-chatgpt' => I18n::t('Read {url}, I want to ask questions about it. A Markdown version of the page is at {markdown}.(page actions)'),
    ], Html::tag('div', ['class' => 'nd-page-actions-group'], $copy . $open)
        . Html::tag('template', ['data-page-actions-popup' => true], $items));
}

/**
 * A brand mark from vendor-data/brands/<name>.svg as an inline icon: the file's
 * viewBox and path data unchanged, filled with currentColor so the theme colors it.
 */
function nd_brand_mark(string $name): string
{
    static $marks = [];
    if (!isset($marks[$name])) {
        $file = dirname(__DIR__, 2) . '/vendor-data/brands/' . $name . '.svg';
        $svg = is_file($file) ? (string) file_get_contents($file) : '';
        if (preg_match('/viewBox="([^"]+)"/', $svg, $box) !== 1 || preg_match('/<path d="([^"]+)"/', $svg, $path) !== 1) {
            throw new \LogicException('brand mark missing or without a single path: vendor-data/brands/' . $name . '.svg');
        }
        $marks[$name] = Html::tag('svg', [
            'viewBox' => $box[1],
            'fill' => 'currentColor',
            'aria-hidden' => 'true',
            'focusable' => 'false',
            'class' => 'nd-page-actions-logo',
        ], '<path d="' . $path[1] . '"></path>');
    }

    return $marks[$name];
}
