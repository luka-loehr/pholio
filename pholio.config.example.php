<?php

declare(strict_types=1);

/**
 * Pholio configuration — copy to pholio.config.php and edit.
 *
 *   php bin/pholio build --config pholio.config.php
 *
 * The file returns one plain array. No classes, no environment lookups, no
 * side effects: the configuration is data, exactly like the content is data.
 * Every key below is documented; keys marked "planned" are accepted by the
 * schema but not yet honoured by the generator.
 */

return [
    // ---------------------------------------------------------------- site --

    // Shown in the header next to the logo, in <title> and in the search dialog.
    'title' => 'My Documentation',

    // Path to the logo, relative to this file. Rendered 24px, rounded.
    // Use null for a wordmark without a logo.
    'logo' => 'assets/logo.svg',

    // Absolute base URL of the published site, without a trailing slash.
    // Used for canonical links, Open Graph URLs and the sitemap.
    'base_url' => 'https://example.org',

    // Path prefix the site is served under, with leading and trailing slash.
    // "/" when the docs are the whole site, "/docs/" when they sit in a subfolder.
    'base_path' => '/docs/',

    // Where CSS, JS, fonts and images are served from. Relative to base_path
    // unless it starts with a scheme or a slash.
    'asset_base' => 'assets/',

    // Content language. Drives <html lang>, the UI translations and the search
    // tokenizer. Shipped translations: "en", "de".
    'language' => 'de',

    // ------------------------------------------------------------ in / out --

    // Directory holding the Markdown sources and the meta.json tree files.
    'content_dir' => __DIR__ . '/content',

    // Directory the static site is written to. Emptied of generated files on
    // every build; anything Pholio did not write is left alone.
    'output_dir' => __DIR__ . '/public/docs',

    // -------------------------------------------------------------- header --

    // Primary navigation, rendered in the header and in the mobile drawer.
    // "active" marks the entry as current when the path starts with the prefix.
    'nav' => [
        ['title' => 'Documentation', 'href' => '/docs/', 'active' => 'prefix'],
        ['title' => 'Website', 'href' => 'https://example.org'],
        // Icon-only links sit on the right; "icon" is a lucide icon name.
        ['title' => 'GitHub', 'href' => 'https://github.com/you/repo', 'icon' => 'github', 'icon_only' => true],
    ],

    // ---------------------------------------------------------- start page --

    // The landing page. Set to null to make the first content page the root.
    'home' => [
        'hero' => [
            // Small uppercase line above the headline.
            'kicker' => 'Project · Documentation',
            // The headline. "\n" becomes a line break.
            'headline' => "Everything you need,\nin one place.",
            'lead' => 'Guides, reference and background for users and administrators.',
            // Background images. The dark twin is optional but recommended.
            'image' => 'brand/hero-light.webp',
            'image_dark' => 'brand/hero-dark.webp',
            // Square icon shown above the kicker, 48px.
            'icon' => 'brand/app-icon.webp',
            'buttons' => [
                ['label' => 'Start reading', 'href' => '/docs/intro/', 'variant' => 'primary', 'icon' => 'arrow-right'],
                ['label' => 'Whitepaper (PDF)', 'href' => '/whitepaper.pdf', 'variant' => 'secondary', 'icon' => 'file-text'],
            ],
        ],

        // The card grid below the hero. Leave the list empty to omit the
        // section; set 'from_tree' => true to derive the cards from the
        // root folders' meta.json (title, description, icon).
        'cards' => [
            'title' => 'Where do I start?',
            'from_tree' => true,
            'items' => [
                // ['title' => 'Users', 'description' => 'Day-to-day guides.',
                //  'href' => '/docs/basics/', 'icon' => 'book-open'],
            ],
        ],
    ],

    // --------------------------------------------------------------- theme --

    // Palette overrides. Every key is a Fumadocs colour token without the
    // "--color-fd-" prefix; values are CSS colours in any notation. Anything
    // omitted keeps the built-in default. Opacity variants are derived, so do
    // not list them here.
    'theme' => [
        'light' => [
            'primary' => 'hsl(220 85% 45%)',
            'background' => 'hsl(0 0% 100%)',
            'sidebar-background' => 'hsl(0 0% 97.5%)',
        ],
        'dark' => [
            'primary' => 'hsl(220 90% 70%)',
            'background' => 'hsl(0 0% 4%)',
            'sidebar-background' => 'hsl(0 0% 9%)',
        ],

        // Default colour scheme before the visitor chooses: "light", "dark"
        // or "system".
        'default_scheme' => 'system',

        // Extra stylesheet appended after the generated CSS, for the few rules
        // a palette cannot express. Relative to this file.
        'custom_css' => null,
    ],

    // -------------------------------------------------------------- search --

    'search' => [
        // Off removes the search field, the ⌘K hotkey and the index file.
        'enabled' => true,

        // Where the client-side index is written, relative to output_dir.
        'index_path' => 'search-index.json',

        // Tokenizer profile. "german" and "english" differ only in the
        // character set; neither stems, matching the Fumadocs default.
        'tokenizer' => 'german',
    ],

    // ----------------------------------------------------------- redirects --

    // Old path => new path. Written as 301 rules into the generated .htaccess
    // and, where the host supports it, a _redirects file.
    'redirects' => [
        '/docs/install' => '/guide/installation/',
    ],

    // ------------------------------------------------------------- planned --

    // Slot overrides: a slot name mapped to a PHP file that returns HTML.
    // Slots: nav, sidebar.banner, sidebar.footer, toc.header, page.footer.
    'slots' => [
        // 'sidebar.footer' => __DIR__ . '/slots/sidebar-footer.php',
    ],

    // Extra component tags, tag name => callable returning HTML (planned).
    'components' => [
        // 'Download' => require __DIR__ . '/components/download.php',
    ],

    // Fail the build when a page has no description, no headings, or an empty
    // body. Off by default because it is stricter than most sites need.
    'strict_content' => false,
];
