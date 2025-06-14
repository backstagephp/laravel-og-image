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

        $url = parse_url($url);

        parse_str($url['query'], $parameters);

        return $parameters['signature'];
    }

    public function createImageFromParams(array $parameters, ?string $template = null, bool $returnImage = false): ?string
    {
        $signature = $this->getSignature($parameters);

        if (! OgImage::getStorageImageFileExists($signature)) {
            if (! empty($template) && View::exists($template)) {
                $html = View::make($template, $parameters)
                    ->render();
            } else {
                $html = View::make('og-image::template', $parameters)
                    ->render();
            }

            OgImage::saveImage($html, $signature);
        }

        if (! $returnImage) {
            return Storage::disk(config('og-image.storage.disk'))
                ->url(OgImage::getStorageImageFilePath($signature));
        } else {
            return Storage::disk(config('og-image.storage.disk'))
                ->get(OgImage::getStorageImageFilePath($signature));
        }
    }

    public function saveImage(string $html, string $filename): void
    {
        if (OgImage::getStorageImageFileExists($filename)) {
            return;
        }

        OgImage::ensureDirectoryExists('images');

        $this->takeScreenshot($html, $filename);
    }

    public function takeScreenshot(string $html, string $filename): void
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

        $screenshot->saveToFile(
            path: $path = storage_path('app/public/'.OgImage::getStorageImageFilePath($filename)),
        );

        $browser->close();
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

        OgImage::saveImage($html, $request->signature);

        return response(OgImage::getStorageImageFileData($request->signature), 200, [
            'Content-Type' => OgImage::getImageMimeType(),
        ]);
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
