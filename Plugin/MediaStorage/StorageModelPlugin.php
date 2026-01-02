<?php
namespace MageZero\CloudflareR2\Plugin\MediaStorage;

use MageZero\CloudflareR2\Model\MediaStorage\File\Storage as R2Storage;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2Factory;
use Magento\MediaStorage\Helper\File\Storage as StorageHelper;
use Magento\MediaStorage\Model\File\Storage;

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class StorageModelPlugin
{
    private StorageHelper $storageHelper;
    private R2Factory $r2Factory;

    public function __construct(
        StorageHelper $storageHelper,
        R2Factory $r2Factory
    ) {
        $this->storageHelper = $storageHelper;
        $this->r2Factory = $r2Factory;
    }

    public function aroundGetStorageModel(Storage $subject, callable $proceed, $storage = null, array $params = [])
    {
        $storageModel = $proceed($storage, $params);
        if ($storageModel !== false) {
            return $storageModel;
        }

        if ($storage === null) {
            $storage = $this->storageHelper->getCurrentStorageCode();
        }

        if ((int)$storage === R2Storage::STORAGE_MEDIA_R2) {
            $storageModel = $this->r2Factory->create();
        } else {
            return false;
        }

        if (!empty($params['init'])) {
            $storageModel->init();
        }

        return $storageModel;
    }
}
