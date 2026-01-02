<?php
namespace MageZero\CloudflareR2\Plugin\MediaStorage;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2;
use Magento\MediaStorage\Model\File\Storage\Database;

class DatabaseStoragePlugin
{
    private Config $config;
    private R2 $storageModel;

    public function __construct(
        Config $config,
        R2 $storageModel
    ) {
        $this->config = $config;
        $this->storageModel = $storageModel;
    }

    public function aroundGetDirectoryFiles(Database $subject, callable $proceed, $directory)
    {
        if ($this->config->isR2Selected()) {
            return $this->storageModel->getDirectoryFiles($directory);
        }

        return $proceed($directory);
    }
}
