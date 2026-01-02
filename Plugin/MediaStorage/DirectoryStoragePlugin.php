<?php
namespace MageZero\CloudflareR2\Plugin\MediaStorage;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2;
use Magento\MediaStorage\Model\File\Storage\Directory\Database;

class DirectoryStoragePlugin
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

    public function aroundCreateRecursive(Database $subject, callable $proceed, $path)
    {
        if ($this->config->isR2Selected()) {
            return $subject;
        }

        return $proceed($path);
    }

    public function aroundGetSubdirectories(Database $subject, callable $proceed, $directory)
    {
        if ($this->config->isR2Selected()) {
            return $this->storageModel->getSubdirectories($directory);
        }

        return $proceed($directory);
    }

    public function aroundDeleteDirectory(Database $subject, callable $proceed, $path)
    {
        if ($this->config->isR2Selected()) {
            return $this->storageModel->deleteDirectory($path);
        }

        return $proceed($path);
    }
}
