<?php

use Backstage\OgImage\Laravel\Facades\OgImage;

it('can generate an image using params', function (): void {
    $image = OgImage::createImageFromParams([
        'title' => 'title',
        'description' => 'description',
    ]);

    expect($image)->toBeString();
});

it('aborts unsigned requests to the file route instead of crashing', function (): void {
    // Regression: an unsigned request used to reach saveImage() with a null
    // signature and throw a TypeError. It must now be rejected cleanly.
    $this->get(route('og-image.file', ['title' => 'title'], false))
        ->assertForbidden();
});

it('serves an image for a validly signed file url', function (): void {
    $url = OgImage::url(['title' => 'title', 'subtitle' => 'subtitle']);

    $this->get($url)->assertOk();
})->skip(fn () => empty(shell_exec('command -v chromium')) && empty(shell_exec('command -v google-chrome')), 'No Chrome/Chromium binary available');
