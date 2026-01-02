<?php
namespace MageZero\CloudflareR2\Test\Unit\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\FileExistenceCache;
use PHPUnit\Framework\TestCase;

class FileExistenceCacheTest extends TestCase
{
    private FileExistenceCache $cache;
    private CacheInterface $cacheInterface;
    private SerializerInterface $serializer;
    private Config $config;

    protected function setUp(): void
    {
        $this->cacheInterface = $this->createMock(CacheInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->config = $this->createMock(Config::class);

        $this->cache = new FileExistenceCache(
            $this->cacheInterface,
            $this->serializer,
            $this->config
        );
    }

    public function testHasReturnsTrueWhenCacheHit(): void
    {
        $filename = 'catalog/product/test.jpg';
        $this->cacheInterface->expects($this->once())
            ->method('load')
            ->willReturn('serialized_data');

        $result = $this->cache->has($filename);

        $this->assertTrue($result);
    }

    public function testHasReturnsFalseWhenCacheMiss(): void
    {
        $filename = 'catalog/product/test.jpg';
        $this->cacheInterface->expects($this->once())
            ->method('load')
            ->willReturn(false);

        $result = $this->cache->has($filename);

        $this->assertFalse($result);
    }

    public function testGetReturnsNullOnCacheMiss(): void
    {
        $filename = 'catalog/product/test.jpg';
        $this->cacheInterface->expects($this->once())
            ->method('load')
            ->willReturn(false);

        $result = $this->cache->get($filename);

        $this->assertNull($result);
    }

    public function testGetReturnsBooleanOnCacheHit(): void
    {
        $filename = 'catalog/product/test.jpg';
        $serialized = 'b:1;';

        $this->cacheInterface->expects($this->once())
            ->method('load')
            ->willReturn($serialized);

        $this->serializer->expects($this->once())
            ->method('unserialize')
            ->with($serialized)
            ->willReturn(true);

        $result = $this->cache->get($filename);

        $this->assertTrue($result);
    }

    public function testSetStoresDataWithTtl(): void
    {
        $filename = 'catalog/product/test.jpg';
        $exists = true;
        $ttl = 3600;

        $this->config->expects($this->once())
            ->method('getCacheTtl')
            ->willReturn($ttl);

        $this->serializer->expects($this->once())
            ->method('serialize')
            ->with($exists)
            ->willReturn('b:1;');

        $this->cacheInterface->expects($this->once())
            ->method('save')
            ->with(
                'b:1;',
                $this->stringContains('magezero_r2_file_exists_'),
                ['magezero_r2_file_existence'],
                $ttl
            );

        $this->cache->set($filename, $exists);
    }

    public function testRemoveDeletesCacheEntry(): void
    {
        $filename = 'catalog/product/test.jpg';

        $this->cacheInterface->expects($this->once())
            ->method('remove')
            ->with($this->stringContains('magezero_r2_file_exists_'));

        $this->cache->remove($filename);
    }

    public function testClearCleansAllEntries(): void
    {
        $this->cacheInterface->expects($this->once())
            ->method('clean')
            ->with(['magezero_r2_file_existence']);

        $this->cache->clear();
    }
}
