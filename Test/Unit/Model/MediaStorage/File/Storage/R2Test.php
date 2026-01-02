<?php
namespace MageZero\CloudflareR2\Test\Unit\Model\MediaStorage\File\Storage;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2;
use MageZero\CloudflareR2\Model\R2ClientFactory;
use Magento\Framework\Filesystem\DriverInterface;
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

        $this->s3Client = $this->createMock(S3Client::class);
        $clientFactory = $this->createMock(R2ClientFactory::class);
        $clientFactory->method('create')->willReturn($this->s3Client);

        $driver = $this->createMock(DriverInterface::class);
        $driver->method('getParentDirectory')->willReturnCallback(fn($path) => dirname($path));
        $driver->method('getBaseName')->willReturnCallback(fn($path) => basename($path));

        $this->r2 = new R2($this->config, $mediaHelper, $storageHelper, $logger, $clientFactory, $driver);
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

    public function testGetConnectionNameReturnsNull(): void
    {
        $this->assertNull($this->r2->getConnectionName());
    }

    public function testInitReturnsSelf(): void
    {
        $this->assertSame($this->r2, $this->r2->init());
    }
}
