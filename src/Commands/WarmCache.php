<?php

namespace Backstage\OgImage\Laravel\Commands;

use Backstage\OgImage\Laravel\Crawler\CrawlInternalPages;
use Backstage\OgImage\Laravel\Crawler\ImageUrlCollector;
use Backstage\OgImage\Laravel\Facades\OgImage;
use Backstage\OgImage\Laravel\Jobs\GenerateImage;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Crawler\Crawler;
use Symfony\Component\Console\Formatter\OutputFormatter;
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

        // Chrome won't start as root (without --no-sandbox), and the images would belong to root, so
        // the web server couldn't write the cache anymore: it should run as the web server's user.
        if ($this->runsAsRoot()) {
            $this->components->warn('Running as root: run this as the user of the web server instead (e.g. sudo -u www-data php artisan og-image:warm), as Chrome refuses to start as root and the cached images would belong to root.');
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

        $counts = ['generated' => 0, 'queued' => 0, 'cached' => 0, 'failed' => 0];

        foreach ($imageUrls as $imageUrl => $page) {
            [$status, $error] = $this->warm($imageUrl);

            $counts[$status]++;

            $this->components->twoColumnDetail($page, match ($status) {
                'generated' => '<fg=green;options=bold>GENERATED</>',
                'queued' => '<fg=green;options=bold>QUEUED</>',
                'cached' => '<fg=gray>CACHED</>',
                'failed' => '<fg=red;options=bold>FAILED</>',
            });

            if ($error !== null) {
                $this->line('  <fg=red>'.OutputFormatter::escape(Str::limit(strtok($error, "\n") ?: $error, 300)).'</>');
            }
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
     * so a bad URL fails the command rather than a job later on. A failure
     * comes with its reason.
     *
     * @return array{0: 'generated'|'queued'|'cached'|'failed', 1: ?string}
     */
    protected function warm(string $imageUrl): array
    {
        try {
            if (! OgImage::isSignedImageUrl($imageUrl)) {
                throw new InvalidArgumentException("Not a validly signed image URL: {$imageUrl}");
            }

            if (OgImage::isImageUrlCached($imageUrl)) {
                return ['cached', null];
            }

            if (! $this->option('sync')) {
                GenerateImage::dispatch($imageUrl);

                return ['queued', null];
            }

            return [OgImage::createImageFromUrl($imageUrl) ? 'generated' : 'cached', null];
        } catch (Throwable $e) {
            report($e);

            return ['failed', $e->getMessage()];
        }
    }

    protected function runsAsRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /**
     * Crawl the pages of the site at the URL and collect their images. It's an
     * own site, often a staging site that keeps search engines out, so whatever
     * its robots say is ignored: robots.txt, the X-Robots-Tag header, the robots
     * meta tag and rel="nofollow" on links.
     */
    protected function crawl(string $url): ImageUrlCollector
    {
        $collector = new ImageUrlCollector;

        $crawler = Crawler::create()
            ->setCrawlObserver($collector)
            ->setCrawlProfile(new CrawlInternalPages($url))
            ->setConcurrency(max(1, (int) $this->option('concurrency')))
            ->ignoreRobots()
            ->acceptNofollowLinks();

        if ($this->option('limit')) {
            $crawler->setTotalCrawlLimit((int) $this->option('limit'));
        }

        $crawler->startCrawling($url);

        return $collector;
    }
}
