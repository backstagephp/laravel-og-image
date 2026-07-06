<?php

namespace Backstage\OgImage\Laravel;

use Backstage\OgImage\Laravel\Http\Controllers\OgImageController;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\View\ComponentAttributeBag;

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

    public function getImageMimeType(): string
    {
        return 'image/'.$this->getImageExtension();
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
        if (! File::isDirectory($this->getStoragePath($folder))) {
            File::makeDirectory($this->getStoragePath($folder), 0777, true);
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
            storage_path('app/public/'.OgImage::getStorageImageFilePath($filename)),
        );
    }

    /**
     * Render $html to an image. When $path is given the image is written there;
     * when it is null the raw image bytes are returned instead (no disk write),
     * which debug mode uses to avoid persisting anything locally.
     */
    public function takeScreenshot(string $html, ?string $path = null): ?string
    {
        $binary = (string) config('og-image.chrome.path');

        $browserFactory = new BrowserFactory($binary);

        $browser = $browserFactory->createBrowser([
            'customFlags' => config('og-image.chrome.flags'),
        ]);

        $page = $browser->createPage();

        $page->setHtml(html: $html, timeout: 10000, eventName: Page::LOAD);
        $page->setViewport(config('og-image.width'), config('og-image.height'));
        $page->evaluate($this->injectJs());

        $screenshot = $page->screenshot();

        $data = null;

        if ($path === null) {
            $data = base64_decode($screenshot->getBase64());
        } else {
            $screenshot->saveToFile($path);
        }

        $browser->close();

        return $data;
    }

    public function getResponse(Request $request): Response
    {
        if (
            $request->view &&
            view()->exists($request->view)
        ) {
            $html = View::make($request->view, $request->all())
                ->render();
        } else {
            $html = View::make('og-image::template', $request->all())
                ->render();
        }

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
