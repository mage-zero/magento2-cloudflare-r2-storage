<?php
namespace MageZero\CloudflareR2\Test\Integration;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2;
use MageZero\CloudflareR2\Model\R2ClientFactory;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\MediaStorage\Helper\File\Media as MediaHelper;
use Magento\MediaStorage\Helper\File\Storage\Database as StorageHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * End-to-end integration tests for R2 model.
 *
 * Tests the actual R2 model class against a real Cloudflare R2 bucket,
 * verifying that Magento's storage interface works correctly with R2.
 *
 * This test can run standalone without full Magento bootstrap.
 */
class R2ModelIntegrationTest extends TestCase
{
    private static ?S3Client $s3Client = null;
    private static ?string $bucket = null;
    private static ?string $testPrefix = null;
    private ?R2 $r2Model = null;

    public static function setUpBeforeClass(): void
    {
        $accountId = getenv('R2_ACCOUNT_ID');
        $accessKey = getenv('R2_ACCESS_KEY');
        $secretKey = getenv('R2_SECRET_KEY');
        $bucket = getenv('R2_BUCKET');

        if (!$accountId || !$accessKey || !$secretKey || !$bucket) {
            self::markTestSkipped(
                'R2 credentials not configured. Set R2_ACCOUNT_ID, R2_ACCESS_KEY, R2_SECRET_KEY, R2_BUCKET.'
            );
        }

        self::$bucket = $bucket;
        self::$testPrefix = 'r2-model-test/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));

        self::$s3Client = new S3Client([
            'region' => 'auto',
            'version' => 'latest',
            'endpoint' => "https://{$accountId}.r2.cloudflarestorage.com",
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$s3Client === null || self::$bucket === null || self::$testPrefix === null) {
            return;
        }

        // Clean up test files
        try {
            $objects = self::$s3Client->listObjectsV2([
                'Bucket' => self::$bucket,
                'Prefix' => self::$testPrefix . '/',
            ]);

            if (!empty($objects['Contents'])) {
                $deleteObjects = array_map(
                    fn($obj) => ['Key' => $obj['Key']],
                    $objects['Contents']
                );
                self::$s3Client->deleteObjects([
                    'Bucket' => self::$bucket,
                    'Delete' => ['Objects' => $deleteObjects, 'Quiet' => true],
                ]);
            }
        } catch (AwsException $e) {
            // Ignore cleanup errors
        }
    }

    protected function setUp(): void
    {
        if (self::$s3Client === null) {
            $this->markTestSkipped('R2 client not initialized');
        }

        // Create mock config that returns real credentials
        $config = $this->createMock(Config::class);
        $config->method('getBucket')->willReturn(self::$bucket);
        $config->method('getKeyPrefix')->willReturn(self::$testPrefix);
        $config->method('getAccountId')->willReturn(getenv('R2_ACCOUNT_ID'));
        $config->method('getAccessKey')->willReturn(getenv('R2_ACCESS_KEY'));
        $config->method('getSecretKey')->willReturn(getenv('R2_SECRET_KEY'));
        $config->method('getEndpoint')->willReturn(
            'https://' . getenv('R2_ACCOUNT_ID') . '.r2.cloudflarestorage.com'
        );
        $config->method('getRegion')->willReturn('auto');

        // Create real client factory
        $clientFactory = $this->createMock(R2ClientFactory::class);
        $clientFactory->method('create')->willReturn(self::$s3Client);

        // Mock storage helper
        $storageHelper = $this->createMock(StorageHelper::class);
        $storageHelper->method('getMediaRelativePath')->willReturnCallback(
            fn($path) => ltrim(str_replace('/var/www/pub/media/', '', $path), '/')
        );
        $storageHelper->method('getMediaBaseDir')->willReturn('/var/www/pub/media');

        // Mock media helper
        $mediaHelper = $this->createMock(MediaHelper::class);

        // Use real file driver
        $driver = new FileDriver();

        $this->r2Model = new R2(
            $config,
            $mediaHelper,
            $storageHelper,
            new NullLogger(),
            $clientFactory,
            $driver
        );
    }

    public function testSaveAndLoadFile(): void
    {
        $content = 'Test content: ' . bin2hex(random_bytes(16));

        // Save file via R2 model
        $result = $this->r2Model->saveFile([
            'filename' => 'test-save-load.txt',
            'directory' => 'catalog/product',
            'content' => $content,
        ]);

        $this->assertTrue($result);

        // Verify file exists
        $this->assertTrue($this->r2Model->fileExists('catalog/product/test-save-load.txt'));

        // Load file and verify content
        $this->r2Model->loadByFilename('catalog/product/test-save-load.txt');
        $this->assertEquals($content, $this->r2Model->getData('content'));
    }

    public function testCopyAndRenameFile(): void
    {
        $content = 'Copy test content';

        // Save original file
        $this->r2Model->saveFile([
            'filename' => 'original.txt',
            'directory' => 'test',
            'content' => $content,
        ]);

        // Copy file
        $this->assertTrue($this->r2Model->copyFile('test/original.txt', 'test/copied.txt'));
        $this->assertTrue($this->r2Model->fileExists('test/copied.txt'));

        // Rename (copy + delete)
        $this->assertTrue($this->r2Model->renameFile('test/copied.txt', 'test/renamed.txt'));
        $this->assertTrue($this->r2Model->fileExists('test/renamed.txt'));
        $this->assertFalse($this->r2Model->fileExists('test/copied.txt'));
    }

    public function testDeleteFile(): void
    {
        // Save a file
        $this->r2Model->saveFile([
            'filename' => 'to-delete.txt',
            'directory' => 'test',
            'content' => 'delete me',
        ]);

        $this->assertTrue($this->r2Model->fileExists('test/to-delete.txt'));

        // Delete it
        $this->assertTrue($this->r2Model->deleteFile('test/to-delete.txt'));
        $this->assertFalse($this->r2Model->fileExists('test/to-delete.txt'));
    }

    public function testDeleteDirectory(): void
    {
        // Create multiple files in a directory
        for ($i = 1; $i <= 3; $i++) {
            $this->r2Model->saveFile([
                'filename' => "file{$i}.txt",
                'directory' => 'test/delete-dir',
                'content' => "content {$i}",
            ]);
        }

        // Verify files exist
        $this->assertTrue($this->r2Model->fileExists('test/delete-dir/file1.txt'));

        // Delete directory
        $this->r2Model->deleteDirectory('test/delete-dir');

        // Verify files are gone
        $this->assertFalse($this->r2Model->fileExists('test/delete-dir/file1.txt'));
        $this->assertFalse($this->r2Model->fileExists('test/delete-dir/file2.txt'));
        $this->assertFalse($this->r2Model->fileExists('test/delete-dir/file3.txt'));
    }

    public function testImportAndExportFiles(): void
    {
        // Import multiple files
        $this->r2Model->importFiles([
            ['filename' => 'import1.txt', 'directory' => 'test/import', 'content' => 'content 1'],
            ['filename' => 'import2.txt', 'directory' => 'test/import', 'content' => 'content 2'],
        ]);

        // Verify files exist
        $this->assertTrue($this->r2Model->fileExists('test/import/import1.txt'));
        $this->assertTrue($this->r2Model->fileExists('test/import/import2.txt'));

        // Test no errors occurred
        $this->assertFalse($this->r2Model->hasErrors());
    }

    public function testGetDirectoryFiles(): void
    {
        // Create files in a directory
        $this->r2Model->saveFile([
            'filename' => 'dir-file1.txt',
            'directory' => 'test/list-dir',
            'content' => 'content 1',
        ]);
        $this->r2Model->saveFile([
            'filename' => 'dir-file2.txt',
            'directory' => 'test/list-dir',
            'content' => 'content 2',
        ]);

        $files = $this->r2Model->getDirectoryFiles('test/list-dir');

        $this->assertCount(2, $files);
        $filenames = array_column($files, 'filename');
        $this->assertContains('dir-file1.txt', $filenames);
        $this->assertContains('dir-file2.txt', $filenames);
    }

    public function testOverwriteProtection(): void
    {
        $originalContent = 'original content';
        $newContent = 'new content';

        // Save original file
        $this->r2Model->saveFile([
            'filename' => 'no-overwrite.txt',
            'directory' => 'test',
            'content' => $originalContent,
        ]);

        // Try to save without overwrite - should return false
        $result = $this->r2Model->saveFile([
            'filename' => 'no-overwrite.txt',
            'directory' => 'test',
            'content' => $newContent,
        ], false);

        $this->assertFalse($result);

        // Verify original content is preserved
        $this->r2Model->loadByFilename('test/no-overwrite.txt');
        $this->assertEquals($originalContent, $this->r2Model->getData('content'));
    }
}
