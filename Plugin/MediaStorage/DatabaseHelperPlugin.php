<?php
namespace MageZero\CloudflareR2\Plugin\MediaStorage;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\DownloadedFileTracker;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2Factory;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class DatabaseHelperPlugin
{
    private Config $config;
    private R2Factory $r2Factory;
    private DownloadedFileTracker $downloadTracker;
    private LoggerInterface $logger;
    private ?R2 $r2StorageModel = null;

    public function __construct(
        Config $config,
        R2Factory $r2Factory,
        DownloadedFileTracker $downloadTracker,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->r2Factory = $r2Factory;
        $this->downloadTracker = $downloadTracker;
        $this->logger = $logger;
    }

    public function afterCheckDbUsage(Database $subject, $result)
    {
        if (!$result && $this->config->isR2Selected()) {
            return true;
        }

        return $result;
    }

    public function afterGetStorageDatabaseModel(Database $subject, $result)
    {
        if ($this->config->isR2Selected()) {
            if ($this->r2StorageModel === null) {
                $this->r2StorageModel = $this->r2Factory->create();
            }
            return $this->r2StorageModel;
        }

        return $result;
    }

    public function aroundSaveFileToFilesystem(Database $subject, callable $proceed, $filename)
    {
        if ($subject->checkDbUsage() && $this->config->isR2Selected()) {
            $relativePath = $subject->getMediaRelativePath($filename);
            $file = $subject->getStorageDatabaseModel()->loadByFilename($relativePath);
            if (!$file->getId()) {
                return false;
            }

            try {
                $result = $subject->getStorageFileModel()->saveFile($file->getData(), true);
            } catch (\Exception $e) {
                // Log and return false so callers (e.g. catalog:images:resize)
                // skip this image rather than aborting the entire batch.
                $this->logger->error('R2: failed to save file to local filesystem', [
                    'path' => $relativePath,
                    'error' => $e->getMessage(),
                ]);
                return false;
            }

            if ($result) {
                $this->downloadTracker->track($relativePath);
            }
            return $result;
        }

        return $proceed($filename);
    }

    public function afterGetMediaRelativePath(Database $subject, $result)
    {
        if ($this->config->isR2Selected()) {
            $prefixToRemove = 'pub/media/';
            if (strpos($result, $prefixToRemove) === 0) {
                return substr($result, strlen($prefixToRemove));
            }
        }

        return $result;
    }

    public function aroundDeleteFolder(Database $subject, callable $proceed, $folderName)
    {
        if ($this->config->isR2Selected()) {
            $storageModel = $subject->getStorageDatabaseModel();
            $storageModel->deleteDirectory($folderName);
            return null;
        }

        return $proceed($folderName);
    }

    public function afterSaveUploadedFile(Database $subject, $result)
    {
        return ltrim($result, '/');
    }
}
