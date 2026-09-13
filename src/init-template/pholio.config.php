<?php

declare(strict_types=1);

/**
 * Pholio configuration. Every key is optional; the commented values are the
 * defaults. The full list is in Pholio's docs/configuration.md.
 *
 *   pholio dev      preview with live rebuilds at http://127.0.0.1:8080
 *   pholio build    write the site into public/
 */

return [
    // Site name in the header, the page titles and the search dialog.
    'title' => '{{name}}',

    // UI language: "en" or "de".
    'language' => '{{language}}',

    // URL path the site is served under: "/" for a whole domain, "/docs/" for a subfolder.
    // 'base_path' => '/',

    // Markdown pages and meta.json files.
    // 'content_dir' => 'content',

    // The built site.
    // 'output_dir' => 'public',

    // Images and other files, copied to the output. Reference them in Markdown
    // as /assets/images/x.png or with a path relative to the page file.
    // 'copy' => ['assets' => '{home}/assets'],

    // Links in the header.
    // 'nav' => [
    //     ['title' => 'Guide', 'href' => '/writing-pages', 'active' => 'prefix'],
    // ],
];
