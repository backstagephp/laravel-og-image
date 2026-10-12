<?php

use Backstage\OgImage\Laravel\Facades\OgImage;
use Backstage\OgImage\Laravel\OgImage as OgImageService;
use HeadlessChromium\Browser;
use HeadlessChromium\Browser\ProcessAwareBrowser;
use HeadlessChromium\Communication\Connection;
use HeadlessChromium\Exception\BrowserConnectionFailed;
use HeadlessChromium\Page;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

$chromeBinary = fn (): string => (string) env('CHROME_PATH', 'chromium');

$hasChromeBinary = fn (): bool => is_executable($chromeBinary()) || ! empty(shell_exec('command -v '.escapeshellarg($chromeBinary())));

it('uses a ws:// url of a running Chrome as is', function (): void {
    Http::fake();

    $url = 'ws://127.0.0.1:9222/devtools/browser/0c1e5f3a-1b2c-4d5e-8f90-123456789abc';

    expect(OgImage::getBrowserWebSocketUrl($url))->toBe($url);

    Http::assertNothingSent();
});

it('resolves the ws:// url of a running Chrome from its http address', function (): void {
    $webSocketUrl = 'ws://127.0.0.1:9222/devtools/browser/0c1e5f3a-1b2c-4d5e-8f90-123456789abc';

    Http::fake([
        'http://127.0.0.1:9222/json/version' => Http::response([
            'Browser' => 'HeadlessChrome/141.0.0.0',
            'webSocketDebuggerUrl' => $webSocketUrl,
        ]),
    ]);

    expect(OgImage::getBrowserWebSocketUrl('http://127.0.0.1:9222/'))->toBe($webSocketUrl);

    Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:9222/json/version');
});

it('fails when the http address does not return a ws:// url', function (): void {
    Http::fake(['*' => Http::response(['Browser' => 'HeadlessChrome/141.0.0.0'])]);

    OgImage::getBrowserWebSocketUrl('http://127.0.0.1:9222');
})->throws(BrowserConnectionFailed::class);

it('launches Chrome when no running Chrome is configured', function (): void {
    config(['og-image.chrome.url' => null]);

    $launched = Mockery::mock(Browser::class);

    $og = Mockery::mock(OgImageService::class)->makePartial();
    $og->shouldNotReceive('connectToBrowser');
    $og->shouldReceive('launchBrowser')->once()->andReturn($launched);

    expect($og->getBrowser())->toBe($launched);
});

it('falls back to launching Chrome when the running Chrome cannot be reached', function (string $url): void {
    // Nothing listens on port 1, so connecting fails right away.
    config(['og-image.chrome.url' => $url]);

    $this->mock(ExceptionHandler::class)->shouldReceive('report')->once();

    $launched = Mockery::mock(Browser::class);

    $og = Mockery::mock(OgImageService::class)->makePartial();
    $og->shouldReceive('launchBrowser')->once()->andReturn($launched);

    expect($og->getBrowser())->toBe($launched);
})->with([
    'http address' => 'http://127.0.0.1:1',
    'ws:// url' => 'ws://127.0.0.1:1/devtools/browser/0c1e5f3a-1b2c-4d5e-8f90-123456789abc',
]);

it('closes only its tab, not a running Chrome it connected to, also when the screenshot fails', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('disconnect')->once();

    $page = Mockery::mock(Page::class);
    $page->shouldReceive('setHtml')->andThrow(new RuntimeException('Page failed to load'));
    $page->shouldReceive('close')->once();

    $browser = Mockery::mock(Browser::class);
    $browser->shouldReceive('createPage')->andReturn($page);
    $browser->shouldReceive('getConnection')->andReturn($connection);
    $browser->shouldNotReceive('close');

    $og = Mockery::mock(OgImageService::class)->makePartial();
    $og->shouldReceive('getBrowser')->andReturn($browser);

    $og->takeScreenshot('<h1>title</h1>');
})->throws(RuntimeException::class, 'Page failed to load');

it('closes a Chrome it launched, also when the screenshot fails', function (): void {
    $page = Mockery::mock(Page::class);
    $page->shouldReceive('setHtml')->andThrow(new RuntimeException('Page failed to load'));

    $browser = Mockery::mock(ProcessAwareBrowser::class);
    $browser->shouldReceive('createPage')->andReturn($page);
    $browser->shouldReceive('close')->once();

    $og = Mockery::mock(OgImageService::class)->makePartial();
    $og->shouldReceive('getBrowser')->andReturn($browser);

    $og->takeScreenshot('<h1>title</h1>');
})->throws(RuntimeException::class, 'Page failed to load');

it('generates images in a running Chrome and keeps it running', function () use ($chromeBinary): void {
    // Pick a free port for Chrome's DevTools endpoint.
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    $userDataDir = sys_get_temp_dir().'/og-image-chrome-'.uniqid();

    $chrome = new Process([
        $chromeBinary(),
        '--headless',
        '--remote-debugging-address=127.0.0.1',
        '--remote-debugging-port='.$port,
        '--remote-allow-origins=http://127.0.0.1',
        '--user-data-dir='.$userDataDir,
        '--no-first-run',
        '--no-default-browser-check',
        ...config('og-image.chrome.flags'),
        'about:blank',
    ]);
    $chrome->start();

    $url = 'http://127.0.0.1:'.$port;
    $tabs = fn (): int => collect(Http::get($url.'/json/list')->json())->where('type', 'page')->count();

    try {
        // Wait for Chrome to listen, but stop waiting when it exited.
        retry(50, fn () => Http::connectTimeout(1)->get($url.'/json/version')->throw(), 200, fn (): bool => $chrome->isRunning());

        $tabsBefore = $tabs();

        // A path that can't be launched proves the images are made in the running Chrome.
        config(['og-image.chrome.url' => $url, 'og-image.chrome.path' => '/nonexistent/chrome']);

        $first = OgImage::takeScreenshot('<h1>first</h1>');
        $second = OgImage::takeScreenshot('<h1>second</h1>');

        // Older chrome-php versions don't wait for Chrome to destroy a closed tab, so give it a moment.
        $tabsAfter = retry(20, function () use ($tabs, $tabsBefore): int {
            throw_unless(($count = $tabs()) === $tabsBefore, RuntimeException::class, "$count tabs open");

            return $count;
        }, 100, fn (): bool => true);

        expect($first)->toBeString()->not->toBeEmpty()
            ->and($second)->toBeString()->not->toBeEmpty()
            ->and($chrome->isRunning())->toBeTrue()
            ->and($tabsAfter)->toBe($tabsBefore);
    } finally {
        $chrome->stop();
        File::deleteDirectory($userDataDir);
    }
})->skip(fn (): bool => ! $hasChromeBinary(), 'No Chrome/Chromium binary available');
