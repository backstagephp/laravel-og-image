<?php

use Backstage\OgImage\Laravel\Facades\OgImage;
use Illuminate\Http\Request;

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
