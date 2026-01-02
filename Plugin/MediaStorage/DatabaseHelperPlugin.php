<?php
namespace MageZero\CloudflareR2\Plugin\MediaStorage;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2Factory;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\MediaStorage\Model\File\Storage\DatabaseFactory;

class DatabaseHelperPlugin
{
    private Config $config;
    private R2Factory $r2Factory;
    private DatabaseFactory $dbStorageFactory;
    private $storageModel = null;

    public function __construct(
        Config $config,
        R2Factory $r2Factory,
        DatabaseFactory $dbStorageFactory
    ) {
        $this->config = $config;
        $this->r2Factory = $r2Factory;
        $this->dbStorageFactory = $dbStorageFactory;
    }

    public function afterCheckDbUsage(Database $subject, $result)
    {
        if (!$result && $this->config->isR2Selected()) {
            return true;
        }

        return $result;
    }

    public function aroundGetStorageDatabaseModel(Database $subject, callable $proceed)
    {
        if ($this->storageModel === null) {
            if ($subject->checkDbUsage() && $this->config->isR2Selected()) {
                $this->storageModel = $this->r2Factory->create();
            } else {
                $this->storageModel = $this->dbStorageFactory->create();
            }
        }

        return $this->storageModel;
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
