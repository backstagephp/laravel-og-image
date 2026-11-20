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
    /**
     * Untyped, so it fits both spatie/crawler 8 (a UriInterface) and 9 (a string).
     *
     * @param  UriInterface|string  $url
     */
    public function shouldCrawl($url): bool
    {
        $path = $url instanceof UriInterface ? $url->getPath() : (string) parse_url($url, PHP_URL_PATH);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return parent::shouldCrawl($url) && in_array($extension, ['', 'html', 'htm', 'php'], true);
    }
}
