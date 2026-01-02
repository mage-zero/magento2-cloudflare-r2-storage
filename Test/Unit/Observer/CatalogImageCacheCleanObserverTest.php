<?php
namespace MageZero\CloudflareR2\Test\Unit\Observer;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2Factory;
use MageZero\CloudflareR2\Observer\CatalogImageCacheCleanObserver;
use Magento\Catalog\Model\Product\Media\ConfigInterface as MediaConfig;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CatalogImageCacheCleanObserverTest extends TestCase
{
    public function testExecuteDeletesR2CacheWhenSelected(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isR2Selected')->willReturn(true);

        $mediaConfig = $this->createMock(MediaConfig::class);
        $mediaConfig->method('getBaseMediaPath')->willReturn('catalog/product');

        $storage = $this->createMock(R2::class);
        $storage->expects($this->once())->method('deleteDirectory')->with('catalog/product/cache');

        $factory = $this->createMock(R2Factory::class);
        $factory->method('create')->willReturn($storage);

        $logger = $this->createMock(LoggerInterface::class);

        $observer = new CatalogImageCacheCleanObserver($config, $factory, $mediaConfig, $logger);
        $observer->execute(new Observer());
    }

    public function testExecuteSkipsWhenR2Disabled(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isR2Selected')->willReturn(false);

        $mediaConfig = $this->createMock(MediaConfig::class);
        $factory = $this->createMock(R2Factory::class);
        $factory->expects($this->never())->method('create');

        $logger = $this->createMock(LoggerInterface::class);

        $observer = new CatalogImageCacheCleanObserver($config, $factory, $mediaConfig, $logger);
        $observer->execute(new Observer());
    }
}
