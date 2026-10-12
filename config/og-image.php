<?php

return [
    'debug' => env('OG_IMAGE_DEBUG', false), // disable caching og images for development

    'extension' => 'jpg', // jpg, png, webp

    'quality' => 90, // 0-100, for jpg and webp

    'width' => 1200,
    'height' => 630,

    'chrome' => [
        // Connect to an already running Chrome instead of launching one for every image, which is
        // faster. Its DevTools address, e.g. http://127.0.0.1:9222 (or a ws://.../devtools/browser/...
        // URL), started with --remote-allow-origins=http://127.0.0.1 (see the README). Falls back to
        // launching Chrome from the path below when it can't be reached.
        'url' => env('CHROME_URL'),
        'path' => env('CHROME_PATH', 'chromium'),
        'flags' => [
            // '--disable-dev-shm-usage',
            // '--disable-gpu',
            // '--disable-setuid-sandbox',
            // '--disable-software-rasterizer',
            // '--hide-scrollbars',
            // '--mute-audio',
            // '--no-sandbox',
        ],
    ],

    // The cache location to use.
    'storage' => [
        'disk' => 'public',
        'path' => 'og-images',
    ],

    'metatags' => [
        'og:title' => 'title',
        'og:description' => 'description',
        'og:type' => 'type',
        'og:url' => 'url',
    ],
];
