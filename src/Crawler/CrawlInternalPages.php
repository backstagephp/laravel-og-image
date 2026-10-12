<?php

namespace Backstage\OgImage\Laravel\Crawler;

use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlProfiles\CrawlInternalUrls;

/**
 * The internal URLs that can be pages: not the assets they link to (images,
 * videos, scripts…), which only cost time to download and have no og:image.
 */
class CrawlInternalPages extends CrawlInternalUrls
{
    public function shouldCrawl(UriInterface $url): bool
    {
        $extension = strtolower(pathinfo($url->getPath(), PATHINFO_EXTENSION));

        return parent::shouldCrawl($url) && in_array($extension, ['', 'html', 'htm', 'php'], true);
    }
}
