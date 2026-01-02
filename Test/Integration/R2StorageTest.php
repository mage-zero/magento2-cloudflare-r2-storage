<?php
namespace MageZero\CloudflareR2\Test\Integration;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for R2 storage operations.
 *
 * These tests run against a real Cloudflare R2 bucket using credentials
 * from environment variables.
 */
class R2StorageTest extends TestCase
{
    private static ?S3Client $client = null;
    private static string $bucket;
    private static string $testPrefix;

    public static function setUpBeforeClass(): void
    {
        $accountId = getenv('R2_ACCOUNT_ID');
        $accessKey = getenv('R2_ACCESS_KEY');
        $secretKey = getenv('R2_SECRET_KEY');
        self::$bucket = getenv('R2_BUCKET');

        if (!$accountId || !$accessKey || !$secretKey || !self::$bucket) {
            self::markTestSkipped('R2 credentials not configured. Set R2_ACCOUNT_ID, R2_ACCESS_KEY, R2_SECRET_KEY, R2_BUCKET environment variables.');
        }

        self::$client = new S3Client([
            'region' => 'auto',
            'version' => 'latest',
            'endpoint' => "https://{$accountId}.r2.cloudflarestorage.com",
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
        ]);

        // Unique prefix for this test run to avoid conflicts
        self::$testPrefix = 'integration-test/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '/';
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$client === null) {
            return;
        }

        // Clean up all test files
        try {
            $objects = self::$client->listObjectsV2([
                'Bucket' => self::$bucket,
                'Prefix' => self::$testPrefix,
            ]);

            if (!empty($objects['Contents'])) {
                $deleteObjects = array_map(fn($obj) => ['Key' => $obj['Key']], $objects['Contents']);
                self::$client->deleteObjects([
                    'Bucket' => self::$bucket,
                    'Delete' => ['Objects' => $deleteObjects, 'Quiet' => true],
                ]);
            }
        } catch (AwsException $e) {
            // Ignore cleanup errors
        }
    }

    public function testConnectionToR2(): void
    {
        // Simple test to verify we can connect and list objects
        $result = self::$client->listObjectsV2([
            'Bucket' => self::$bucket,
            'MaxKeys' => 1,
        ]);

        $this->assertIsArray($result->toArray());
    }

    public function testUploadFile(): void
    {
        $key = self::$testPrefix . 'upload-test.txt';
        $content = 'Hello from integration test!';

        $result = self::$client->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => $content,
            'ContentType' => 'text/plain',
        ]);

        $this->assertNotEmpty($result['ETag']);
    }

    public function testFileExists(): void
    {
        $key = self::$testPrefix . 'exists-test.txt';

        // Upload a file first
        self::$client->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'test content',
        ]);

        // Check it exists using headObject
        $result = self::$client->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $this->assertNotEmpty($result['ContentLength']);
    }

    public function testFileNotExists(): void
    {
        $key = self::$testPrefix . 'does-not-exist-' . bin2hex(random_bytes(8)) . '.txt';

        $this->expectException(AwsException::class);
        self::$client->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
    }

    public function testDownloadFile(): void
    {
        $key = self::$testPrefix . 'download-test.txt';
        $content = 'Content to download: ' . bin2hex(random_bytes(16));

        // Upload first
        self::$client->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => $content,
        ]);

        // Download and verify
        $result = self::$client->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $this->assertEquals($content, (string) $result['Body']);
    }

    public function testCopyFile(): void
    {
        $sourceKey = self::$testPrefix . 'copy-source.txt';
        $destKey = self::$testPrefix . 'copy-dest.txt';
        $content = 'Content to copy';

        // Upload source
        self::$client->putObject([
            'Bucket' => self::$bucket,
            'Key' => $sourceKey,
            'Body' => $content,
        ]);

        // Copy
        self::$client->copyObject([
            'Bucket' => self::$bucket,
            'CopySource' => self::$bucket . '/' . $sourceKey,
            'Key' => $destKey,
        ]);

        // Verify destination exists with same content
        $result = self::$client->getObject([
            'Bucket' => self::$bucket,
            'Key' => $destKey,
        ]);

        $this->assertEquals($content, (string) $result['Body']);
    }

    public function testDeleteFile(): void
    {
        $key = self::$testPrefix . 'delete-test.txt';

        // Upload first
        self::$client->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'to be deleted',
        ]);

        // Delete
        self::$client->deleteObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        // Verify it's gone
        $this->expectException(AwsException::class);
        self::$client->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
    }

    public function testListObjects(): void
    {
        $prefix = self::$testPrefix . 'list-test/';

        // Upload multiple files
        for ($i = 1; $i <= 3; $i++) {
            self::$client->putObject([
                'Bucket' => self::$bucket,
                'Key' => $prefix . "file{$i}.txt",
                'Body' => "content {$i}",
            ]);
        }

        // List with prefix
        $result = self::$client->listObjectsV2([
            'Bucket' => self::$bucket,
            'Prefix' => $prefix,
        ]);

        $this->assertCount(3, $result['Contents']);
    }

    public function testListWithDelimiter(): void
    {
        $prefix = self::$testPrefix . 'delimiter-test/';

        // Upload files in subdirectories
        self::$client->putObject([
            'Bucket' => self::$bucket,
            'Key' => $prefix . 'root.txt',
            'Body' => 'root file',
        ]);
        self::$client->putObject([
            'Bucket' => self::$bucket,
            'Key' => $prefix . 'subdir/nested.txt',
            'Body' => 'nested file',
        ]);

        // List with delimiter to get "directories"
        $result = self::$client->listObjectsV2([
            'Bucket' => self::$bucket,
            'Prefix' => $prefix,
            'Delimiter' => '/',
        ]);

        // Should have 1 file at root level
        $this->assertCount(1, $result['Contents'] ?? []);
        // Should have 1 common prefix (subdir/)
        $this->assertCount(1, $result['CommonPrefixes'] ?? []);
        $this->assertEquals($prefix . 'subdir/', $result['CommonPrefixes'][0]['Prefix']);
    }

    public function testBinaryContent(): void
    {
        $key = self::$testPrefix . 'binary-test.bin';
        $content = random_bytes(1024); // 1KB of random binary data

        // Upload binary content
        self::$client->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => $content,
            'ContentType' => 'application/octet-stream',
        ]);

        // Download and verify
        $result = self::$client->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $this->assertEquals($content, (string) $result['Body']);
    }

    public function testContentTypePreserved(): void
    {
        $key = self::$testPrefix . 'content-type-test.jpg';

        self::$client->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'fake image content',
            'ContentType' => 'image/jpeg',
        ]);

        $result = self::$client->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $this->assertEquals('image/jpeg', $result['ContentType']);
    }
}
