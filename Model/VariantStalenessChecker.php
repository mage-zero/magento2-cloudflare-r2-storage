<?php
namespace MageZero\CloudflareR2\Model;

use Magento\Framework\HTTP\ClientInterface as HttpClient;

class VariantStalenessChecker
{
    private HttpClient $httpClient;

    public function __construct(HttpClient $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    public function isStale(string $filePath, string $baseMediaUrl): bool
    {
        $originalPath = $this->extractOriginalPath($filePath);
        if ($originalPath === null) {
            return false;
        }

        $variantModified = $this->getLastModifiedHeader();
        if ($variantModified === null) {
            return false;
        }

        try {
            $originalUrl = $baseMediaUrl . '/' . ltrim($originalPath, '/');
            $this->httpClient->setOptions(['timeout' => 5]);
            $this->httpClient->setOption(CURLOPT_NOBODY, true);
            $this->httpClient->get($originalUrl);

            if ($this->httpClient->getStatus() !== 200) {
                return false;
            }

            $originalModified = $this->getLastModifiedHeader();
            if ($originalModified === null) {
                return false;
            }

            return $originalModified > $variantModified;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function extractOriginalPath(string $cachePath): ?string
    {
        if (preg_match('#^(catalog/product)/cache/[^/]+/(.+)$#', $cachePath, $matches)) {
            return $matches[1] . '/' . $matches[2];
        }
        return null;
    }

    private function getLastModifiedHeader(): ?int
    {
        $headers = $this->httpClient->getHeaders();
        foreach ($headers as $name => $value) {
            if (strtolower((string)$name) === 'last-modified') {
                $timestamp = strtotime($value);
                return $timestamp !== false ? $timestamp : null;
            }
        }
        return null;
    }
}
