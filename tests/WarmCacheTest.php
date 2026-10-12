<?php

use Backstage\OgImage\Laravel\Crawler\CrawlInternalPages;
use Backstage\OgImage\Laravel\Crawler\ImageUrlCollector;
use Backstage\OgImage\Laravel\Facades\OgImage;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    Storage::fake(config('og-image.storage.disk'));

    // Chrome is not what's tested here: "draw" every image as its HTML.
    $this->screenshots = 0;

    OgImage::partialMock()
        ->shouldReceive('takeScreenshot')
        ->andReturnUsing(function (string $html, ?string $path = null) {
            $this->screenshots++;

            if ($path === null) {
                return $html;
            }

            file_put_contents($path, $html);

            return null;
        });
});

/** A signed image URL, as the package puts in og:image. */
function imageUrl(string $title): string
{
    return OgImage::url(['title' => $title]);
}

/** Serve the pages (path => HTML) on a free port with PHP's built-in server; returns its URL and the process. */
function servePages(array $pages): array
{
    $root = sys_get_temp_dir().'/og-image-site-'.uniqid();

    foreach ($pages as $path => $html) {
        File::ensureDirectoryExists(dirname($root.$path));
        file_put_contents($root.$path, $html);
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    // Without a router, PHP's server answers a missing file with index.html.
    file_put_contents($root.'/router.php', '<?php if (is_file(__DIR__.parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH))) { return false; } http_response_code(404);');

    $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $root, $root.'/router.php']);
    $server->start();

    $url = 'http://127.0.0.1:'.$port;

    retry(50, function () use ($url): void {
        throw_unless(@file_get_contents($url.'/index.html') !== false, RuntimeException::class, 'Server not ready');
    }, 100);

    return [$url, $server, $root];
}

function page(string $imageUrl, string $links = ''): string
{
    return '<!DOCTYPE html><html><head>'
        .'<meta property="og:image" content="'.e($imageUrl).'">'
        .'<meta name="twitter:image" content="'.e($imageUrl).'">'
        .'</head><body>'.$links.'</body></html>';
}

it('collects the og:image and twitter:image urls of a page, with entities decoded', function (): void {
    $html = '<html><head>'
        .'<meta property="og:image" content="https://example.com/og-image?title=a&amp;signature=b&amp;.png">'
        .'<meta name="twitter:image" content="https://example.com/twitter.png">'
        .'<meta property="og:title" content="Not an image">'
        .'</head></html>';

    expect(ImageUrlCollector::imageUrls($html))->toBe([
        'https://example.com/og-image?title=a&signature=b&.png',
        'https://example.com/twitter.png',
    ])->and(ImageUrlCollector::imageUrls(''))->toBe([]);
});

it('knows the image urls of this application', function (): void {
    expect(OgImage::isImageUrl(imageUrl('title')))->toBeTrue()
        ->and(OgImage::isImageUrl('https://example.com/images/share.png'))->toBeFalse();
});

it('generates the image of a signed url once', function (): void {
    $url = imageUrl('Hello');

    expect(OgImage::createImageFromUrl($url))->toBeTrue()
        ->and(OgImage::createImageFromUrl($url))->toBeFalse()
        ->and($this->screenshots)->toBe(1);

    // The image is the one a visit to the url gets, cached under its signature.
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect(Storage::disk(config('og-image.storage.disk'))->get(OgImage::getStorageImageFilePath($query['signature'])))
        ->toContain('Hello');
});

it('refuses an image url without a valid signature', function (string $url): void {
    OgImage::createImageFromUrl($url);
})->with([
    'unsigned' => fn () => route('og-image.file', ['title' => 'Hello']),
    'tampered' => fn () => str_replace('Hello', 'Bye', imageUrl('Hello')),
    'elsewhere' => 'https://example.com/images/share.png',
])->throws(InvalidArgumentException::class);

it('crawls the site and generates the images of its pages', function (): void {
    [$url, $server, $root] = servePages([
        '/index.html' => page(imageUrl('Home'), '<a href="/about.html">About</a> <a href="/static.html">Static</a> <a href="https://example.com/">Elsewhere</a>'),
        '/about.html' => page(imageUrl('About'), '<a href="/index.html">Home</a>'),
        '/static.html' => page('https://example.com/share.png'),
    ]);

    try {
        $this->artisan('og-image:warm', ['url' => $url.'/index.html'])
            ->expectsOutputToContain('found 2 images')
            ->expectsOutputToContain('2 generated, 0 already cached, 0 failed')
            ->assertSuccessful();

        // The second time, everything is in the cache already.
        $this->artisan('og-image:warm', ['url' => $url.'/index.html'])
            ->expectsOutputToContain('0 generated, 2 already cached, 0 failed')
            ->assertSuccessful();

        expect($this->screenshots)->toBe(2);
    } finally {
        $server->stop();
        File::deleteDirectory($root);
    }
});

it('fails when an image can not be generated', function (): void {
    [$url, $server, $root] = servePages([
        '/index.html' => page(str_replace('Home', 'Tampered', imageUrl('Home'))),
    ]);

    try {
        $this->artisan('og-image:warm', ['url' => $url.'/index.html'])
            ->expectsOutputToContain('0 generated, 0 already cached, 1 failed')
            ->assertFailed();
    } finally {
        $server->stop();
        File::deleteDirectory($root);
    }
});

it('does not warm in debug mode, where images are not cached', function (): void {
    config(['og-image.debug' => true]);

    $this->artisan('og-image:warm', ['url' => 'http://127.0.0.1:1'])->assertFailed();
});

it('crawls pages, not the assets they link to', function (string $path, bool $crawled): void {
    $profile = new CrawlInternalPages('https://example.com');

    expect($profile->shouldCrawl(new Uri('https://example.com'.$path)))->toBe($crawled);
})->with([
    ['/', true],
    ['/about', true],
    ['/about.html', true],
    ['/index.php?page=2', true],
    ['/images/photo.jpg', false],
    ['/build/video.mp4', false],
    ['/build/app.js', false],
]);

it('warns about pages it can not crawl, without failing', function (): void {
    [$url, $server, $root] = servePages([
        '/index.html' => page(imageUrl('Home'), '<a href="/missing.html">Missing</a>'),
    ]);

    try {
        $this->artisan('og-image:warm', ['url' => $url.'/index.html'])
            ->expectsOutputToContain('Could not crawl '.$url.'/missing.html')
            ->expectsOutputToContain('1 generated, 0 already cached, 0 failed')
            ->assertSuccessful();
    } finally {
        $server->stop();
        File::deleteDirectory($root);
    }
});
