<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/../I18n.php';
require_once __DIR__ . '/../lib/Html.php';
require_once __DIR__ . '/../lib/Icons.php';

/**
 * The page actions below the description (`MarkdownCopyButton` and
 * `ViewOptionsPopover` from `layouts/shared/page-actions.js`, placed in a row
 * with `flex flex-row gap-2 items-center border-b pb-6` as in the Fumadocs docs
 * template): "Copy Markdown" copies the page's Markdown twin, "Open" opens a menu
 * with View as Markdown, Open in ChatGPT, Open in Claude and, when llms.txt is
 * published, Copy llms.txt URL. js/page-actions.js wires both up.
 *
 * Both are `buttonVariants({ color: 'secondary', size: 'sm' })` buttons. The menu
 * content is a `<template data-page-actions-popup>` after its trigger, like the
 * section switcher's, and js/popover.js mounts it. The chat links get their
 * `href` in JavaScript: the prompt names the absolute URL of the twin, which only
 * the browser knows when `site.url` is not set.
 *
 * @param array{markdownUrl:string, llmsTxtUrl:?string} $actions
 */
function nd_page_actions(array $actions): string
{
    $copy = Html::tag('button', [
        'type' => 'button',
        'data-page-copy' => true,
        'class' => 'nd-btn nd-btn-secondary nd-btn-text-sm nd-page-copy',
    ], Icons::svg('copy', 'nd-page-copy-idle') . Icons::svg('check', 'nd-page-copy-done') . Html::e(I18n::t('Copy Markdown(page actions)')));

    $open = Html::tag('button', [
        'type' => 'button',
        'aria-haspopup' => 'dialog',
        'aria-expanded' => 'false',
        'data-page-actions-trigger' => true,
        'class' => 'nd-btn nd-btn-secondary nd-btn-text-sm nd-page-open',
    ], Html::e(I18n::t('Open(page actions)')) . Icons::svg('chevron-down', 'nd-page-open-chevron'));

    $external = Icons::svg('external-link', 'nd-page-actions-external');
    $item = static fn(string $tag, array $attrs, string $icon, string $label, string $end = ''): string => Html::tag(
        $tag,
        $attrs + ['class' => 'nd-page-actions-item'],
        $icon . Html::e($label) . $end,
    );
    $blank = ['rel' => 'noreferrer noopener', 'target' => '_blank'];

    $items = $item('a', ['href' => $actions['markdownUrl'], 'data-page-action' => 'markdown'] + $blank, Icons::svg('text'), I18n::t('View as Markdown(page actions)'), $external)
        . $item('a', ['href' => 'https://chatgpt.com/', 'data-page-action' => 'chatgpt'] + $blank, nd_page_actions_mark(ND_OPENAI_MARK), I18n::t('Open in ChatGPT(page actions)'), $external)
        . $item('a', ['href' => 'https://claude.ai/new', 'data-page-action' => 'claude'] + $blank, nd_page_actions_mark(ND_ANTHROPIC_MARK), I18n::t('Open in Claude(page actions)'), $external);
    if ($actions['llmsTxtUrl'] !== null) {
        $items .= $item(
            'button',
            ['type' => 'button', 'data-page-action' => 'llms'],
            Icons::svg('link', 'nd-page-copy-idle') . Icons::svg('check', 'nd-page-copy-done'),
            I18n::t('Copy llms.txt URL(page actions)'),
        );
    }

    return Html::tag('div', [
        'class' => 'nd-page-actions',
        'data-page-actions' => true,
        'data-markdown-url' => $actions['markdownUrl'],
        'data-llms-url' => $actions['llmsTxtUrl'],
        'data-prompt' => I18n::t('Read {url}, I want to ask questions about it.(page actions)'),
    ], $copy . $open . Html::tag('template', ['data-page-actions-popup' => true], $items));
}

/** The OpenAI mark of the ChatGPT item, as in page-actions.js. */
const ND_OPENAI_MARK = 'M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729zm-9.022 12.6081a4.4755 4.4755 0 0 1-2.8764-1.0408l.1419-.0804 4.7783-2.7582a.7948.7948 0 0 0 .3927-.6813v-6.7369l2.02 1.1686a.071.071 0 0 1 .038.052v5.5826a4.504 4.504 0 0 1-4.4945 4.4944zm-9.6607-4.1254a4.4708 4.4708 0 0 1-.5346-3.0137l.142.0852 4.783 2.7582a.7712.7712 0 0 0 .7806 0l5.8428-3.3685v2.3324a.0804.0804 0 0 1-.0332.0615L9.74 19.9502a4.4992 4.4992 0 0 1-6.1408-1.6464zM2.3408 7.8956a4.485 4.485 0 0 1 2.3655-1.9728V11.6a.7664.7664 0 0 0 .3879.6765l5.8144 3.3543-2.0201 1.1685a.0757.0757 0 0 1-.071 0l-4.8303-2.7865A4.504 4.504 0 0 1 2.3408 7.872zm16.5963 3.8558L13.1038 8.364 15.1192 7.2a.0757.0757 0 0 1 .071 0l4.8303 2.7913a4.4944 4.4944 0 0 1-.6765 8.1042v-5.6772a.79.79 0 0 0-.407-.667zm2.0107-3.0231l-.142-.0852-4.7735-2.7818a.7759.7759 0 0 0-.7854 0L9.409 9.2297V6.8974a.0662.0662 0 0 1 .0284-.0615l4.8303-2.7866a4.4992 4.4992 0 0 1 6.6802 4.66zM8.3065 12.863l-2.02-1.1638a.0804.0804 0 0 1-.038-.0567V6.0742a4.4992 4.4992 0 0 1 7.3757-3.4537l-.142.0805L8.704 5.459a.7948.7948 0 0 0-.3927.6813zm1.0976-2.3654l2.602-1.4998 2.6069 1.4998v2.9994l-2.5974 1.4997-2.6067-1.4997Z';

/** The Anthropic mark of the Claude item, as in page-actions.js. */
const ND_ANTHROPIC_MARK = 'M17.3041 3.541h-3.6718l6.696 16.918H24Zm-10.6082 0L0 20.459h3.7442l1.3693-3.5527h7.0052l1.3693 3.5528h3.7442L10.5363 3.5409Zm-.3712 10.2232 2.2914-5.9456 2.2914 5.9456Z';

function nd_page_actions_mark(string $path): string
{
    return Html::tag('svg', ['role' => 'img', 'viewBox' => '0 0 24 24', 'fill' => 'currentColor', 'aria-hidden' => 'true'], Html::tag('path', ['d' => $path]));
}
