<?php
namespace MageZero\CloudflareR2\Test\Unit\Model\MediaStorage\File\Storage;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2;
use MageZero\CloudflareR2\Model\R2ClientFactory;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Filesystem\Io\File as IoFile;
use Magento\MediaStorage\Helper\File\Media as MediaHelper;
use Magento\MediaStorage\Helper\File\Storage\Database as StorageHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class R2Test extends TestCase
{
    private S3Client $s3Client;
    private Config $config;
    private R2 $r2;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getBucket')->willReturn('test-bucket');
        $this->config->method('getKeyPrefix')->willReturn('');

        $mediaHelper = $this->createMock(MediaHelper::class);
        $storageHelper = $this->createMock(StorageHelper::class);
        $storageHelper->method('getMediaRelativePath')->willReturnArgument(0);
        $storageHelper->method('getMediaBaseDir')->willReturn('/var/www/pub/media');

        $logger = $this->createMock(LoggerInterface::class);

        $this->s3Client = $this->getMockBuilder(S3Client::class)
            ->disableOriginalConstructor()
            ->addMethods(['headObject', 'putObject', 'getObject', 'deleteObject', 'copyObject', 'listObjectsV2', 'deleteObjects'])
            ->getMock();
        $clientFactory = $this->createMock(R2ClientFactory::class);
        $clientFactory->method('create')->willReturn($this->s3Client);

        $driver = $this->createMock(FileDriver::class);
        $driver->method('getParentDirectory')->willReturnCallback(fn($path) => dirname($path));

        $ioFile = $this->createMock(IoFile::class);
        $ioFile->method('getPathInfo')->willReturnCallback(function ($path) {
            return [
                'dirname' => dirname($path),
                'basename' => basename($path),
                'extension' => pathinfo($path, PATHINFO_EXTENSION),
                'filename' => pathinfo($path, PATHINFO_FILENAME),
            ];
        });

        $this->r2 = new R2($this->config, $mediaHelper, $storageHelper, $logger, $clientFactory, $driver, $ioFile);
    }

    public function testGetStorageName(): void
    {
        $this->assertEquals('Cloudflare R2 (S3 Compatible)', (string)$this->r2->getStorageName());
    }

    public function testHasErrorsReturnsFalseByDefault(): void
    {
        $this->assertFalse($this->r2->hasErrors());
    }

    public function testFileExistsReturnsTrue(): void
    {
        $this->s3Client->method('headObject')->willReturn([]);
        $this->assertTrue($this->r2->fileExists('catalog/product/test.jpg'));
    }

    public function testFileExistsReturnsFalseOnException(): void
    {
        $command = $this->createMock(CommandInterface::class);
        $this->s3Client->method('headObject')
            ->willThrowException(new AwsException('Not found', $command));
        $this->assertFalse($this->r2->fileExists('catalog/product/missing.jpg'));
    }

    public function testSaveFileWithArray(): void
    {
        $this->s3Client->expects($this->once())
            ->method('putObject')
            ->with($this->callback(function ($params) {
                return $params['Bucket'] === 'test-bucket'
                    && $params['Key'] === 'catalog/product/test.jpg'
                    && $params['Body'] === 'file-content';
            }));

        $result = $this->r2->saveFile([
            'filename' => 'test.jpg',
            'directory' => 'catalog/product',
            'content' => 'file-content',
        ]);

        $this->assertTrue($result);
    }

    public function testSaveFileSkipsWhenExistsAndNoOverwrite(): void
    {
        $this->s3Client->method('headObject')->willReturn([]);
        $this->s3Client->expects($this->never())->method('putObject');

        $result = $this->r2->saveFile([
            'filename' => 'test.jpg',
            'directory' => 'catalog/product',
            'content' => 'file-content',
        ], false);

        $this->assertFalse($result);
    }

    public function testDeleteFile(): void
    {
        $this->s3Client->expects($this->once())
            ->method('deleteObject')
            ->with($this->callback(function ($params) {
                return $params['Bucket'] === 'test-bucket'
                    && $params['Key'] === 'catalog/product/test.jpg';
            }));

        $this->assertTrue($this->r2->deleteFile('catalog/product/test.jpg'));
    }

    public function testCopyFile(): void
    {
        $this->s3Client->expects($this->once())
            ->method('copyObject')
            ->with($this->callback(function ($params) {
                return $params['Bucket'] === 'test-bucket'
                    && $params['CopySource'] === 'test-bucket/old.jpg'
                    && $params['Key'] === 'new.jpg';
            }));

        $this->assertTrue($this->r2->copyFile('old.jpg', 'new.jpg'));
    }

    public function testRenameFileCopiesAndDeletes(): void
    {
        $this->s3Client->expects($this->once())->method('copyObject');
        $this->s3Client->expects($this->once())->method('deleteObject');

        $this->assertTrue($this->r2->renameFile('old.jpg', 'new.jpg'));
    }

    public function testLoadByFilename(): void
    {
        $this->s3Client->method('getObject')->willReturn([
            'Body' => 'test-content',
        ]);

        $this->r2->loadByFilename('catalog/product/test.jpg');

        $this->assertEquals('catalog/product/test.jpg', $this->r2->getData('filename'));
        $this->assertEquals('test-content', $this->r2->getData('content'));
    }

    public function testLoadByFilenameUnsetsDataOnNotFound(): void
    {
        $command = $this->createMock(CommandInterface::class);
        $this->s3Client->method('getObject')
            ->willThrowException(new AwsException('Not found', $command));

        $this->r2->loadByFilename('missing.jpg');

        $this->assertNull($this->r2->getData('filename'));
    }

    public function testGetConnectionNameReturnsR2(): void
    {
        $this->assertEquals('r2', $this->r2->getConnectionName());
    }

    public function testInitReturnsSelf(): void
    {
        $this->assertSame($this->r2, $this->r2->init());
    }

    public function testGetMediaBaseDirectory(): void
    {
        $this->assertEquals('/var/www/pub/media', $this->r2->getMediaBaseDirectory());
    }

    public function testImportDirectoriesReturnsSelf(): void
    {
        $this->assertSame($this->r2, $this->r2->importDirectories(['dir1', 'dir2']));
    }

    public function testImportFilesUploadsFiles(): void
    {
        $this->s3Client->expects($this->exactly(2))
            ->method('putObject');

        $result = $this->r2->importFiles([
            ['filename' => 'file1.jpg', 'directory' => 'catalog', 'content' => 'content1'],
            ['filename' => 'file2.jpg', 'directory' => 'catalog', 'content' => 'content2'],
        ]);

        $this->assertSame($this->r2, $result);
    }

    public function testImportFilesLogsErrorOnException(): void
    {
        $command = $this->createMock(CommandInterface::class);
        $this->s3Client->method('putObject')
            ->willThrowException(new AwsException('Upload failed', $command));

        $result = $this->r2->importFiles([
            ['filename' => 'file1.jpg', 'directory' => 'catalog', 'content' => 'content1'],
        ]);

        $this->assertSame($this->r2, $result);
        $this->assertTrue($this->r2->hasErrors());
    }

    public function testDeleteDirectoryDeletesAllFilesWithPrefix(): void
    {
        $this->s3Client->method('listObjectsV2')->willReturn([
            'Contents' => [
                ['Key' => 'catalog/product/cache/file1.jpg'],
                ['Key' => 'catalog/product/cache/file2.jpg'],
            ],
            'IsTruncated' => false,
        ]);

        $this->s3Client->expects($this->once())
            ->method('deleteObjects')
            ->with($this->callback(function ($params) {
                return $params['Bucket'] === 'test-bucket'
                    && count($params['Delete']['Objects']) === 2;
            }));

        $result = $this->r2->deleteDirectory('catalog/product/cache');

        $this->assertSame($this->r2, $result);
    }

    public function testDeleteDirectoryDoesNothingForEmptyPrefix(): void
    {
        $this->s3Client->expects($this->never())->method('listObjectsV2');
        $this->s3Client->expects($this->never())->method('deleteObjects');

        $result = $this->r2->deleteDirectory('');

        $this->assertSame($this->r2, $result);
    }

    public function testGetSubdirectoriesReturnsDirectories(): void
    {
        $this->s3Client->method('listObjectsV2')->willReturn([
            'CommonPrefixes' => [
                ['Prefix' => 'catalog/product/'],
                ['Prefix' => 'catalog/category/'],
            ],
        ]);

        $dirs = $this->r2->getSubdirectories('catalog');

        $this->assertCount(2, $dirs);
        $this->assertEquals('catalog/product', $dirs[0]['name']);
        $this->assertEquals('catalog/category', $dirs[1]['name']);
    }

    public function testGetDirectoryFilesReturnsFiles(): void
    {
        $this->s3Client->method('listObjectsV2')->willReturn([
            'Contents' => [
                ['Key' => 'catalog/file1.jpg'],
                ['Key' => 'catalog/file2.jpg'],
            ],
        ]);

        $this->s3Client->method('getObject')->willReturn([
            'Body' => 'file-content',
        ]);

        $files = $this->r2->getDirectoryFiles('catalog');

        $this->assertCount(2, $files);
        $this->assertEquals('file1.jpg', $files[0]['filename']);
        $this->assertEquals('catalog', $files[0]['directory']);
    }

    public function testCopyFileReturnsFalseOnException(): void
    {
        $command = $this->createMock(CommandInterface::class);
        $this->s3Client->method('copyObject')
            ->willThrowException(new AwsException('Copy failed', $command));

        $this->assertFalse($this->r2->copyFile('old.jpg', 'new.jpg'));
    }

    public function testRenameFileReturnsFalseWhenCopyFails(): void
    {
        $command = $this->createMock(CommandInterface::class);
        $this->s3Client->method('copyObject')
            ->willThrowException(new AwsException('Copy failed', $command));

        $this->assertFalse($this->r2->renameFile('old.jpg', 'new.jpg'));
    }

    public function testDeleteFileReturnsFalseOnException(): void
    {
        $command = $this->createMock(CommandInterface::class);
        $this->s3Client->method('deleteObject')
            ->willThrowException(new AwsException('Delete failed', $command));

        $this->assertFalse($this->r2->deleteFile('test.jpg'));
    }

    public function testSaveFileThrowsExceptionOnUploadError(): void
    {
        $command = $this->createMock(CommandInterface::class);
        $this->s3Client->method('putObject')
            ->willThrowException(new AwsException('Upload failed', $command));

        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('Unable to save file');

        $this->r2->saveFile([
            'filename' => 'test.jpg',
            'directory' => 'catalog',
            'content' => 'content',
        ]);
    }

    public function testSaveFileThrowsExceptionOnInvalidFormat(): void
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('Wrong file info format');

        $this->r2->saveFile([]);
    }

    public function testExportDirectoriesReturnsFalseWhenEmpty(): void
    {
        $this->s3Client->method('listObjectsV2')->willReturn([
            'Contents' => [],
            'IsTruncated' => false,
        ]);

        $result = $this->r2->exportDirectories(0, 100);

        $this->assertFalse($result);
    }

    public function testExportFilesReturnsFalseWhenEmpty(): void
    {
        $this->s3Client->method('listObjectsV2')->willReturn([
            'Contents' => [],
            'IsTruncated' => false,
        ]);

        $result = $this->r2->exportFiles(0, 100);

        $this->assertFalse($result);
    }

    public function testClearDeletesAllKeys(): void
    {
        $this->s3Client->method('listObjectsV2')->willReturn([
            'Contents' => [
                ['Key' => 'file1.jpg'],
                ['Key' => 'file2.jpg'],
            ],
            'IsTruncated' => false,
        ]);

        $this->s3Client->expects($this->once())
            ->method('deleteObjects');

        $result = $this->r2->clear();

        $this->assertSame($this->r2, $result);
    }

    /**
     * Test extension-based MIME type detection (using empty content to skip finfo detection).
     */
    public function testSaveFileSetsContentTypeByExtensionForJpeg(): void
    {
        $this->s3Client->expects($this->once())
            ->method('putObject')
            ->with($this->callback(function ($params) {
                return isset($params['ContentType'])
                    && $params['ContentType'] === 'image/jpeg';
            }));

        $this->r2->saveFile([
            'filename' => 'test.jpg',
            'directory' => 'catalog/product',
            'content' => '', // Empty content triggers extension-based detection
        ]);
    }

    /**
     * Test that ContentDisposition is set to inline for image types.
     */
    public function testSaveFileSetsContentDispositionInlineForImages(): void
    {
        $this->s3Client->expects($this->once())
            ->method('putObject')
            ->with($this->callback(function ($params) {
                return isset($params['ContentDisposition'])
                    && $params['ContentDisposition'] === 'inline';
            }));

        $this->r2->saveFile([
            'filename' => 'test.png',
            'directory' => 'catalog/product',
            'content' => '', // Empty content triggers extension-based detection
        ]);
    }

    /**
     * Test extension-based MIME type detection for various file types.
     * Uses empty content to ensure extension-based fallback is used.
     *
     * @dataProvider mimeTypeDataProvider
     */
    public function testSaveFileSetsCorrectContentTypeByExtension(string $filename, string $expectedMime): void
    {
        $this->s3Client->expects($this->once())
            ->method('putObject')
            ->with($this->callback(function ($params) use ($expectedMime) {
                return isset($params['ContentType'])
                    && $params['ContentType'] === $expectedMime;
            }));

        $this->r2->saveFile([
            'filename' => $filename,
            'directory' => 'catalog',
            'content' => '', // Empty content triggers extension-based detection
        ]);
    }

    public static function mimeTypeDataProvider(): array
    {
        return [
            'jpeg' => ['test.jpeg', 'image/jpeg'],
            'jpg' => ['test.jpg', 'image/jpeg'],
            'png' => ['test.png', 'image/png'],
            'gif' => ['test.gif', 'image/gif'],
            'webp' => ['test.webp', 'image/webp'],
            'svg' => ['test.svg', 'image/svg+xml'],
            'pdf' => ['document.pdf', 'application/pdf'],
            'css' => ['styles.css', 'text/css'],
            'js' => ['script.js', 'application/javascript'],
        ];
    }

    /**
     * Test that ContentDisposition is set to inline for displayable content types.
     * Uses empty content to ensure extension-based MIME detection.
     *
     * @dataProvider inlineContentTypeDataProvider
     */
    public function testSaveFileSetsInlineDispositionForDisplayableTypes(string $filename): void
    {
        $this->s3Client->expects($this->once())
            ->method('putObject')
            ->with($this->callback(function ($params) {
                return isset($params['ContentDisposition'])
                    && $params['ContentDisposition'] === 'inline';
            }));

        $this->r2->saveFile([
            'filename' => $filename,
            'directory' => 'catalog',
            'content' => '', // Empty content triggers extension-based detection
        ]);
    }

    public static function inlineContentTypeDataProvider(): array
    {
        return [
            'jpeg' => ['test.jpeg'],
            'png' => ['test.png'],
            'gif' => ['test.gif'],
            'pdf' => ['document.pdf'],
            'css' => ['styles.css'],
            'js' => ['script.js'],
            'txt' => ['readme.txt'],
            'html' => ['page.html'],
            'mp4' => ['video.mp4'],
            'mp3' => ['audio.mp3'],
        ];
    }

    /**
     * Test that ContentDisposition is NOT set for non-displayable types like zip.
     */
    public function testSaveFileDoesNotSetContentDispositionForZip(): void
    {
        $this->s3Client->expects($this->once())
            ->method('putObject')
            ->with($this->callback(function ($params) {
                return !isset($params['ContentDisposition']);
            }));

        $this->r2->saveFile([
            'filename' => 'archive.zip',
            'directory' => 'downloads',
            'content' => '', // Empty content, and zip has no MIME mapping so no ContentType/ContentDisposition
        ]);
    }

    /**
     * Test that finfo-based content detection works when content is provided.
     * Plain text content should be detected as text/plain.
     */
    public function testSaveFileUsesFinfoForContentBasedMimeDetection(): void
    {
        $this->s3Client->expects($this->once())
            ->method('putObject')
            ->with($this->callback(function ($params) {
                return isset($params['ContentType'])
                    && $params['ContentType'] === 'text/plain';
            }));

        $this->r2->saveFile([
            'filename' => 'test.unknown',
            'directory' => 'catalog',
            'content' => 'This is plain text content',
        ]);
    }
}
