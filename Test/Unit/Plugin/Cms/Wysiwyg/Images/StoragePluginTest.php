<?php
namespace MageZero\CloudflareR2\Test\Unit\Plugin\Cms\Wysiwyg\Images;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Plugin\Cms\Wysiwyg\Images\StoragePlugin;
use Magento\Cms\Model\Wysiwyg\Images\Storage;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\MediaStorage\Model\File\Storage\Directory\Database as DirectoryDatabase;
use Magento\MediaStorage\Model\File\Storage\Directory\DatabaseFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StoragePluginTest extends TestCase
{
    private Config|MockObject $config;
    private Database|MockObject $database;
    private Database|MockObject $coreFileStorageDb;
    private WriteInterface|MockObject $directory;
    private DatabaseFactory|MockObject $directoryDatabaseFactory;
    private DirectoryDatabase|MockObject $directoryDatabase;
    private StoragePlugin $plugin;
    private Storage|MockObject $subject;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->database = $this->createMock(Database::class);
        $this->coreFileStorageDb = $this->createMock(Database::class);
        $this->directory = $this->createMock(WriteInterface::class);
        $this->directoryDatabaseFactory = $this->createMock(DatabaseFactory::class);
        $this->directoryDatabase = $this->createMock(DirectoryDatabase::class);
        $this->subject = $this->createMock(Storage::class);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')
            ->with(DirectoryList::MEDIA)
            ->willReturn($this->directory);

        $this->directoryDatabaseFactory->method('create')
            ->willReturn($this->directoryDatabase);

        $this->plugin = new StoragePlugin(
            $this->config,
            $this->database,
            $this->coreFileStorageDb,
            $filesystem,
            $this->directoryDatabaseFactory
        );
    }

    public function testBeforeGetDirsCollectionCreatesSubdirectoriesWhenR2Selected(): void
    {
        $this->coreFileStorageDb->method('checkDbUsage')->willReturn(true);
        $this->config->method('isR2Selected')->willReturn(true);

        $this->directoryDatabase->method('getSubdirectories')
            ->with('/wysiwyg')
            ->willReturn([
                ['name' => 'wysiwyg/images'],
                ['name' => 'wysiwyg/uploads']
            ]);

        $this->directory->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function ($path) {
                static $calls = [];
                $calls[] = $path;
                $this->assertContains($path, ['wysiwyg/images', 'wysiwyg/uploads']);
                return true;
            });

        $result = $this->plugin->beforeGetDirsCollection($this->subject, '/wysiwyg');

        $this->assertEquals(['/wysiwyg'], $result);
    }

    public function testBeforeGetDirsCollectionSkipsWhenDbUsageDisabled(): void
    {
        $this->coreFileStorageDb->method('checkDbUsage')->willReturn(false);
        $this->config->method('isR2Selected')->willReturn(true);

        $this->directory->expects($this->never())->method('create');

        $result = $this->plugin->beforeGetDirsCollection($this->subject, '/wysiwyg');

        $this->assertEquals(['/wysiwyg'], $result);
    }

    public function testBeforeGetDirsCollectionSkipsWhenR2NotSelected(): void
    {
        $this->coreFileStorageDb->method('checkDbUsage')->willReturn(true);
        $this->config->method('isR2Selected')->willReturn(false);

        $this->directory->expects($this->never())->method('create');

        $result = $this->plugin->beforeGetDirsCollection($this->subject, '/wysiwyg');

        $this->assertEquals(['/wysiwyg'], $result);
    }

    public function testAfterResizeFileSavesToR2WhenSelected(): void
    {
        $this->config->method('isR2Selected')->willReturn(true);

        $storageModel = $this->createMock(\MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2::class);
        $this->database->method('getMediaRelativePath')
            ->with('/var/www/pub/media/wysiwyg/thumb.jpg')
            ->willReturn('wysiwyg/thumb.jpg');
        $this->database->method('getStorageDatabaseModel')
            ->willReturn($storageModel);

        $storageModel->expects($this->once())
            ->method('saveFile')
            ->with('wysiwyg/thumb.jpg');

        $result = $this->plugin->afterResizeFile(
            $this->subject,
            '/var/www/pub/media/wysiwyg/thumb.jpg'
        );

        $this->assertEquals('/var/www/pub/media/wysiwyg/thumb.jpg', $result);
    }

    public function testAfterResizeFileSkipsWhenR2NotSelected(): void
    {
        $this->config->method('isR2Selected')->willReturn(false);

        $this->database->expects($this->never())->method('getStorageDatabaseModel');

        $result = $this->plugin->afterResizeFile(
            $this->subject,
            '/var/www/pub/media/wysiwyg/thumb.jpg'
        );

        $this->assertEquals('/var/www/pub/media/wysiwyg/thumb.jpg', $result);
    }

    public function testAfterGetThumbsPathTrimsTrailingSlash(): void
    {
        $result = $this->plugin->afterGetThumbsPath($this->subject, '/wysiwyg/.thumbs/');

        $this->assertEquals('/wysiwyg/.thumbs', $result);
    }

    public function testAfterGetThumbsPathHandlesNoTrailingSlash(): void
    {
        $result = $this->plugin->afterGetThumbsPath($this->subject, '/wysiwyg/.thumbs');

        $this->assertEquals('/wysiwyg/.thumbs', $result);
    }
}
