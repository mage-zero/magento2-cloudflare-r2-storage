<?php
namespace MageZero\CloudflareR2\Test\Unit\Model\MediaStorage;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\MediaStorage\ImageCacheSynchronizer;
use Magento\Catalog\Model\Product\Media\ConfigInterface as MediaConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Write;
use Magento\MediaStorage\Helper\File\Storage\Database as StorageDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImageCacheSynchronizerTest extends TestCase
{
    public function testSyncSkipsWhenR2NotSelected(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isR2Selected')->willReturn(false);

        $mediaConfig = $this->createMock(MediaConfig::class);
        $mediaDirectory = $this->createMock(Write::class);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->with(DirectoryList::MEDIA)->willReturn($mediaDirectory);

        $storageDatabase = $this->createMock(StorageDatabase::class);
        $storageDatabase->expects($this->never())->method('saveFile');

        $logger = $this->createMock(LoggerInterface::class);

        $synchronizer = new ImageCacheSynchronizer(
            $config,
            $mediaConfig,
            $filesystem,
            $storageDatabase,
            $logger
        );

        $synchronizer->sync();
    }

    public function testSyncSkipsWhenReadOnlyMode(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isR2Selected')->willReturn(true);
        $config->method('isReadOnlyMode')->willReturn(true);

        $mediaConfig = $this->createMock(MediaConfig::class);
        $mediaDirectory = $this->createMock(Write::class);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->with(DirectoryList::MEDIA)->willReturn($mediaDirectory);

        $storageDatabase = $this->createMock(StorageDatabase::class);
        $storageDatabase->expects($this->never())->method('saveFile');

        $logger = $this->createMock(LoggerInterface::class);

        $synchronizer = new ImageCacheSynchronizer(
            $config,
            $mediaConfig,
            $filesystem,
            $storageDatabase,
            $logger
        );

        $synchronizer->sync();
    }

    public function testSyncUploadsCacheFiles(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isR2Selected')->willReturn(true);
        $config->method('isReadOnlyMode')->willReturn(false);

        $mediaConfig = $this->createMock(MediaConfig::class);
        $mediaConfig->method('getBaseMediaPath')->willReturn('catalog/product');

        $mediaDirectory = $this->createMock(Write::class);
        $mediaDirectory->method('isExist')->with('catalog/product/cache')->willReturn(true);
        $mediaDirectory->method('readRecursively')
            ->with('catalog/product/cache')
            ->willReturn([
                'catalog/product/cache/a.jpg',
                'catalog/product/cache/dir',
            ]);
        $mediaDirectory->method('isFile')->willReturnCallback(
            static function (string $path): bool {
                return $path === 'catalog/product/cache/a.jpg';
            }
        );

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->with(DirectoryList::MEDIA)->willReturn($mediaDirectory);

        $storageDatabase = $this->createMock(StorageDatabase::class);
        $storageDatabase->expects($this->once())
            ->method('saveFile')
            ->with('catalog/product/cache/a.jpg');

        $logger = $this->createMock(LoggerInterface::class);

        $synchronizer = new ImageCacheSynchronizer(
            $config,
            $mediaConfig,
            $filesystem,
            $storageDatabase,
            $logger
        );

        $synchronizer->sync();
    }
}
