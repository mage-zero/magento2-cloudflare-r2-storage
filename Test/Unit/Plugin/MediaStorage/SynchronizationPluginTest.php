<?php
namespace MageZero\CloudflareR2\Test\Unit\Plugin\MediaStorage;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\FileExistenceCache;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2Factory;
use MageZero\CloudflareR2\Plugin\MediaStorage\SynchronizationPlugin;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\HTTP\ClientInterface;
use Magento\MediaStorage\Model\File\Storage\Synchronization;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SynchronizationPluginTest extends TestCase
{
    private Config|MockObject $config;
    private FileExistenceCache|MockObject $fileExistenceCache;
    private ClientInterface|MockObject $httpClient;
    private LoggerInterface|MockObject $logger;
    private SynchronizationPlugin $plugin;
    private Synchronization|MockObject $subject;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->fileExistenceCache = $this->createMock(FileExistenceCache::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->subject = $this->createMock(Synchronization::class);

        $this->httpClient = $this->createMock(ClientInterface::class);

        $storageFactory = $this->createMock(R2Factory::class);
        $mediaDirectory = $this->createMock(WriteInterface::class);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')
            ->with(DirectoryList::MEDIA)
            ->willReturn($mediaDirectory);

        $this->plugin = new SynchronizationPlugin(
            $this->config,
            $storageFactory,
            $filesystem,
            $this->fileExistenceCache,
            $this->httpClient,
            $this->logger
        );
    }

    public function testBeforeSynchronizeSkipsWhenR2NotSelected(): void
    {
        $this->config->method('isR2Selected')->willReturn(false);
        $this->httpClient->expects($this->never())->method('get');
        $this->fileExistenceCache->expects($this->never())->method('get');

        $result = $this->plugin->beforeSynchronize($this->subject, 'test/image.jpg');

        $this->assertEquals(['test/image.jpg'], $result);
    }

    public function testBeforeSynchronizeUsesCacheHit(): void
    {
        $this->config->method('isR2Selected')->willReturn(true);
        $this->fileExistenceCache->method('get')
            ->with('test/image.jpg')
            ->willReturn(true);

        $this->httpClient->expects($this->never())->method('get');
        $this->fileExistenceCache->expects($this->never())->method('set');

        $result = $this->plugin->beforeSynchronize($this->subject, 'test/image.jpg');

        $this->assertEquals(['test/image.jpg'], $result);
    }

    public function testBeforeSynchronizeCacheMissHttpSuccess(): void
    {
        $this->config->method('isR2Selected')->willReturn(true);
        $this->config->method('getBaseMediaUrl')->willReturn('https://cdn.example.com');
        $this->fileExistenceCache->method('get')->willReturn(null);

        $this->httpClient->expects($this->once())
            ->method('get')
            ->with('https://cdn.example.com/test/image.jpg');
        $this->httpClient->method('getStatus')->willReturn(200);

        $this->fileExistenceCache->expects($this->once())
            ->method('set')
            ->with('test/image.jpg', true);

        $result = $this->plugin->beforeSynchronize($this->subject, 'test/image.jpg');

        $this->assertEquals(['test/image.jpg'], $result);
    }

    public function testBeforeSynchronizeCacheMissHttpNotFound(): void
    {
        $this->config->method('isR2Selected')->willReturn(true);
        $this->config->method('getBaseMediaUrl')->willReturn('https://cdn.example.com');
        $this->fileExistenceCache->method('get')->willReturn(null);

        $this->httpClient->method('getStatus')->willReturn(404);

        $this->fileExistenceCache->expects($this->once())
            ->method('set')
            ->with('test/image.jpg', false);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('File not found in CDN', $this->anything());

        $this->plugin->beforeSynchronize($this->subject, 'test/image.jpg');
    }

    public function testBeforeSynchronizeHttpExceptionNotCached(): void
    {
        $this->config->method('isR2Selected')->willReturn(true);
        $this->config->method('getBaseMediaUrl')->willReturn('https://cdn.example.com');
        $this->fileExistenceCache->method('get')->willReturn(null);

        $this->httpClient->method('get')
            ->willThrowException(new \Exception('Connection failed'));

        $this->fileExistenceCache->expects($this->never())->method('set');

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Error checking file existence in CDN', $this->anything());

        $this->plugin->beforeSynchronize($this->subject, 'test/image.jpg');
    }

    public function testBeforeSynchronizeWarnsWhenBaseMediaUrlNotConfigured(): void
    {
        $this->config->method('isR2Selected')->willReturn(true);
        $this->config->method('getBaseMediaUrl')->willReturn('');
        $this->fileExistenceCache->method('get')->willReturn(null);

        $this->httpClient->expects($this->never())->method('get');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Read-only mode enabled but Base Media URL not configured. File existence checks will fail.',
                $this->anything()
            );

        $this->plugin->beforeSynchronize($this->subject, 'test/image.jpg');
    }

    public function testBeforeSynchronizeStripsLeadingSlashFromUrl(): void
    {
        $this->config->method('isR2Selected')->willReturn(true);
        $this->config->method('getBaseMediaUrl')->willReturn('https://cdn.example.com');
        $this->fileExistenceCache->method('get')->willReturn(null);
        $this->httpClient->method('getStatus')->willReturn(200);

        $this->httpClient->expects($this->once())
            ->method('get')
            ->with('https://cdn.example.com/test/image.jpg');

        $this->plugin->beforeSynchronize($this->subject, '/test/image.jpg');
    }

    public function testBeforeSynchronizeSetsHeadRequestOption(): void
    {
        $this->config->method('isR2Selected')->willReturn(true);
        $this->config->method('getBaseMediaUrl')->willReturn('https://cdn.example.com');
        $this->fileExistenceCache->method('get')->willReturn(null);
        $this->httpClient->method('getStatus')->willReturn(200);

        $this->httpClient->expects($this->once())
            ->method('setOption')
            ->with(CURLOPT_NOBODY, true);

        $this->plugin->beforeSynchronize($this->subject, 'test/image.jpg');
    }
}
