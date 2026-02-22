<?php
namespace MageZero\CloudflareR2\Test\Unit\Plugin\MediaStorage;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\DownloadedFileTracker;
use MageZero\CloudflareR2\Model\MediaStorage\ImageCacheSynchronizer;
use MageZero\CloudflareR2\Plugin\MediaStorage\ImageResizePlugin;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\MediaStorage\Service\ImageResize;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class ImageResizePluginTest extends TestCase
{
    private Config $config;
    private ImageCacheSynchronizer $synchronizer;
    private DownloadedFileTracker $downloadTracker;
    private WriteInterface $mediaDirectory;
    private LoggerInterface $logger;
    private ImageResizePlugin $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->synchronizer = $this->createMock(ImageCacheSynchronizer::class);
        $this->downloadTracker = new DownloadedFileTracker();
        $this->mediaDirectory = $this->createMock(WriteInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')
            ->with(DirectoryList::MEDIA)
            ->willReturn($this->mediaDirectory);

        $this->plugin = new ImageResizePlugin(
            $this->config,
            $this->synchronizer,
            $this->downloadTracker,
            $filesystem,
            $this->logger
        );
    }

    public function testAroundResizeFromThemesRunsSyncAfterIteration(): void
    {
        $this->config->method('isR2Selected')->willReturn(true);
        $this->synchronizer->expects($this->once())->method('sync');

        $generator = (function () {
            yield ['filename' => 'image.jpg', 'error' => ''] => 1;
        })();

        $result = $this->plugin->aroundResizeFromThemes(
            $this->createMock(ImageResize::class),
            function (?array $themes = null, bool $skipHiddenImages = false) use ($generator) {
                return $generator;
            },
            null,
            false
        );

        $items = [];
        foreach ($result as $key => $value) {
            $items[] = [$key, $value];
        }

        $this->assertCount(1, $items);
        $this->assertSame(['filename' => 'image.jpg', 'error' => ''], $items[0][0]);
        $this->assertSame(1, $items[0][1]);
    }

    public function testAroundResizeFromThemesReturnsOriginalGeneratorWhenR2Disabled(): void
    {
        $this->config->method('isR2Selected')->willReturn(false);
        $this->synchronizer->expects($this->never())->method('sync');

        $generator = (function () {
            yield ['filename' => 'image.jpg', 'error' => ''] => 1;
        })();

        $result = $this->plugin->aroundResizeFromThemes(
            $this->createMock(ImageResize::class),
            function (?array $themes = null, bool $skipHiddenImages = false) use ($generator) {
                return $generator;
            },
            null,
            false
        );

        $this->assertSame($generator, $result);
    }

    public function testCleansUpDownloadedFilesAfterSync(): void
    {
        $this->config->method('isR2Selected')->willReturn(true);

        // Simulate files being tracked during resize
        $this->downloadTracker->track('catalog/product/a/b/image1.jpg');
        $this->downloadTracker->track('catalog/product/c/d/image2.jpg');

        $this->mediaDirectory->method('isFile')->willReturn(true);
        $this->mediaDirectory->expects($this->exactly(2))
            ->method('delete')
            ->willReturnCallback(function ($path) {
                $this->assertContains($path, [
                    'catalog/product/a/b/image1.jpg',
                    'catalog/product/c/d/image2.jpg',
                ]);
            });

        $generator = (function () {
            yield ['filename' => 'image.jpg', 'error' => ''] => 1;
        })();

        $result = $this->plugin->aroundResizeFromThemes(
            $this->createMock(ImageResize::class),
            fn() => $generator,
            null,
            false
        );

        // Consume the generator to trigger finally block
        while ($result->valid()) {
            $result->next();
        }
    }

    public function testCleanupSkipsNonExistentFiles(): void
    {
        $this->config->method('isR2Selected')->willReturn(true);

        $this->downloadTracker->track('catalog/product/gone.jpg');

        $this->mediaDirectory->method('isFile')->willReturn(false);
        $this->mediaDirectory->expects($this->never())->method('delete');

        $generator = (function () {
            yield ['filename' => 'image.jpg', 'error' => ''] => 1;
        })();

        $result = $this->plugin->aroundResizeFromThemes(
            $this->createMock(ImageResize::class),
            fn() => $generator,
            null,
            false
        );

        while ($result->valid()) {
            $result->next();
        }
    }

    public function testCleanupLogsWarningOnDeleteFailure(): void
    {
        $this->config->method('isR2Selected')->willReturn(true);

        $this->downloadTracker->track('catalog/product/locked.jpg');

        $this->mediaDirectory->method('isFile')->willReturn(true);
        $this->mediaDirectory->method('delete')
            ->willThrowException(new \Exception('Permission denied'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to clean up downloaded file',
                $this->callback(function ($context) {
                    return $context['path'] === 'catalog/product/locked.jpg'
                        && $context['error'] === 'Permission denied';
                })
            );

        $generator = (function () {
            yield ['filename' => 'image.jpg', 'error' => ''] => 1;
        })();

        $result = $this->plugin->aroundResizeFromThemes(
            $this->createMock(ImageResize::class),
            fn() => $generator,
            null,
            false
        );

        while ($result->valid()) {
            $result->next();
        }
    }
}
