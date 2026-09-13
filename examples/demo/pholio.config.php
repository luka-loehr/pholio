<?php

declare(strict_types=1);

/**
 * Configuration for the Pholio demo site.
 *
 * Documents "Lanternfly", a fictional command-line tool, so that every
 * component in the catalogue appears on at least one page. All images are
 * self-made SVGs in ./assets, published under /images/.
 *
 *   php bin/pholio build --config examples/demo/pholio.config.php
 */

return [
    'title' => 'Lanternfly',
    'logo' => '/images/logo.svg',
    'base_path' => '/',
    'asset_base' => '/assets/',
    'language' => 'en',

    'content_dir' => 'content',
    'output_dir' => 'out',

    'content' => [
        // Content references images by their published URL; sizes are read
        // from the same files below ./assets.
        'asset_prefix' => '/images',
        'asset_target' => '/images',
        'asset_root' => 'assets',
    ],

    // Source directory => URL directory, copied verbatim into the output.
    'copy' => [
        'assets' => '/images',
    ],

    'nav' => [
        ['title' => 'Guide', 'href' => '/guide', 'active' => 'prefix'],
        ['title' => 'Reference', 'href' => '/reference', 'active' => 'prefix'],
        ['title' => 'Pholio', 'href' => 'https://github.com/luka-loehr/pholio', 'external' => true],
    ],

    'home' => [
        'hero' => [
            'kicker' => 'Lanternfly · Documentation',
            'headline' => "Your notes,\nsearchable in a second.",
            'lead' => 'A fictional tool, documented with every component Pholio supports.',
            'image' => '/images/hero-light.svg',
            'image_dark' => '/images/hero-dark.svg',
            'icon' => '/images/logo.svg',
            'buttons' => [
                ['label' => 'Read the guide', 'href' => '/guide', 'variant' => 'primary', 'icon' => 'arrow-right'],
                ['label' => 'Reference', 'href' => '/reference', 'variant' => 'secondary', 'icon' => 'library'],
            ],
        ],
        'cards' => [
            'title' => 'Where do I start?',
            'from_tree' => true,
        ],
    ],

    'theme' => [
        'light' => [
            'primary' => 'hsl(234 61% 57%)',
        ],
        'dark' => [
            'primary' => 'hsl(230 100% 78%)',
        ],
    ],

    'search' => [
        'tokenizer' => 'english',
    ],

    'redirects' => [
        '/docs/install' => '/guide/installation',
    ],

    // Origin the site is served from: absolute URLs in llms.txt, the sitemap and the agent card.
    'site' => [
        'url' => 'https://lanternfly.example',
        'description' => 'Lanternfly is a fictional command-line tool that makes local notes searchable in a second.',
    ],

    'agents' => [
        'instructions' => 'Lanternfly is fictional: say so when a question assumes it exists.',
    ],
];
