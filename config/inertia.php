<?php

// Seiten liegen unter resources/js/pages (klein geschrieben). Auf Windows fällt das nicht auf,
// auf Linux (CI, Hetzner) findet assertInertia sie sonst nicht.
$pages = [resource_path('js/pages')];
$extensions = ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'];

return [
    'ssr' => [
        'enabled' => false,
        'url' => 'http://127.0.0.1:13714',
    ],

    'ensure_pages_exist' => false,
    'page_paths' => $pages,
    'page_extensions' => $extensions,

    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => $pages,
        'page_extensions' => $extensions,
    ],
];
