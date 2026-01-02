<?php
namespace MageZero\CloudflareR2\Plugin\MediaStorage;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2Factory;
use Magento\MediaStorage\Helper\File\Storage\Database;

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class DatabaseHelperPlugin
{
    private Config $config;
    private R2Factory $r2Factory;
    private ?R2 $r2StorageModel = null;

    public function __construct(
        Config $config,
        R2Factory $r2Factory
    ) {
        $this->config = $config;
        $this->r2Factory = $r2Factory;
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
            $file = $subject->getStorageDatabaseModel()->loadByFilename(
                $subject->getMediaRelativePath($filename)
            );
            if (!$file->getId()) {
                return false;
            }

            return $subject->getStorageFileModel()->saveFile($file->getData(), true);
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
