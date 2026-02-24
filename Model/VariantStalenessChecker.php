<?php
namespace MageZero\CloudflareR2\Model;

use Magento\Framework\HTTP\ClientInterface as HttpClient;

class VariantStalenessChecker
{
    private const HTTP_HEAD_USER_AGENT = 'magezero-r2-media-check/1.0';

    private HttpClient $httpClient;
    /** @var array<string,int|null> */
    private array $lastModifiedCache = [];

    public function __construct(HttpClient $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    public function isStale(string $filePath, string $baseMediaUrl, ?int $variantModified = null): bool
    {
        $originalPath = $this->extractOriginalPath($filePath);
        if ($originalPath === null) {
            return false;
        }

        $variantModified = $variantModified ?? $this->getLastModifiedHeader();
        if ($variantModified === null) {
            return false;
        }

        $originalUrl = $baseMediaUrl . '/' . ltrim($originalPath, '/');
        $originalModified = $this->getLastModified($originalUrl);
        if ($originalModified === null) {
            return false;
        }

        return $originalModified > $variantModified;
    }

    public function extractOriginalPath(string $cachePath): ?string
    {
        if (preg_match('#^(catalog/product)/cache/[^/]+/(.+)$#', $cachePath, $matches)) {
            return $matches[1] . '/' . $matches[2];
        }
        return null;
    }

    public function getLastModified(string $url): ?int
    {
        if (array_key_exists($url, $this->lastModifiedCache)) {
            return $this->lastModifiedCache[$url];
        }

        try {
            $this->configureHeadRequest();
            $this->httpClient->get($url);

            if ($this->httpClient->getStatus() !== 200) {
                $this->lastModifiedCache[$url] = null;
                return null;
            }

            $this->lastModifiedCache[$url] = $this->getLastModifiedHeader();
            return $this->lastModifiedCache[$url];
        } catch (\Exception $e) {
            $this->lastModifiedCache[$url] = null;
            return null;
        }
    }

    private function configureHeadRequest(): void
    {
        $this->httpClient->setTimeout(5);
        $this->httpClient->setOption(CURLOPT_NOBODY, true);
        $this->httpClient->setOption(CURLOPT_USERAGENT, self::HTTP_HEAD_USER_AGENT);
        $this->httpClient->setOption(CURLOPT_HTTPHEADER, ['Accept: */*']);
    }

    private function getLastModifiedHeader(): ?int
    {
        $headers = $this->httpClient->getHeaders();
        foreach ($headers as $name => $value) {
            if (strtolower((string)$name) === 'last-modified') {
                if (is_array($value)) {
                    $value = (string)reset($value);
                }
                $timestamp = strtotime((string)$value);
                return $timestamp !== false ? $timestamp : null;
            }
        }
        return null;
    }
}
