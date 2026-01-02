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
        // No-op: images are processed in /tmp and uploaded directly to R2
        // There is no local cache directory to sync from
        if (!$this->config->isR2Selected()) {
            return;
        }

        // When R2 is selected, images are always uploaded directly
        // This sync is not needed
        return;
    }
}
