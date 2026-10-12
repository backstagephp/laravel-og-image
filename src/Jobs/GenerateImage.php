<?php

namespace Backstage\OgImage\Laravel\Jobs;

use Backstage\OgImage\Laravel\Facades\OgImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Generates and caches the image of a signed image URL (see og-image:warm), on
 * the queue and connection in og-image.queue. Unique per URL, so warming twice
 * in a row doesn't draw an image twice.
 */
class GenerateImage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public string $url)
    {
        $this->onConnection(config('og-image.queue.connection'));
        $this->onQueue(config('og-image.queue.name'));
    }

    public function handle(): void
    {
        OgImage::createImageFromUrl($this->url);
    }

    public function uniqueId(): string
    {
        return hash('sha256', $this->url);
    }
}
