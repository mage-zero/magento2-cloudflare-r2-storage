<?php
namespace MageZero\CloudflareR2\Model\MediaStorage;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2;
use Magento\Catalog\Model\Product\Media\ConfigInterface as MediaConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Synchronizes locally generated image cache to R2 storage
 *
 * After bin/magento catalog:images:resize generates cached images to pub/media,
 * this synchronizer uploads them to R2 and optionally cleans up local files.
 */
class ImageCacheSynchronizer
{
    private Config $config;
    private MediaConfig $mediaConfig;
    private Filesystem\Directory\ReadInterface $mediaDirectory;
    private R2 $r2Storage;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        MediaConfig $mediaConfig,
        Filesystem $filesystem,
        R2 $r2Storage,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->mediaConfig = $mediaConfig;
        $this->mediaDirectory = $filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $this->r2Storage = $r2Storage;
        $this->logger = $logger;
    }

    /**
     * Sync locally generated image cache to R2
     */
    public function sync(): void
    {
        if (!$this->config->isR2Selected()) {
            return;
        }

        $cachePath = $this->mediaConfig->getBaseMediaPath() . '/cache';

        if (!$this->mediaDirectory->isDirectory($cachePath)) {
            return;
        }

        $this->syncDirectory($cachePath);
    }

    private function syncDirectory(string $path): void
    {
        try {
            $files = $this->mediaDirectory->read($path);

            foreach ($files as $file) {
                $relativePath = $file;

                if ($this->mediaDirectory->isDirectory($relativePath)) {
                    $this->syncDirectory($relativePath);
                } elseif ($this->mediaDirectory->isFile($relativePath)) {
                    $this->syncFile($relativePath);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error syncing image cache directory', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function syncFile(string $relativePath): void
    {
        try {
            $content = $this->mediaDirectory->readFile($relativePath);

            $this->r2Storage->saveFile([
                'filename' => $relativePath,
                'content' => $content
            ]);

            $this->logger->debug('Synced image cache file to R2', ['path' => $relativePath]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to sync image cache file to R2', [
                'path' => $relativePath,
                'error' => $e->getMessage()
            ]);
        }
    }
}
