<?php
namespace MageZero\CloudflareR2\Plugin\MediaStorage;

use MageZero\CloudflareR2\Model\MediaStorage\File\Storage as R2Storage;
use Magento\MediaStorage\Model\Config\Source\Storage\Media\Storage;

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class StorageSourcePlugin
{
    public function afterToOptionArray(Storage $subject, array $result): array
    {
        $result[] = [
            'value' => R2Storage::STORAGE_MEDIA_R2,
            'label' => __('Cloudflare R2 (S3 Compatible)'),
        ];

        return $result;
    }
}
