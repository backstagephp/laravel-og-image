<?php

namespace Backstage\OgImage\Laravel;

use Backstage\OgImage\Laravel\Http\Controllers\OgImageController;
use HeadlessChromium\Browser;
use HeadlessChromium\Browser\ProcessAwareBrowser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Exception\BrowserConnectionFailed;
use HeadlessChromium\Page;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\View\ComponentAttributeBag;
use InvalidArgumentException;
use Throwable;

class OgImage
{
    public function routes(): void
    {
        if (app()->environment('local')) {
            Route::get('og-image/preview', [OgImageController::class, '__invoke'])->name('og-image.html');
        }

        Route::get('og-image', [OgImageController::class, '__invoke'])->name('og-image.file');
    }

    public function getImageExtension(): string
    {
        return config('og-image.extension');
    }

    /**
     * The format Chrome takes the screenshot in, following the configured
     * extension: png, jpeg (for jpg/jpeg) or webp.
     */
    public function getImageFormat(): string
    {
        return match (strtolower($this->getImageExtension())) {
            'jpg', 'jpeg' => 'jpeg',
            'webp' => 'webp',
            default => 'png',
        };
    }

    public function getImageMimeType(): string
    {
        return 'image/'.$this->getImageFormat();
    }

    /**
     * The options for Chrome's screenshot: the format, plus the quality (0-100)
     * for the lossy formats, jpeg and webp.
     *
     * @return array{format: string, quality?: int}
     */
    public function getScreenshotOptions(): array
    {
        $format = $this->getImageFormat();

        if ($format === 'png') {
            return ['format' => $format];
        }

        return ['format' => $format, 'quality' => (int) config('og-image.quality', 90)];
    }

    public function getStorageDisk(): FilesystemAdapter
    {
        return Storage::disk(config('og-image.storage.disk'));
    }

    public function getStoragePath(?string $folder = null): string
    {
        return rtrim(config('og-image.storage.path')).($folder ? '/'.$folder : '');
    }

    public function getStorageImageFileName(string $signature): string
    {
        return $signature.'.'.$this->getImageExtension();
    }

    public function getStorageImageFilePath(string $signature): string
    {
        return $this->getStoragePath('images').'/'.$this->getStorageImageFileName($signature);
    }

    public function getStorageImageFileExists(string $signature): bool
    {
        if (config('og-image.debug') === true) {
            return false;
        }

        return $this->getStorageDisk()
            ->exists($this->getStorageImageFilePath($signature));
    }

    public function getStorageImageFileData(string $signature): ?string
    {
        return $this->getStorageDisk()
            ->get($this->getStorageImageFilePath($signature));
    }

    public function getStorageViewFileName(string $signature): string
    {
        return $signature.'.blade.php';
    }

    public function getStorageViewFilePath(string $signature, ?string $folder = null): string
    {
        return $this->getStoragePath('views').'/'.$this->getStorageViewFileName($signature);
    }

    public function getStorageViewFileData(string $signature): string
    {
        return $this->getStorageDisk()
            ->get($this->getStorageViewFilePath($signature));
    }

    public function getStorageViewFileExists(string $signature): bool
    {
        return $this->getStorageDisk()
            ->exists($this->getStorageViewFilePath($signature));
    }

    public function ensureDirectoryExists(string $folder = ''): void
    {
        // The storage path is relative to the configured disk, not to the working directory.
        $disk = $this->getStorageDisk();

        if (! $disk->directoryExists($this->getStoragePath($folder))) {
            $disk->makeDirectory($this->getStoragePath($folder));
        }
    }

    public function transformAttributeBagToArray(ComponentAttributeBag $attributes): array
    {
        return collect($attributes)->all();
    }

    public function url(array|ComponentAttributeBag $parameters): string
    {
        if ($parameters instanceof ComponentAttributeBag) {
            $parameters = $this->transformAttributeBagToArray($parameters);
        }

        $parameters = collect($parameters)
            ->merge(['.'.config('og-image.extension')]) // add image extension to url for twitter compatibility
            ->all();

        return url()
            ->signedRoute('og-image.file', $parameters);
    }

    public function getSignature(array|ComponentAttributeBag $parameters): string
    {
        if ($parameters instanceof ComponentAttributeBag) {
            $parameters = $this->transformAttributeBagToArray($parameters);
        }

        $url = $this->url($parameters);

        $query = parse_url($url, PHP_URL_QUERY);

        parse_str((string) $query, $parameters);

        return $parameters['signature'] ?? '';
    }

    public function createImageFromParams(array $parameters, ?string $template = null, bool $returnImage = false): ?string
    {
        $signature = $this->getSignature($parameters);

        if (OgImage::getStorageImageFileExists($signature) && ! ($returnImage && config('og-image.debug') === true)) {
            return $returnImage
                ? Storage::disk(config('og-image.storage.disk'))->get(OgImage::getStorageImageFilePath($signature))
                : Storage::disk(config('og-image.storage.disk'))->url(OgImage::getStorageImageFilePath($signature));
        }

        $view = (! empty($template) && View::exists($template)) ? $template : 'og-image::template';
        $html = View::make($view, $parameters)->render();

        // Debug mode: never persist. Return the bytes in memory when the caller
        // wants the image; there is no stored file to return a URL for otherwise.
        if (config('og-image.debug') === true) {
            return $returnImage ? OgImage::takeScreenshot($html) : null;
        }

        OgImage::saveImage($html, $signature);

        return $returnImage
            ? Storage::disk(config('og-image.storage.disk'))->get(OgImage::getStorageImageFilePath($signature))
            : Storage::disk(config('og-image.storage.disk'))->url(OgImage::getStorageImageFilePath($signature));
    }

    public function saveImage(string $html, string $filename): void
    {
        if (OgImage::getStorageImageFileExists($filename)) {
            return;
        }

        OgImage::ensureDirectoryExists('images');

        $this->takeScreenshot(
            $html,
            $this->getStorageDisk()->path(OgImage::getStorageImageFilePath($filename)),
        );
    }

    /**
     * Render $html to an image. When $path is given the image is written there;
     * when it is null the raw image bytes are returned instead (no disk write),
     * which debug mode uses to avoid persisting anything locally.
     */
    public function takeScreenshot(string $html, ?string $path = null): ?string
    {
        $browser = $this->getBrowser();
        $page = null;

        try {
            $page = $browser->createPage();

            return $this->renderScreenshot($page, $html, $path);
        } finally {
            $this->releaseBrowser($browser, $page);
        }
    }

    /**
     * The browser to take the screenshot with: the running Chrome configured in
     * og-image.chrome.url when it can be reached, so only a tab is opened per
     * image, otherwise a newly launched Chrome. A Chrome service that is down
     * is reported but never breaks image generation.
     */
    public function getBrowser(): Browser
    {
        $url = (string) config('og-image.chrome.url');

        if ($url !== '') {
            try {
                return $this->connectToBrowser($url);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $this->launchBrowser();
    }

    /**
     * Connect to an already running Chrome, which saves launching and closing
     * a browser process for every image.
     */
    public function connectToBrowser(string $url): Browser
    {
        return BrowserFactory::connectToBrowser($this->getBrowserWebSocketUrl($url));
    }

    /**
     * Resolve the browser's DevTools WebSocket URL. A ws:// URL is used as is;
     * for an http:// address (e.g. http://127.0.0.1:9222) Chrome is asked for
     * it, as the URL contains an id that changes every time Chrome starts.
     */
    public function getBrowserWebSocketUrl(string $url): string
    {
        if (preg_match('#^wss?://#i', $url)) {
            return $url;
        }

        $webSocketUrl = Http::connectTimeout(2)
            ->timeout(5)
            ->get(rtrim($url, '/').'/json/version')
            ->throw()
            ->json('webSocketDebuggerUrl');

        if (! is_string($webSocketUrl) || $webSocketUrl === '') {
            throw new BrowserConnectionFailed("No webSocketDebuggerUrl found at {$url}/json/version");
        }

        return $webSocketUrl;
    }

    /**
     * Launch a new Chrome for a single image; it is closed again afterwards.
     */
    public function launchBrowser(): Browser
    {
        $binary = (string) config('og-image.chrome.path');

        $browserFactory = new BrowserFactory($binary);

        return $browserFactory->createBrowser([
            'customFlags' => config('og-image.chrome.flags'),
        ]);
    }

    /**
     * Clean up after a screenshot, also when it failed. A browser we launched
     * is closed; a running Chrome we connected to is shared, so only our tab
     * is closed and the browser keeps running for the next image.
     */
    public function releaseBrowser(Browser $browser, ?Page $page = null): void
    {
        if ($browser instanceof ProcessAwareBrowser) {
            $browser->close();

            return;
        }

        try {
            $page?->close();
        } finally {
            $browser->getConnection()->disconnect();
        }
    }

    /**
     * Load the HTML into the page, wait for its images and fonts, and take the screenshot.
     */
    protected function renderScreenshot(Page $page, string $html, ?string $path = null): ?string
    {
        $page->setHtml(html: $html, timeout: 10000, eventName: Page::LOAD);
        $page->setViewport(config('og-image.width'), config('og-image.height'));
        $page->evaluate($this->injectJs());

        $screenshot = $page->screenshot($this->getScreenshotOptions());

        $data = null;

        if ($path === null) {
            $data = base64_decode($screenshot->getBase64());
        } else {
            $screenshot->saveToFile($path);
        }

        return $data;
    }

    /**
     * The HTML of an image: the view its parameters ask for when that exists,
     * otherwise the package's template.
     */
    public function renderHtml(array $parameters): string
    {
        $view = isset($parameters['view']) && is_string($parameters['view']) && view()->exists($parameters['view'])
            ? $parameters['view']
            : 'og-image::template';

        return View::make($view, $parameters)->render();
    }

    /**
     * Whether a URL points at the image route of this application, like the
     * og:image URLs this package makes. It doesn't check the signature.
     */
    public function isImageUrl(string $url): bool
    {
        return '/'.Request::create($url)->path() === route('og-image.file', [], false);
    }

    /**
     * Generate and cache the image of a signed image URL, the way a visit to it
     * would, so no visitor or crawler has to wait for it later. Returns false
     * when the image was cached already.
     *
     * @throws InvalidArgumentException when the URL is not a validly signed image URL
     */
    public function createImageFromUrl(string $url): bool
    {
        $request = Request::create($url);

        if (! $this->isImageUrl($url) || ! URL::hasValidSignature($request)) {
            throw new InvalidArgumentException("Not a validly signed image URL: {$url}");
        }

        $filename = $this->getRequestImageFilename($request);

        if ($this->getStorageImageFileExists($filename)) {
            return false;
        }

        $this->saveImage($this->renderHtml($request->query()), $filename);

        return true;
    }

    public function getResponse(Request $request): Response
    {
        $html = $this->renderHtml($request->all());

        if ($request->route()->getName() == 'og-image.html') {
            return response($html, 200, [
                'Content-Type' => 'text/html',
            ]);
        }

        $headers = [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0',
            'Content-Type' => OgImage::getImageMimeType(),
            'Pragma' => 'no-cache',
        ];

        // Debug mode: render on every request and return the bytes in memory,
        // without persisting anything to disk.
        if (config('og-image.debug') === true) {
            return response(OgImage::takeScreenshot($html), 200, $headers);
        }

        // Signed requests are cached by their signature. Unsigned requests are
        // only allowed in local development (see the controller); there is no
        // signature to key the cache on, so derive a stable filename from the
        // request parameters instead. This keeps local previews working without
        // passing a null filename to saveImage().
        $filename = OgImage::getRequestImageFilename($request);

        OgImage::saveImage($html, $filename);

        return response(OgImage::getStorageImageFileData($filename), 200, $headers);
    }

    /**
     * Resolve the cache filename (without extension) for an incoming image
     * request: the signed-URL signature when present, otherwise a deterministic
     * hash of the request parameters (used for unsigned local-development
     * requests).
     */
    public function getRequestImageFilename(Request $request): string
    {
        if (is_string($request->signature) && $request->signature !== '') {
            return $request->signature;
        }

        $parameters = $request->except('signature');

        ksort($parameters);

        return hash('sha256', json_encode($parameters));
    }

    private function injectJs(): string
    {
        // Wait until all images and fonts have loaded
        // Taken from: https://github.com/svycal/og-image/blob/main/priv/js/take-screenshot.js#L42C5-L63
        // See: https://github.blog/2021-06-22-framework-building-open-graph-images/#some-performance-gotchas

        return <<<'JS'
            const selectors = Array.from(document.querySelectorAll('img'));

            await Promise.all([
                document.fonts.ready,
                document.querySelector('body') && document.body.innerText.trim().length > 0,
                ...selectors.map((img) => {

                    // Image has already finished loading, let’s see if it worked
                    if (img.complete) {
                        // Image loaded and has presence
                        if (img.naturalHeight !== 0) return;

                        // Image failed, so it has no height
                        throw new Error('Image failed to load');
                    }

                    // Image hasn’t loaded yet, added an event listener to know when it does
                    return new Promise((resolve, reject) => {
                        img.addEventListener('load', resolve);
                        img.addEventListener('error', reject);
                    });
                })
            ]);
        JS;
    }
}
