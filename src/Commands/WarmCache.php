<?php

namespace Backstage\OgImage\Laravel\Commands;

use Backstage\OgImage\Laravel\Crawler\CrawlInternalPages;
use Backstage\OgImage\Laravel\Crawler\ImageUrlCollector;
use Backstage\OgImage\Laravel\Facades\OgImage;
use Backstage\OgImage\Laravel\Jobs\GenerateImage;
use GuzzleHttp\RequestOptions;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Spatie\Crawler\Crawler;
use Throwable;

class WarmCache extends Command
{
    public $signature = 'og-image:warm
        {url? : The URL to start crawling at (default: app.url)}
        {--concurrency=5 : The number of pages to crawl at the same time}
        {--limit= : The maximum number of pages to crawl}
        {--sync : Generate the images right away, instead of on the queue}';

    public $description = 'Crawl the site and generate the open graph images of its pages (on the queue), so no visitor or crawler has to wait for them';

    public function handle(): int
    {
        if (config('og-image.debug') === true) {
            $this->components->error('Images are not cached in debug mode (og-image.debug), so there is nothing to warm.');

            return self::FAILURE;
        }

        $url = (string) ($this->argument('url') ?: config('app.url'));

        $collector = $this->crawl($url);

        if ($collector->interrupted) {
            $this->components->error('The crawl was interrupted, so no images were warmed.');

            return self::FAILURE;
        }

        // A page that can't be crawled (a broken link, say) is worth a warning, but only an image
        // that can't be generated fails the command, so it can run in a deploy without breaking it.
        foreach ($collector->failedPages as $page => $reason) {
            $this->components->warn("Could not crawl {$page}: {$reason}");
        }

        // Only the images of this application: other og:images are static files or live elsewhere.
        $imageUrls = array_filter($collector->imageUrls, fn (string $page, string $imageUrl): bool => OgImage::isImageUrl($imageUrl), ARRAY_FILTER_USE_BOTH);

        $this->components->info(sprintf('Crawled %d pages of %s and found %d images.', $collector->crawledPages, $url, count($imageUrls)));

        $counts = ['generated' => 0, 'queued' => 0, 'cached' => 0, 'failed' => 0];

        foreach ($imageUrls as $imageUrl => $page) {
            $status = $this->warm($imageUrl);

            $counts[$status]++;

            $this->components->twoColumnDetail($page, match ($status) {
                'generated' => '<fg=green;options=bold>GENERATED</>',
                'queued' => '<fg=green;options=bold>QUEUED</>',
                'cached' => '<fg=gray>CACHED</>',
                'failed' => '<fg=red;options=bold>FAILED</>',
            });
        }

        $this->newLine();
        $this->components->info($this->option('sync')
            ? sprintf('%d generated, %d already cached, %d failed.', $counts['generated'], $counts['cached'], $counts['failed'])
            : sprintf('%d queued, %d already cached, %d failed.', $counts['queued'], $counts['cached'], $counts['failed']));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Generate the image of a URL, or put it on the queue: generated, queued,
     * cached (already) or failed. The signature is checked here in both cases,
     * so a bad URL fails the command rather than a job later on.
     *
     * @return 'generated'|'queued'|'cached'|'failed'
     */
    protected function warm(string $imageUrl): string
    {
        try {
            if (! OgImage::isSignedImageUrl($imageUrl)) {
                throw new InvalidArgumentException("Not a validly signed image URL: {$imageUrl}");
            }

            if (OgImage::isImageUrlCached($imageUrl)) {
                return 'cached';
            }

            if (! $this->option('sync')) {
                GenerateImage::dispatch($imageUrl);

                return 'queued';
            }

            return OgImage::createImageFromUrl($imageUrl) ? 'generated' : 'cached';
        } catch (Throwable $e) {
            report($e);

            return 'failed';
        }
    }

    /** Crawl the pages of the site at the URL (robots.txt aside: it's our own site) and collect their images. */
    protected function crawl(string $url): ImageUrlCollector
    {
        $collector = new ImageUrlCollector;

        // spatie/crawler 8 and 9 are both supported (9 is the one that installs on PHP 8.6), each with its own API.
        $profile = new CrawlInternalPages($url);
        $concurrency = max(1, (int) $this->option('concurrency'));
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        if (method_exists(Crawler::class, 'startCrawling')) {
            $crawler = Crawler::create()
                ->setCrawlObserver($collector)
                ->setCrawlProfile($profile)
                ->setConcurrency($concurrency)
                ->ignoreRobots();

            if ($limit !== null) {
                $crawler->setTotalCrawlLimit($limit);
            }

            $crawler->startCrawling($url);

            return $collector;
        }

        // spatie/crawler 9 follows redirects by default; 8 did not.
        $crawler = Crawler::create($url, [RequestOptions::ALLOW_REDIRECTS => false])
            ->addObserver($collector)
            ->crawlProfile($profile)
            ->concurrency($concurrency)
            ->ignoreRobots();

        if ($limit !== null) {
            $crawler->limit($limit);
        }

        // 9 catches Ctrl+C and returns what it has so far, where 8 just stopped. It also leaves the
        // signal handlers reset, which would cost a queue worker calling this command its own.
        $handlers = function_exists('pcntl_signal_get_handler')
            ? [SIGINT => pcntl_signal_get_handler(SIGINT), SIGTERM => pcntl_signal_get_handler(SIGTERM)]
            : [];

        try {
            $collector->interrupted = $crawler->start()->name === 'Interrupted';
        } finally {
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
        }

        return $collector;
    }
}
