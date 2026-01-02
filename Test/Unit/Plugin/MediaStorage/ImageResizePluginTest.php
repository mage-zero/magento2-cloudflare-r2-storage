<?php
namespace MageZero\CloudflareR2\Test\Unit\Plugin\MediaStorage;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\MediaStorage\ImageCacheSynchronizer;
use MageZero\CloudflareR2\Plugin\MediaStorage\ImageResizePlugin;
use Magento\MediaStorage\Service\ImageResize;
use PHPUnit\Framework\TestCase;

class ImageResizePluginTest extends TestCase
{
    public function testAroundResizeFromThemesRunsSyncAfterIteration(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isR2Selected')->willReturn(true);

        $synchronizer = $this->createMock(ImageCacheSynchronizer::class);
        $synchronizer->expects($this->once())->method('sync');

        $plugin = new ImageResizePlugin($config, $synchronizer);

        $generator = (function () {
            yield ['filename' => 'image.jpg', 'error' => ''] => 1;
        })();

        $result = $plugin->aroundResizeFromThemes(
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
        $config = $this->createMock(Config::class);
        $config->method('isR2Selected')->willReturn(false);

        $synchronizer = $this->createMock(ImageCacheSynchronizer::class);
        $synchronizer->expects($this->never())->method('sync');

        $plugin = new ImageResizePlugin($config, $synchronizer);

        $generator = (function () {
            yield ['filename' => 'image.jpg', 'error' => ''] => 1;
        })();

        $result = $plugin->aroundResizeFromThemes(
            $this->createMock(ImageResize::class),
            function (?array $themes = null, bool $skipHiddenImages = false) use ($generator) {
                return $generator;
            },
            null,
            false
        );

        $this->assertSame($generator, $result);
    }
}
