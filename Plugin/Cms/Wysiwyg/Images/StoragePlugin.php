<?php
namespace MageZero\CloudflareR2\Plugin\Cms\Wysiwyg\Images;

use Magento\Cms\Model\Wysiwyg\Images\Storage;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\MediaStorage\Model\File\Storage\Directory\DatabaseFactory;
use MageZero\CloudflareR2\Model\Config;

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class StoragePlugin
{
    private Config $config;
    private Database $database;
    private Database $coreFileStorageDb;
    private Filesystem\Directory\WriteInterface $directory;
    private DatabaseFactory $directoryDatabaseFactory;

    public function __construct(
        Config $config,
        Database $database,
        Database $coreFileStorageDb,
        Filesystem $filesystem,
        DatabaseFactory $directoryDatabaseFactory
    ) {
        $this->config = $config;
        $this->database = $database;
        $this->coreFileStorageDb = $coreFileStorageDb;
        $this->directory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->directoryDatabaseFactory = $directoryDatabaseFactory;
    }

    public function beforeGetDirsCollection(Storage $subject, $path)
    {
        $this->createSubDirectories($path);
        return [$path];
    }

    private function createSubDirectories(string $path): void
    {
        if ($this->coreFileStorageDb->checkDbUsage() && $this->config->isR2Selected()) {
            $subDirectories = $this->directoryDatabaseFactory->create();
            $directories = $subDirectories->getSubdirectories($path);
            foreach ($directories as $directory) {
                $this->directory->create($directory['name']);
            }
        }
    }

    public function afterResizeFile(Storage $subject, $result)
    {
        if ($this->config->isR2Selected()) {
            $thumbnailRelativePath = $this->database->getMediaRelativePath($result);
            $this->database->getStorageDatabaseModel()->saveFile($thumbnailRelativePath);
        }

        return $result;
    }

    public function afterGetThumbsPath(Storage $subject, $result)
    {
        return rtrim($result, '/');
    }
}
