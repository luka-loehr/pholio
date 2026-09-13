<?php

declare(strict_types=1);

// Theme CSS rules the layout depends on: no scroller rubber-bands or chains its scroll into the page.

require __DIR__ . '/run.php';

const THEME_CSS_DIR = __DIR__ . '/../theme/css';

/** The declarations of every rule whose selector list is exactly $selector, joined. */
function theme_css_declarations(string $selector): string
{
    $out = '';
    foreach (glob(THEME_CSS_DIR . '/*.css') ?: [] as $file) {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($file));
        if (preg_match_all('/(?:^|[{};])\s*' . preg_quote($selector, '/') . '\s*\{([^{}]*)\}/', $css, $m) > 0) {
            $out .= implode("\n", $m[1]);
        }
    }

    return $out;
}

$overscroll = [
    'html, body' => 'none',
    '.nd-scroll-viewport' => 'contain',
    '.nd-toc-scroll' => 'contain',
    '.nd-search-list' => 'contain',
    '.nd-popover' => 'contain',
];

foreach ($overscroll as $selector => $value) {
    test("{$selector} has overscroll-behavior: {$value}", function () use ($selector, $value): void {
        $declarations = theme_css_declarations($selector);
        assert_true($declarations !== '', "no rule for {$selector} in theme/css");
        assert_contains("overscroll-behavior: {$value};", $declarations, $selector);
    });
}
