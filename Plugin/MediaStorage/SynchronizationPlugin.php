<?php
namespace MageZero\CloudflareR2\Plugin\MediaStorage;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\HTTP\ClientInterface;
use Magento\MediaStorage\Model\File\Storage\Synchronization;
use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\FileExistenceCache;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2Factory;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class SynchronizationPlugin
{
    private Config $config;
    private R2Factory $storageFactory;
    private Filesystem\Directory\WriteInterface $mediaDirectory;
    private FileExistenceCache $fileExistenceCache;
    private ClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        R2Factory $storageFactory,
        Filesystem $filesystem,
        FileExistenceCache $fileExistenceCache,
        ClientInterface $httpClient,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->storageFactory = $storageFactory;
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->fileExistenceCache = $fileExistenceCache;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    public function beforeSynchronize(Synchronization $subject, $relativeFileName)
    {
        if (!$this->config->isR2Selected()) {
            return [$relativeFileName];
        }

        // Check CDN for file existence (never write to local filesystem)
        $this->checkFileExistsInCdn($relativeFileName);

        return [$relativeFileName];
    }

    private function checkFileExistsInCdn(string $relativeFileName): void
    {
        // Check cache first
        $cachedResult = $this->fileExistenceCache->get($relativeFileName);
        if ($cachedResult !== null) {
            return; // Cache hit, no need to check CDN
        }

        // Cache miss - check CDN with HEAD request
        $baseMediaUrl = $this->config->getBaseMediaUrl();
        if (empty($baseMediaUrl)) {
            $this->logger->warning(
                'Read-only mode enabled but Base Media URL not configured. File existence checks will fail.',
                ['filename' => $relativeFileName]
            );
            return;
        }

        $cdnUrl = $baseMediaUrl . '/' . ltrim($relativeFileName, '/');

        try {
            // Use HEAD request to check existence without downloading content
            $this->httpClient->setOptions(['timeout' => 5]);
            $this->httpClient->request('HEAD', $cdnUrl);
            $statusCode = $this->httpClient->getStatus();
            $exists = $statusCode === 200;

            // Cache the result
            $this->fileExistenceCache->set($relativeFileName, $exists);

            if (!$exists) {
                $this->logger->debug(
                    'File not found in CDN',
                    ['filename' => $relativeFileName, 'url' => $cdnUrl, 'status' => $statusCode]
                );
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Error checking file existence in CDN',
                ['filename' => $relativeFileName, 'url' => $cdnUrl, 'error' => $e->getMessage()]
            );
            // Don't cache errors - allow retry on next request
        }
    }
}
