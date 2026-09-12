<?php

declare(strict_types=1);

/**
 * Configuration for the Pholio demo site.
 *
 * Documents "Lanternfly", a fictional command-line tool, so that every
 * component in the catalogue appears on at least one page. All images are
 * self-made SVGs in ./assets.
 *
 *   php ../../bin/pholio build --config pholio.config.php
 */

return [
    'title' => 'Lanternfly',
    'logo' => __DIR__ . '/assets/logo.svg',
    'base_url' => 'http://localhost:8080',
    'base_path' => '/',
    'asset_base' => 'assets/',
    'language' => 'en',

    'content_dir' => __DIR__ . '/content',
    'output_dir' => __DIR__ . '/out',

    'nav' => [
        ['title' => 'Guide', 'href' => '/guide/', 'active' => 'prefix'],
        ['title' => 'Reference', 'href' => '/reference/', 'active' => 'prefix'],
        ['title' => 'Pholio', 'href' => 'https://github.com/luka-loehr/pholio', 'icon' => 'github', 'icon_only' => true],
    ],

    'home' => [
        'hero' => [
            'kicker' => 'Lanternfly · Documentation',
            'headline' => "Your notes,\nsearchable in a second.",
            'lead' => 'A fictional tool, documented with every component Pholio supports.',
            'image' => __DIR__ . '/assets/hero-light.svg',
            'image_dark' => __DIR__ . '/assets/hero-dark.svg',
            'icon' => __DIR__ . '/assets/logo.svg',
            'buttons' => [
                ['label' => 'Read the guide', 'href' => '/guide/', 'variant' => 'primary', 'icon' => 'arrow-right'],
                ['label' => 'Reference', 'href' => '/reference/', 'variant' => 'secondary', 'icon' => 'library'],
            ],
        ],
        'cards' => [
            'title' => 'Where do I start?',
            'from_tree' => true,
            'items' => [],
        ],
    ],

    'theme' => [
        'light' => [
            'primary' => 'hsl(234 61% 57%)',
        ],
        'dark' => [
            'primary' => 'hsl(230 100% 78%)',
        ],
        'default_scheme' => 'system',
        'custom_css' => null,
    ],

    'search' => [
        'enabled' => true,
        'index_path' => 'search-index.json',
        'tokenizer' => 'english',
    ],

    'redirects' => [
        '/docs/install' => '/guide/installation/',
    ],

    'slots' => [],
    'components' => [],
    'strict_content' => true,
];
