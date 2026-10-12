<?php

namespace Backstage\OgImage\Laravel\Crawler;

use DOMDocument;
use DOMXPath;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlObservers\CrawlObserver;
use Spatie\Crawler\ResponseWithCachedBody;

/**
 * Collects the og:image and twitter:image URLs of the pages the crawler visits
 * (see the og-image:warm command).
 */
class ImageUrlCollector extends CrawlObserver
{
    /** @var array<string, string> the image URLs, each with the first page it was found on */
    public array $imageUrls = [];

    /** @var array<string, string> the pages that could not be crawled, with the reason */
    public array $failedPages = [];

    public int $crawledPages = 0;

    public function crawled(
        UriInterface $url,
        ResponseInterface $response,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null,
    ): void {
        $this->crawledPages++;

        if (! str_contains($response->getHeaderLine('Content-Type'), 'html')) {
            return;
        }

        $html = $response instanceof ResponseWithCachedBody && $response->getCachedBody() !== null
            ? $response->getCachedBody()
            : (string) $response->getBody();

        foreach (self::imageUrls($html) as $imageUrl) {
            $this->imageUrls[$imageUrl] ??= (string) $url;
        }
    }

    public function crawlFailed(
        UriInterface $url,
        RequestException $requestException,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null,
    ): void {
        $this->failedPages[(string) $url] = $requestException->getMessage();
    }

    /**
     * The og:image and twitter:image URLs in a page's HTML, with entities like
     * &amp; decoded.
     *
     * @return array<int, string>
     */
    public static function imageUrls(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = new DOMDocument;
        $useInternalErrors = libxml_use_internal_errors(true);

        try {
            $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }

        $urls = [];

        foreach ((new DOMXPath($document))->query('//meta[@property="og:image" or @name="og:image" or @property="twitter:image" or @name="twitter:image"]/@content') ?: [] as $content) {
            $url = trim((string) $content->nodeValue);

            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }
}
