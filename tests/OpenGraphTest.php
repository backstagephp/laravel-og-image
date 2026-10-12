<?php

use Backstage\OgImage\Laravel\Facades\OgImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

$hasChrome = fn (): bool => ! empty(shell_exec('command -v chromium')) || ! empty(shell_exec('command -v google-chrome')) || ! empty(shell_exec('command -v google-chrome-stable'));

it('can generate an image using params', function (): void {
    $image = OgImage::createImageFromParams([
        'title' => 'title',
        'description' => 'description',
    ]);

    expect($image)->toBeString();
});

it('serves an image for a validly signed file url', function (): void {
    $url = OgImage::url(['title' => 'title', 'subtitle' => 'subtitle']);

    $this->get($url)->assertOk();
})->skip(fn (): bool => ! $hasChrome(), 'No Chrome/Chromium binary available');

it('forbids unsigned file requests outside local', function (): void {
    // Regression: an unsigned request used to reach saveImage() with a null
    // signature and throw a TypeError. Outside local it must be rejected.
    app()->detectEnvironment(fn () => 'production');

    $this->get(route('og-image.file', ['title' => 'title'], false))
        ->assertForbidden();
});

it('renders an unsigned file request in local development', function (): void {
    // In local development the image always loads regardless of signature; a
    // stable filename is derived from the request params instead of a signature.
    app()->detectEnvironment(fn () => 'local');

    $this->get(route('og-image.file', ['title' => 'title'], false))
        ->assertOk();
})->skip(fn (): bool => ! $hasChrome(), 'No Chrome/Chromium binary available');

it('derives a stable filename from params when unsigned', function (): void {
    $request = Request::create(route('og-image.file', ['title' => 'title', 'subtitle' => 'sub'], false));

    $a = OgImage::getRequestImageFilename($request);
    $b = OgImage::getRequestImageFilename($request);

    expect($a)->toBeString()->not->toBeEmpty()->and($a)->toBe($b);
});

it('creates its storage folders on the configured disk, not in the working directory', function (): void {
    // Regression: the folders were made with a path relative to the working directory, so an
    // empty og-images/ folder appeared wherever PHP ran (public/ when serving requests).
    Storage::fake(config('og-image.storage.disk'));

    $cwd = getcwd();
    $workingDirectory = sys_get_temp_dir().'/og-image-'.uniqid();
    mkdir($workingDirectory);
    chdir($workingDirectory);

    try {
        OgImage::ensureDirectoryExists('images');
    } finally {
        chdir($cwd);
    }

    expect(Storage::disk(config('og-image.storage.disk'))->directoryExists(OgImage::getStoragePath('images')))->toBeTrue()
        ->and(is_dir($workingDirectory.'/'.OgImage::getStoragePath('images')))->toBeFalse();

    rmdir($workingDirectory);
});

it('takes the screenshot in the format of the configured extension', function (string $extension, string $format, string $mimeType): void {
    config(['og-image.extension' => $extension]);

    expect(OgImage::getImageFormat())->toBe($format)
        ->and(OgImage::getImageMimeType())->toBe($mimeType)
        ->and(OgImage::getScreenshotOptions()['format'])->toBe($format);
})->with([
    ['png', 'png', 'image/png'],
    ['jpg', 'jpeg', 'image/jpeg'],
    ['jpeg', 'jpeg', 'image/jpeg'],
    ['webp', 'webp', 'image/webp'],
]);

it('passes the quality for lossy formats only', function (): void {
    config(['og-image.extension' => 'jpg', 'og-image.quality' => 75]);
    expect(OgImage::getScreenshotOptions())->toBe(['format' => 'jpeg', 'quality' => 75]);

    config(['og-image.extension' => 'png']);
    expect(OgImage::getScreenshotOptions())->toBe(['format' => 'png']);
});

it('writes the image in the configured format', function (string $extension, string $magic): void {
    config(['og-image.extension' => $extension]);

    $image = OgImage::takeScreenshot('<h1>title</h1>');

    expect(substr((string) $image, 0, strlen($magic)))->toBe($magic);
})->with([
    ['png', "\x89PNG"],
    ['jpg', "\xFF\xD8\xFF"],
])->skip(fn (): bool => ! $hasChrome(), 'No Chrome/Chromium binary available');
