<?php
namespace MageZero\CloudflareR2\Test\Unit\Model\MediaStorage;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2;
use MageZero\CloudflareR2\Model\MediaStorage\ImageCacheSynchronizer;
use Magento\Catalog\Model\Product\Media\ConfigInterface as MediaConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImageCacheSynchronizerTest extends TestCase
{
    private Config|MockObject $config;
    private MediaConfig|MockObject $mediaConfig;
    private ReadInterface|MockObject $mediaDirectory;
    private R2|MockObject $r2Storage;
    private LoggerInterface|MockObject $logger;
    private ImageCacheSynchronizer $synchronizer;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->mediaConfig = $this->createMock(MediaConfig::class);
        $this->mediaDirectory = $this->createMock(ReadInterface::class);
        $this->r2Storage = $this->createMock(R2::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')
            ->with(DirectoryList::MEDIA)
            ->willReturn($this->mediaDirectory);

        $this->synchronizer = new ImageCacheSynchronizer(
            $this->config,
            $this->mediaConfig,
            $filesystem,
            $this->r2Storage,
            $this->logger
        );
    }

    public function testSyncSkipsWhenR2NotSelected(): void
    {
        $this->config->method('isR2Selected')->willReturn(false);
        $this->mediaDirectory->expects($this->never())->method('isDirectory');
        $this->r2Storage->expects($this->never())->method('saveFile');

        $this->synchronizer->sync();
    }

    public function testSyncSkipsWhenCacheDirectoryDoesNotExist(): void
    {
        $this->config->method('isR2Selected')->willReturn(true);
        $this->mediaConfig->method('getBaseMediaPath')->willReturn('catalog/product');
        $this->mediaDirectory->method('isDirectory')
            ->with('catalog/product/cache')
            ->willReturn(false);

        $this->r2Storage->expects($this->never())->method('saveFile');

        $this->synchronizer->sync();
    }

    public function testSyncUploadsFilesToR2(): void
    {
        $this->config->method('isR2Selected')->willReturn(true);
        $this->mediaConfig->method('getBaseMediaPath')->willReturn('catalog/product');

        $this->mediaDirectory->method('isDirectory')
            ->willReturnCallback(function ($path) {
                return in_array($path, ['catalog/product/cache', 'catalog/product/cache/subdir']);
            });

        $this->mediaDirectory->method('isFile')
            ->willReturnCallback(function ($path) {
                return $path === 'catalog/product/cache/image.jpg';
            });

        $this->mediaDirectory->method('read')
            ->willReturnCallback(function ($path) {
                if ($path === 'catalog/product/cache') {
                    return ['catalog/product/cache/image.jpg'];
                }
                return [];
            });

        $this->mediaDirectory->method('readFile')
            ->with('catalog/product/cache/image.jpg')
            ->willReturn('image content');

        $this->r2Storage->expects($this->once())
            ->method('saveFile')
            ->with([
                'filename' => 'catalog/product/cache/image.jpg',
                'content' => 'image content'
            ]);

        $this->synchronizer->sync();
    }

    public function testSyncHandlesReadErrors(): void
    {
        $this->config->method('isR2Selected')->willReturn(true);
        $this->mediaConfig->method('getBaseMediaPath')->willReturn('catalog/product');

        $this->mediaDirectory->method('isDirectory')
            ->with('catalog/product/cache')
            ->willReturn(true);

        $this->mediaDirectory->method('read')
            ->willThrowException(new \Exception('Read error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Error syncing image cache directory', $this->anything());

        $this->r2Storage->expects($this->never())->method('saveFile');

        $this->synchronizer->sync();
    }

    public function testSyncHandlesUploadErrors(): void
    {
        $this->config->method('isR2Selected')->willReturn(true);
        $this->mediaConfig->method('getBaseMediaPath')->willReturn('catalog/product');

        $this->mediaDirectory->method('isDirectory')
            ->with('catalog/product/cache')
            ->willReturn(true);

        $this->mediaDirectory->method('isFile')
            ->willReturn(true);

        $this->mediaDirectory->method('read')
            ->willReturn(['catalog/product/cache/image.jpg']);

        $this->mediaDirectory->method('readFile')
            ->willReturn('image content');

        $this->r2Storage->method('saveFile')
            ->willThrowException(new \Exception('Upload failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to sync image cache file to R2', $this->anything());

        $this->synchronizer->sync();
    }
}
