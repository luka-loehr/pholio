<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../I18n.php';
require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';

/**
 * "Copy page" next to the page title, with a menu: view the Markdown twin, open
 * ChatGPT or Claude with a prompt that points at it, copy the llms.txt URL.
 * Not part of the reference design; built from its secondary button and its
 * popover. js/page-actions.js wires it up.
 *
 * The menu content is a `<template data-page-actions-popup>` after its trigger,
 * like the section switcher's, and js/popover.js mounts it. The chat links get
 * their `href` in JavaScript: the prompt names the absolute URL of the twin,
 * which only the browser knows when `site.url` is not set.
 *
 * @param array{markdownUrl:string, llmsTxtUrl:?string} $actions
 */
function nd_page_actions(array $actions): string
{
    $copy = Html::tag('button', [
        'type' => 'button',
        'data-page-copy' => true,
        'class' => 'nd-btn nd-btn-secondary nd-page-actions-btn',
    ], Icons::svg('copy', 'nd-page-actions-idle-icon') . Icons::svg('check', 'nd-page-actions-done-icon')
        . Html::tag('span', [], Html::e(I18n::t('Copy page(page actions)'))));

    $more = Html::tag('button', [
        'type' => 'button',
        'aria-haspopup' => 'dialog',
        'aria-expanded' => 'false',
        'aria-label' => I18n::t('More page actions(page actions)(aria-label)'),
        'data-page-actions-trigger' => true,
        'class' => 'nd-btn nd-btn-secondary nd-page-actions-btn nd-page-actions-more',
    ], Icons::svg('chevron-down'));

    $item = static fn(string $tag, array $attrs, string $icon, string $label, string $end = ''): string => Html::tag(
        $tag,
        $attrs + ['class' => 'nd-page-actions-item'],
        $icon . Html::tag('span', [], Html::e($label)) . $end,
    );
    $external = ['target' => '_blank', 'rel' => 'noreferrer noopener'];

    $items = $item('a', ['href' => $actions['markdownUrl'], 'data-page-action' => 'markdown'], Icons::svg('file-text'), I18n::t('View as Markdown(page actions)'))
        . $item('a', ['href' => 'https://chatgpt.com/', 'data-page-action' => 'chatgpt'] + $external, Icons::svg('message-circle'), I18n::t('Open in ChatGPT(page actions)'), Icons::svg('external-link', 'nd-page-actions-external'))
        . $item('a', ['href' => 'https://claude.ai/new', 'data-page-action' => 'claude'] + $external, Icons::svg('message-circle'), I18n::t('Open in Claude(page actions)'), Icons::svg('external-link', 'nd-page-actions-external'));
    if ($actions['llmsTxtUrl'] !== null) {
        $items .= $item(
            'button',
            ['type' => 'button', 'data-page-action' => 'llms'],
            Icons::svg('link', 'nd-page-actions-idle-icon') . Icons::svg('check', 'nd-page-actions-done-icon'),
            I18n::t('Copy llms.txt URL(page actions)'),
        );
    }

    return Html::tag('div', [
        'class' => 'nd-page-actions',
        'data-page-actions' => true,
        'data-markdown-url' => $actions['markdownUrl'],
        'data-llms-url' => $actions['llmsTxtUrl'],
        'data-prompt' => I18n::t('Read {url}, I want to ask questions about it.(page actions)'),
    ], $copy . $more . Html::tag('template', ['data-page-actions-popup' => true], $items));
}
