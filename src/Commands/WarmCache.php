<?php

namespace Backstage\OgImage\Laravel\Commands;

use Backstage\OgImage\Laravel\Crawler\CrawlInternalPages;
use Backstage\OgImage\Laravel\Crawler\ImageUrlCollector;
use Backstage\OgImage\Laravel\Facades\OgImage;
use Illuminate\Console\Command;
use Spatie\Crawler\Crawler;
use Throwable;

class WarmCache extends Command
{
    public $signature = 'og-image:warm
        {url? : The URL to start crawling at (default: app.url)}
        {--concurrency=5 : The number of pages to crawl at the same time}
        {--limit= : The maximum number of pages to crawl}';

    public $description = 'Crawl the site and generate the open graph images of its pages, so no visitor or crawler has to wait for them';

    public function handle(): int
    {
        if (config('og-image.debug') === true) {
            $this->components->error('Images are not cached in debug mode (og-image.debug), so there is nothing to warm.');

            return self::FAILURE;
        }

        $url = (string) ($this->argument('url') ?: config('app.url'));

        $collector = $this->crawl($url);

        // A page that can't be crawled (a broken link, say) is worth a warning, but only an image
        // that can't be generated fails the command, so it can run in a deploy without breaking it.
        foreach ($collector->failedPages as $page => $reason) {
            $this->components->warn("Could not crawl {$page}: {$reason}");
        }

        // Only the images of this application: other og:images are static files or live elsewhere.
        $imageUrls = array_filter($collector->imageUrls, fn (string $page, string $imageUrl): bool => OgImage::isImageUrl($imageUrl), ARRAY_FILTER_USE_BOTH);

        $this->components->info(sprintf('Crawled %d pages of %s and found %d images.', $collector->crawledPages, $url, count($imageUrls)));

        $counts = ['generated' => 0, 'cached' => 0, 'failed' => 0];

        foreach ($imageUrls as $imageUrl => $page) {
            try {
                $status = OgImage::createImageFromUrl($imageUrl) ? 'generated' : 'cached';
            } catch (Throwable $e) {
                report($e);

                $status = 'failed';
            }

            $counts[$status]++;

            $this->components->twoColumnDetail($page, match ($status) {
                'generated' => '<fg=green;options=bold>GENERATED</>',
                'cached' => '<fg=gray>CACHED</>',
                'failed' => '<fg=red;options=bold>FAILED</>',
            });
        }

        $this->newLine();
        $this->components->info(sprintf('%d generated, %d already cached, %d failed.', $counts['generated'], $counts['cached'], $counts['failed']));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Crawl the pages of the site at the URL (robots.txt aside: it's our own site) and collect their images. */
    protected function crawl(string $url): ImageUrlCollector
    {
        $collector = new ImageUrlCollector;

        $crawler = Crawler::create()
            ->setCrawlObserver($collector)
            ->setCrawlProfile(new CrawlInternalPages($url))
            ->setConcurrency(max(1, (int) $this->option('concurrency')))
            ->ignoreRobots();

        if ($this->option('limit')) {
            $crawler->setTotalCrawlLimit((int) $this->option('limit'));
        }

        $crawler->startCrawling($url);

        return $collector;
    }
}
