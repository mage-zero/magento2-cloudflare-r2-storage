<?php
namespace MageZero\CloudflareR2\Test\Unit\Plugin\MediaStorage;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2Factory;
use MageZero\CloudflareR2\Plugin\MediaStorage\DatabaseHelperPlugin;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\MediaStorage\Model\File\Storage\Database as DatabaseStorage;
use Magento\MediaStorage\Model\File\Storage\DatabaseFactory;
use Magento\MediaStorage\Model\File\Storage\File as FileStorage;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DatabaseHelperPluginTest extends TestCase
{
    private Config $config;
    private R2Factory $r2Factory;
    private DatabaseFactory $dbStorageFactory;
    private DatabaseHelperPlugin $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->r2Factory = $this->createMock(R2Factory::class);
        $this->dbStorageFactory = $this->createMock(DatabaseFactory::class);

        $this->plugin = new DatabaseHelperPlugin(
            $this->config,
            $this->r2Factory,
            $this->dbStorageFactory
        );
    }

    public function testAfterCheckDbUsageReturnsTrueWhenR2Selected(): void
    {
        $subject = $this->createMock(Database::class);
        $this->config->method('isR2Selected')->willReturn(true);

        $result = $this->plugin->afterCheckDbUsage($subject, false);

        $this->assertTrue($result);
    }

    public function testAfterCheckDbUsageReturnsOriginalResultWhenR2NotSelected(): void
    {
        $subject = $this->createMock(Database::class);
        $this->config->method('isR2Selected')->willReturn(false);

        $result = $this->plugin->afterCheckDbUsage($subject, false);

        $this->assertFalse($result);
    }

    public function testAfterCheckDbUsageReturnsOriginalTrueResult(): void
    {
        $subject = $this->createMock(Database::class);
        $this->config->method('isR2Selected')->willReturn(false);

        $result = $this->plugin->afterCheckDbUsage($subject, true);

        $this->assertTrue($result);
    }

    public function testAroundGetStorageDatabaseModelReturnsR2WhenR2Selected(): void
    {
        $subject = $this->createMock(Database::class);
        $subject->method('checkDbUsage')->willReturn(true);
        $this->config->method('isR2Selected')->willReturn(true);

        $r2Model = $this->createMock(R2::class);
        $this->r2Factory->expects($this->once())->method('create')->willReturn($r2Model);
        $this->dbStorageFactory->expects($this->never())->method('create');

        $proceed = fn() => $this->fail('Proceed should not be called');

        $result = $this->plugin->aroundGetStorageDatabaseModel($subject, $proceed);

        $this->assertSame($r2Model, $result);
    }

    public function testAroundGetStorageDatabaseModelReturnsDatabaseWhenR2NotSelected(): void
    {
        $subject = $this->createMock(Database::class);
        $subject->method('checkDbUsage')->willReturn(true);
        $this->config->method('isR2Selected')->willReturn(false);

        $dbModel = $this->createMock(DatabaseStorage::class);
        $this->dbStorageFactory->expects($this->once())->method('create')->willReturn($dbModel);
        $this->r2Factory->expects($this->never())->method('create');

        $proceed = fn() => $this->fail('Proceed should not be called');

        $result = $this->plugin->aroundGetStorageDatabaseModel($subject, $proceed);

        $this->assertSame($dbModel, $result);
    }

    public function testAroundGetStorageDatabaseModelCachesResult(): void
    {
        $subject = $this->createMock(Database::class);
        $subject->method('checkDbUsage')->willReturn(true);
        $this->config->method('isR2Selected')->willReturn(true);

        $r2Model = $this->createMock(R2::class);
        $this->r2Factory->expects($this->once())->method('create')->willReturn($r2Model);

        $proceed = fn() => $this->fail('Proceed should not be called');

        $result1 = $this->plugin->aroundGetStorageDatabaseModel($subject, $proceed);
        $result2 = $this->plugin->aroundGetStorageDatabaseModel($subject, $proceed);

        $this->assertSame($result1, $result2);
    }

    public function testAfterGetMediaRelativePathStripsPubMediaPrefix(): void
    {
        $subject = $this->createMock(Database::class);
        $this->config->method('isR2Selected')->willReturn(true);

        $result = $this->plugin->afterGetMediaRelativePath($subject, 'pub/media/catalog/product/test.jpg');

        $this->assertEquals('catalog/product/test.jpg', $result);
    }

    public function testAfterGetMediaRelativePathReturnsOriginalWhenNoPubMediaPrefix(): void
    {
        $subject = $this->createMock(Database::class);
        $this->config->method('isR2Selected')->willReturn(true);

        $result = $this->plugin->afterGetMediaRelativePath($subject, 'catalog/product/test.jpg');

        $this->assertEquals('catalog/product/test.jpg', $result);
    }

    public function testAfterGetMediaRelativePathReturnsOriginalWhenR2NotSelected(): void
    {
        $subject = $this->createMock(Database::class);
        $this->config->method('isR2Selected')->willReturn(false);

        $result = $this->plugin->afterGetMediaRelativePath($subject, 'pub/media/catalog/product/test.jpg');

        $this->assertEquals('pub/media/catalog/product/test.jpg', $result);
    }

    public function testAroundDeleteFolderUsesR2WhenSelected(): void
    {
        $r2Model = $this->createMock(R2::class);
        $r2Model->expects($this->once())
            ->method('deleteDirectory')
            ->with('catalog/product/cache');

        $subject = $this->createMock(Database::class);
        $subject->method('getStorageDatabaseModel')->willReturn($r2Model);
        $this->config->method('isR2Selected')->willReturn(true);

        $proceed = fn() => $this->fail('Proceed should not be called');

        $result = $this->plugin->aroundDeleteFolder($subject, $proceed, 'catalog/product/cache');

        $this->assertNull($result);
    }

    public function testAroundDeleteFolderCallsProceedWhenR2NotSelected(): void
    {
        $subject = $this->createMock(Database::class);
        $this->config->method('isR2Selected')->willReturn(false);

        $proceedCalled = false;
        $proceed = function ($folderName) use (&$proceedCalled) {
            $proceedCalled = true;
            $this->assertEquals('catalog/product/cache', $folderName);
            return 'proceed-result';
        };

        $result = $this->plugin->aroundDeleteFolder($subject, $proceed, 'catalog/product/cache');

        $this->assertTrue($proceedCalled);
        $this->assertEquals('proceed-result', $result);
    }

    public function testAfterSaveUploadedFileTrimsLeadingSlash(): void
    {
        $subject = $this->createMock(Database::class);

        $result = $this->plugin->afterSaveUploadedFile($subject, '/catalog/product/test.jpg');

        $this->assertEquals('catalog/product/test.jpg', $result);
    }

    public function testAfterSaveUploadedFileReturnsUnchangedWhenNoLeadingSlash(): void
    {
        $subject = $this->createMock(Database::class);

        $result = $this->plugin->afterSaveUploadedFile($subject, 'catalog/product/test.jpg');

        $this->assertEquals('catalog/product/test.jpg', $result);
    }

    public function testAroundSaveFileToFilesystemUsesR2WhenSelected(): void
    {
        $r2Model = $this->getMockBuilder(R2::class)
            ->disableOriginalConstructor()
            ->addMethods(['getId'])
            ->onlyMethods(['loadByFilename', 'getData'])
            ->getMock();
        $r2Model->method('loadByFilename')->willReturnSelf();
        $r2Model->method('getId')->willReturn('test-id');
        $r2Model->method('getData')->willReturn([
            'filename' => 'test.jpg',
            'content' => 'file-content',
        ]);

        $fileModel = $this->createMock(FileStorage::class);
        $fileModel->expects($this->once())
            ->method('saveFile')
            ->willReturn(true);

        $subject = $this->createMock(Database::class);
        $subject->method('checkDbUsage')->willReturn(true);
        $subject->method('getStorageDatabaseModel')->willReturn($r2Model);
        $subject->method('getMediaRelativePath')->willReturn('catalog/product/test.jpg');
        $subject->method('getStorageFileModel')->willReturn($fileModel);

        $this->config->method('isR2Selected')->willReturn(true);

        $proceed = fn() => $this->fail('Proceed should not be called');

        $result = $this->plugin->aroundSaveFileToFilesystem($subject, $proceed, '/var/www/pub/media/catalog/product/test.jpg');

        $this->assertTrue($result);
    }

    public function testAroundSaveFileToFilesystemReturnsFalseWhenFileNotFound(): void
    {
        $r2Model = $this->getMockBuilder(R2::class)
            ->disableOriginalConstructor()
            ->addMethods(['getId'])
            ->onlyMethods(['loadByFilename'])
            ->getMock();
        $r2Model->method('loadByFilename')->willReturnSelf();
        $r2Model->method('getId')->willReturn(null);

        $subject = $this->createMock(Database::class);
        $subject->method('checkDbUsage')->willReturn(true);
        $subject->method('getStorageDatabaseModel')->willReturn($r2Model);
        $subject->method('getMediaRelativePath')->willReturn('catalog/product/missing.jpg');

        $this->config->method('isR2Selected')->willReturn(true);

        $proceed = fn() => $this->fail('Proceed should not be called');

        $result = $this->plugin->aroundSaveFileToFilesystem($subject, $proceed, '/var/www/pub/media/catalog/product/missing.jpg');

        $this->assertFalse($result);
    }

    public function testAroundSaveFileToFilesystemCallsProceedWhenR2NotSelected(): void
    {
        $subject = $this->createMock(Database::class);
        $subject->method('checkDbUsage')->willReturn(true);

        $this->config->method('isR2Selected')->willReturn(false);

        $proceedCalled = false;
        $proceed = function ($filename) use (&$proceedCalled) {
            $proceedCalled = true;
            $this->assertEquals('/var/www/pub/media/test.jpg', $filename);
            return 'proceed-result';
        };

        $result = $this->plugin->aroundSaveFileToFilesystem($subject, $proceed, '/var/www/pub/media/test.jpg');

        $this->assertTrue($proceedCalled);
        $this->assertEquals('proceed-result', $result);
    }
}
