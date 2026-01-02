<?php
namespace MageZero\CloudflareR2\Plugin\MediaStorage;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Exception\FileSystemException;
use Magento\MediaStorage\Model\File\Storage\Synchronization;
use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2Factory;

class SynchronizationPlugin
{
    private Config $config;
    private R2Factory $storageFactory;
    private Filesystem\Directory\WriteInterface $mediaDirectory;

    public function __construct(
        Config $config,
        R2Factory $storageFactory,
        Filesystem $filesystem
    ) {
        $this->config = $config;
        $this->storageFactory = $storageFactory;
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
    }

    public function beforeSynchronize(Synchronization $subject, $relativeFileName)
    {
        if (!$this->config->isR2Selected()) {
            return [$relativeFileName];
        }

        $storage = $this->storageFactory->create();
        try {
            $storage->loadByFilename($relativeFileName);
        } catch (\Exception $exception) {
            return [$relativeFileName];
        }

        if ($storage->getId()) {
            $file = $this->mediaDirectory->openFile($relativeFileName, 'w');
            try {
                $file->lock();
                $file->write($storage->getContent());
                $file->unlock();
                $file->close();
            } catch (FileSystemException $exception) {
                $file->close();
            }
        }

        return [$relativeFileName];
    }
}
