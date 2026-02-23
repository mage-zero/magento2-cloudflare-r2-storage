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
    private const CACHE_PATH = 'catalog/product/cache';

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

        // Iterate manually so we can clean up after each image.
        // Each yield corresponds to one product image whose originals have
        // been downloaded from R2 and whose resized variants have already
        // been uploaded back to R2 by Magento's generateResizedImage().
        // Without per-image cleanup, originals and cache variants accumulate
        // on the (small) tmpfs media mount and eventually fill it.
        return (function () use ($generator) {
            try {
                foreach ($generator as $key => $value) {
                    yield $key => $value;
                    $this->cleanupDownloadedFiles();
                    $this->cleanupCacheFiles();
                }
            } finally {
                $this->cacheSynchronizer->sync();
                $this->cleanupDownloadedFiles();
                $this->cleanupCacheFiles();
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

    /**
     * Delete locally generated cache variants that have already been uploaded to R2.
     *
     * Magento's generateResizedImage() uploads each variant via Database::saveFile()
     * immediately after creating it, so the local copies under catalog/product/cache/
     * are safe to remove.
     */
    private function cleanupCacheFiles(): void
    {
        try {
            if ($this->mediaDirectory->isDirectory(self::CACHE_PATH)) {
                $this->mediaDirectory->delete(self::CACHE_PATH);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to clean up image cache directory', [
                'path' => self::CACHE_PATH,
                'error' => $e->getMessage()
            ]);
        }
    }
}
