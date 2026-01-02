<?php
namespace MageZero\CloudflareR2\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;

class FileExistenceCache
{
    private const CACHE_PREFIX = 'magezero_r2_file_exists_';
    private const CACHE_TAG = 'magezero_r2_file_existence';

    private CacheInterface $cache;
    private SerializerInterface $serializer;
    private Config $config;

    public function __construct(
        CacheInterface $cache,
        SerializerInterface $serializer,
        Config $config
    ) {
        $this->cache = $cache;
        $this->serializer = $serializer;
        $this->config = $config;
    }

    public function has(string $filename): bool
    {
        $cacheKey = $this->getCacheKey($filename);
        return $this->cache->load($cacheKey) !== false;
    }

    public function get(string $filename): ?bool
    {
        $cacheKey = $this->getCacheKey($filename);
        $data = $this->cache->load($cacheKey);

        if ($data === false) {
            return null;
        }

        $result = $this->serializer->unserialize($data);
        return is_bool($result) ? $result : null;
    }

    public function set(string $filename, bool $exists): void
    {
        $cacheKey = $this->getCacheKey($filename);
        $data = $this->serializer->serialize($exists);
        $this->cache->save(
            $data,
            $cacheKey,
            [self::CACHE_TAG],
            $this->config->getCacheTtl()
        );
    }

    public function remove(string $filename): void
    {
        $cacheKey = $this->getCacheKey($filename);
        $this->cache->remove($cacheKey);
    }

    public function clear(): void
    {
        $this->cache->clean([self::CACHE_TAG]);
    }

    private function getCacheKey(string $filename): string
    {
        return self::CACHE_PREFIX . md5($filename);
    }
}
