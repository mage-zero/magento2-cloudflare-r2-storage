<?php
namespace MageZero\CloudflareR2\Test\Integration;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for R2 storage operations.
 *
 * These tests run against a real Cloudflare R2 bucket using credentials
 * from environment variables. They verify the AWS SDK integration works
 * correctly with R2's S3-compatible API.
 *
 * @magentoAppArea adminhtml
 */
class R2StorageOperationsTest extends TestCase
{
    private static ?S3Client $client = null;
    private static ?string $bucket = null;
    private static ?string $testPrefix = null;

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

        // Unique prefix for this test run
        self::$testPrefix = 'integration-test/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '/';
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$client === null || self::$bucket === null || self::$testPrefix === null) {
            return;
        }

        // Clean up test files
        try {
            $objects = self::$client->listObjectsV2([
                'Bucket' => self::$bucket,
                'Prefix' => self::$testPrefix,
            ]);

            if (!empty($objects['Contents'])) {
                $deleteObjects = array_map(
                    fn($obj) => ['Key' => $obj['Key']],
                    $objects['Contents']
                );
                self::$client->deleteObjects([
                    'Bucket' => self::$bucket,
                    'Delete' => ['Objects' => $deleteObjects, 'Quiet' => true],
                ]);
            }
        } catch (AwsException $e) {
            // Ignore cleanup errors
        }
    }

    public function testR2Connection(): void
    {
        $result = self::$client->listObjectsV2([
            'Bucket' => self::$bucket,
            'MaxKeys' => 1,
        ]);

        $this->assertIsArray($result->toArray());
    }

    public function testUploadAndDownload(): void
    {
        $key = self::$testPrefix . 'upload-test.txt';
        $content = 'Integration test content: ' . bin2hex(random_bytes(16));

        // Upload
        self::$client->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => $content,
            'ContentType' => 'text/plain',
        ]);

        // Download and verify
        $result = self::$client->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $this->assertEquals($content, (string) $result['Body']);
    }

    public function testFileExists(): void
    {
        $key = self::$testPrefix . 'exists-test.txt';

        // Upload
        self::$client->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'test',
        ]);

        // Check exists
        $result = self::$client->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $this->assertGreaterThan(0, $result['ContentLength']);
    }

    public function testFileNotExists(): void
    {
        $key = self::$testPrefix . 'nonexistent-' . bin2hex(random_bytes(8)) . '.txt';

        $this->expectException(AwsException::class);
        self::$client->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
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

        // Verify destination
        $result = self::$client->getObject([
            'Bucket' => self::$bucket,
            'Key' => $destKey,
        ]);

        $this->assertEquals($content, (string) $result['Body']);
    }

    public function testDeleteFile(): void
    {
        $key = self::$testPrefix . 'delete-test.txt';

        // Upload
        self::$client->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'to delete',
        ]);

        // Delete
        self::$client->deleteObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        // Verify deleted
        $this->expectException(AwsException::class);
        self::$client->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
    }

    public function testListWithPrefix(): void
    {
        $prefix = self::$testPrefix . 'list-test/';

        // Upload files
        for ($i = 1; $i <= 3; $i++) {
            self::$client->putObject([
                'Bucket' => self::$bucket,
                'Key' => $prefix . "file{$i}.txt",
                'Body' => "content {$i}",
            ]);
        }

        // List
        $result = self::$client->listObjectsV2([
            'Bucket' => self::$bucket,
            'Prefix' => $prefix,
        ]);

        $this->assertCount(3, $result['Contents']);
    }

    public function testContentTypePreserved(): void
    {
        $key = self::$testPrefix . 'image-test.jpg';

        self::$client->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'fake image',
            'ContentType' => 'image/jpeg',
        ]);

        $result = self::$client->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $this->assertEquals('image/jpeg', $result['ContentType']);
    }
}
