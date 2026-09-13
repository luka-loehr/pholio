<?php

declare(strict_types=1);

/**
 * Pholio configuration: copy this file next to your content, rename it and edit.
 *
 *   php vendor/pholio/bin/pholio build --config docs.config.php
 *
 * The file returns one plain array. No classes, no environment lookups, no side
 * effects: the configuration is data, like the content. docs/configuration.md
 * documents every key. Paths are relative to this file; URL paths start with "/"
 * and generated URLs have no trailing slash.
 *
 * Keys marked "planned" are part of the schema but not implemented yet. They are
 * shown with their default, the only value accepted today.
 */

return [
    // ---------------------------------------------------------------- site --

    // Site name: header wordmark, <title> and the {site} placeholder.
    'title' => 'Example Docs',

    // Page <title> pattern. {title} is the page title.
    'title_template' => '{title} – {site}',

    // Logo URL shown next to the wordmark, and its size in pixels.
    // null shows the wordmark only.
    'logo' => '/images/logo.svg',
    'logo_size' => 24,

    // Where the wordmark links.
    'home_link' => '{home}',

    // Planned: absolute origin for canonical links, Open Graph and a sitemap.
    'base_url' => null,

    // URL of the start page ({home}) and of the docs root ({docs}). docs_path
    // null means the docs root is the start page URL.
    'base_path' => '/',
    'docs_path' => null,

    // Appended to the docs root page's URL when it would otherwise collide with
    // the start page, e.g. '/overview'.
    'docs_root_suffix' => '/overview',

    // URL of the theme's CSS, JS, fonts and licences ({assets}). Relative to
    // base_path unless it starts with "/" or a scheme.
    'asset_base' => 'assets/',

    // Interface language: "en" or "de". Also picks the default search tokenizer.
    'language' => 'en',

    // Interface string overrides, translation key => text.
    'translations' => [
        // 'Search(search dialog)' => 'Find',
    ],

    // ------------------------------------------------------------ in / out --

    'content_dir' => 'content',

    // Directory of the start page's index.html. The build writes into it and
    // deletes nothing; `pholio check` reports files it did not write.
    'output_dir' => 'public',

    'content' => [
        'extensions' => ['md'],

        // Internal links written as /handbook/... in the content point at
        // {docs}/... in the output. null leaves links as written.
        'link_prefix' => null,

        // Image sources starting with asset_prefix are rewritten to asset_target;
        // asset_root is the directory behind asset_target, used for image sizes.
        'asset_prefix' => '/images',
        'asset_target' => '/images',
        'asset_root' => 'images',

        // Extra frontmatter names mapped onto allowed ones.
        'frontmatter_aliases' => [
            // 'date' => 'updated',
        ],
    ],

    // Source directory => URL directory, copied verbatim (without *.md files).
    'copy' => [
        'images' => '/images',
    ],

    'output' => [
        // Paths below output_dir that Pholio neither writes nor reports.
        'keep' => [
            // 'downloads',
        ],
    ],

    // -------------------------------------------------------------- header --

    // "active": "exact" (default) or "prefix" (also current below the URL).
    'nav' => [
        ['title' => 'Guide', 'href' => '/guide', 'active' => 'prefix'],
        ['title' => 'Source', 'href' => 'https://example.org/source', 'external' => true],
        // Planned: 'icon' => null, 'icon_only' => false.
    ],

    // ---------------------------------------------------------- start page --

    // The landing page at base_path. null makes the first content page the root.
    'home' => [
        'title' => '{site}',
        'hero' => [
            'kicker' => '{site} · Documentation',
            // "\n" starts a new line.
            'headline' => "Everything you need,\nin one place.",
            'lead' => 'Guides and reference, written for the people who use it.',
            'image' => '/images/hero-light.svg',
            'image_dark' => '/images/hero-dark.svg',
            'icon' => '/images/logo.svg',
            'icon_size' => 48,
            'buttons' => [
                ['label' => 'Start reading', 'href' => '/guide', 'variant' => 'primary', 'icon' => 'arrow-right'],
                ['label' => 'Reference', 'href' => '/reference', 'variant' => 'secondary', 'icon' => null],
            ],
        ],
        'cards' => [
            'title' => 'Where do I start?',
            // null uses the translated default link text.
            'link_label' => null,
            // true: one card per root folder, from its meta.json. items is then
            // ignored; with false, items lists the cards explicitly.
            'from_tree' => true,
            'items' => [
                // ['title' => 'Guide', 'description' => 'Day-to-day tasks.', 'href' => '/guide', 'icon' => 'book-open'],
            ],
        ],
    ],

    // --------------------------------------------------------------- theme --

    'theme' => [
        // Colour tokens without the "--color-fd-" prefix, written at the palette
        // marker in theme/css/tokens.css.
        'light' => [
            'primary' => 'hsl(220 85% 45%)',
        ],
        'dark' => [
            'primary' => 'hsl(220 90% 70%)',
        ],

        // Stylesheet inserted at the same marker, after the token maps.
        'palette_css' => null,

        // <html data-preset> and an extra class on <html>.
        'preset' => null,
        'font_class' => '',

        // Key that toggles the colour scheme.
        'hotkey' => 'd',

        // Planned: "light" or "dark" as the initial scheme.
        'default_scheme' => 'system',

        // Planned: stylesheet appended after the theme CSS.
        'custom_css' => null,
    ],

    'head' => [
        'icons' => [
            // ['rel' => 'icon', 'type' => 'image/svg+xml', 'sizes' => null, 'href' => '/images/logo.svg'],
        ],
        'manifest' => null,
        'theme_color' => null,
    ],

    // -------------------------------------------------------------- search --

    'search' => [
        // Planned: false removes the field, the hotkey and the index.
        'enabled' => true,

        // Index file, relative to the docs root.
        'index_path' => 'search-index.json',

        // "english" or "german"; null follows language. Neither stems.
        'tokenizer' => null,

        // Keys shown in the search field.
        'hotkey' => ['⌘', 'K'],
    ],

    // -------------------------------------------------------------- server --

    // Old path => new path, written as 301 rules into the generated .htaccess.
    // A list of ['from' => ..., 'to' => ..., 'reason' => ...] works as well.
    'redirects' => [
        '/install' => '/guide/installation',
    ],

    // JSON list of {from, to, reason}, appended to redirects.
    'redirects_file' => null,

    'server' => [
        'htaccess' => true,
        // 'csp' => "default-src 'self'; ...",   the default is strict; no double quotes
    ],

    // ------------------------------------------------------------ profiles --

    // Merged recursively over this file with --profile <name>.
    'profiles' => [
        'staging' => [
            'base_path' => '/staging/',
            'output_dir' => 'public-staging',
        ],
    ],

    // ------------------------------------------------------------- planned --

    'slots' => [],
    'components' => [],
    'strict_content' => false,
];
