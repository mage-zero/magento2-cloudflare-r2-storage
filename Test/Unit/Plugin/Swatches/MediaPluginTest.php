<?php
namespace MageZero\CloudflareR2\Test\Unit\Plugin\Swatches;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Plugin\Swatches\MediaPlugin;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\Swatches\Helper\Media;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MediaPluginTest extends TestCase
{
    private Config|MockObject $config;
    private MediaConfig|MockObject $mediaConfig;
    private Database|MockObject $fileStorageDb;
    private MediaPlugin $plugin;
    private Media|MockObject $subject;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->mediaConfig = $this->createMock(MediaConfig::class);
        $this->fileStorageDb = $this->createMock(Database::class);
        $this->subject = $this->createMock(Media::class);

        $this->plugin = new MediaPlugin(
            $this->config,
            $this->mediaConfig,
            $this->fileStorageDb
        );
    }

    public function testBeforeMoveImageFromTmpSkipsWhenDbUsageDisabled(): void
    {
        $this->fileStorageDb->method('checkDbUsage')->willReturn(false);
        $this->config->method('isR2Selected')->willReturn(true);

        $this->fileStorageDb->expects($this->never())->method('copyFile');

        $result = $this->plugin->beforeMoveImageFromTmp($this->subject, 'swatch.jpg');

        $this->assertEquals(['swatch.jpg'], $result);
    }

    public function testBeforeMoveImageFromTmpSkipsWhenR2NotSelected(): void
    {
        $this->fileStorageDb->method('checkDbUsage')->willReturn(true);
        $this->config->method('isR2Selected')->willReturn(false);

        $this->fileStorageDb->expects($this->never())->method('copyFile');

        $result = $this->plugin->beforeMoveImageFromTmp($this->subject, 'swatch.jpg');

        $this->assertEquals(['swatch.jpg'], $result);
    }

    public function testBeforeMoveImageFromTmpCopiesFileWhenR2Selected(): void
    {
        $this->fileStorageDb->method('checkDbUsage')->willReturn(true);
        $this->config->method('isR2Selected')->willReturn(true);

        $this->mediaConfig->method('getTmpMediaShortUrl')
            ->with('swatch.jpg')
            ->willReturn('tmp/swatch.jpg');

        $this->mediaConfig->method('getBaseMediaUrlAddition')
            ->willReturn('attribute/swatch');

        $this->fileStorageDb->method('getUniqueFilename')
            ->with('attribute/swatch', 'swatch.jpg')
            ->willReturn('swatch_1.jpg');

        $this->subject->method('getAttributeSwatchPath')
            ->with('swatch_1.jpg')
            ->willReturn('attribute/swatch/s/w/swatch_1.jpg');

        $this->fileStorageDb->expects($this->once())
            ->method('copyFile')
            ->with('tmp/swatch.jpg', 'attribute/swatch/s/w/swatch_1.jpg');

        $result = $this->plugin->beforeMoveImageFromTmp($this->subject, 'swatch.jpg');

        $this->assertEquals(['/swatch.jpg'], $result);
    }

    public function testBeforeMoveImageFromTmpStripsTmpSuffix(): void
    {
        $this->fileStorageDb->method('checkDbUsage')->willReturn(true);
        $this->config->method('isR2Selected')->willReturn(true);

        $this->mediaConfig->method('getTmpMediaShortUrl')
            ->with('swatch.jpg')
            ->willReturn('tmp/swatch.jpg');

        $this->mediaConfig->method('getBaseMediaUrlAddition')
            ->willReturn('attribute/swatch');

        $this->fileStorageDb->method('getUniqueFilename')
            ->with('attribute/swatch', 'swatch.jpg')
            ->willReturn('swatch.jpg');

        $this->subject->method('getAttributeSwatchPath')
            ->willReturn('attribute/swatch/s/w/swatch.jpg');

        $this->fileStorageDb->expects($this->once())->method('copyFile');

        // File with .tmp suffix
        $result = $this->plugin->beforeMoveImageFromTmp($this->subject, 'swatch.jpg.tmp');

        $this->assertEquals(['/swatch.jpg.tmp'], $result);
    }

    public function testBeforeMoveImageFromTmpAddsLeadingSlash(): void
    {
        $this->fileStorageDb->method('checkDbUsage')->willReturn(true);
        $this->config->method('isR2Selected')->willReturn(true);

        $this->mediaConfig->method('getTmpMediaShortUrl')->willReturn('tmp/swatch.jpg');
        $this->mediaConfig->method('getBaseMediaUrlAddition')->willReturn('attribute/swatch');
        $this->fileStorageDb->method('getUniqueFilename')->willReturn('swatch.jpg');
        $this->subject->method('getAttributeSwatchPath')->willReturn('attribute/swatch/s/w/swatch.jpg');

        // File without leading slash
        $result = $this->plugin->beforeMoveImageFromTmp($this->subject, 'swatch.jpg');
        $this->assertEquals(['/swatch.jpg'], $result);

        // File already with leading slash
        $result = $this->plugin->beforeMoveImageFromTmp($this->subject, '/swatch.jpg');
        $this->assertEquals(['/swatch.jpg'], $result);
    }
}
