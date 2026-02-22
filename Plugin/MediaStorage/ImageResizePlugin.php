<?php
namespace MageZero\CloudflareR2\Plugin\MediaStorage;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\DownloadedFileTracker;
use MageZero\CloudflareR2\Model\MediaStorage\ImageCacheSynchronizer;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\MediaStorage\Service\ImageResize;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class ImageResizePlugin
{
    private Config $config;
    private ImageCacheSynchronizer $cacheSynchronizer;
    private DownloadedFileTracker $downloadTracker;
    private Filesystem\Directory\WriteInterface $mediaDirectory;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        ImageCacheSynchronizer $cacheSynchronizer,
        DownloadedFileTracker $downloadTracker,
        Filesystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->cacheSynchronizer = $cacheSynchronizer;
        $this->downloadTracker = $downloadTracker;
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->logger = $logger;
    }

    public function aroundResizeFromThemes(
        ImageResize $subject,
        callable $proceed,
        ?array $themes = null,
        bool $skipHiddenImages = false
    ): \Generator {
        $generator = $proceed($themes, $skipHiddenImages);
        if (!$this->config->isR2Selected()) {
            return $generator;
        }

        // In read-only mode, images are generated in /tmp and uploaded directly
        // The sync() call is a no-op in read-only mode
        return (function () use ($generator) {
            try {
                yield from $generator;
            } finally {
                $this->cacheSynchronizer->sync();
                $this->cleanupDownloadedFiles();
            }
        })();
    }

    private function cleanupDownloadedFiles(): void
    {
        $files = $this->downloadTracker->getAndClear();
        foreach ($files as $relativePath) {
            try {
                if ($this->mediaDirectory->isFile($relativePath)) {
                    $this->mediaDirectory->delete($relativePath);
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to clean up downloaded file', [
                    'path' => $relativePath,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
