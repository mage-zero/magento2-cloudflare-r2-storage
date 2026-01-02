<?php
namespace MageZero\CloudflareR2\Model\MediaStorage;

use MageZero\CloudflareR2\Model\Config;
use Magento\Catalog\Model\Product\Media\ConfigInterface as MediaConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\MediaStorage\Helper\File\Storage\Database as StorageDatabase;
use Psr\Log\LoggerInterface;

class ImageCacheSynchronizer
{
    private Config $config;
    private MediaConfig $mediaConfig;
    private Filesystem\Directory\WriteInterface $mediaDirectory;
    private StorageDatabase $storageDatabase;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        MediaConfig $mediaConfig,
        Filesystem $filesystem,
        StorageDatabase $storageDatabase,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->mediaConfig = $mediaConfig;
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->storageDatabase = $storageDatabase;
        $this->logger = $logger;
    }

    public function sync(): void
    {
        if (!$this->config->isR2Selected()) {
            return;
        }

        $cachePath = $this->mediaConfig->getBaseMediaPath() . '/cache';
        if (!$this->mediaDirectory->isExist($cachePath)) {
            return;
        }

        $entries = $this->mediaDirectory->readRecursively($cachePath);
        foreach ($entries as $entry) {
            if (!$this->mediaDirectory->isFile($entry)) {
                continue;
            }

            try {
                $this->storageDatabase->saveFile($entry);
            } catch (\Throwable $exception) {
                $this->logger->warning(
                    sprintf('Unable to sync resized image "%s" to R2: %s', $entry, $exception->getMessage())
                );
            }
        }
    }
}
