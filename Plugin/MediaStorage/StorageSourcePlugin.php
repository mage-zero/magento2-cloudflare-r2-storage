<?php
declare(strict_types=1);

namespace MageZero\CloudflareR2\Plugin\MediaStorage;

use MageZero\CloudflareR2\Model\ConnectionValidator;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage as R2Storage;
use Magento\MediaStorage\Model\Config\Source\Storage\Media\Storage;

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class StorageSourcePlugin
{
    private ConnectionValidator $connectionValidator;

    public function __construct(ConnectionValidator $connectionValidator)
    {
        $this->connectionValidator = $connectionValidator;
    }

    public function afterToOptionArray(Storage $subject, array $result): array
    {
        if (!$this->connectionValidator->isConnectionValid()) {
            return $result;
        }

        $result[] = [
            'value' => R2Storage::STORAGE_MEDIA_R2,
            'label' => __('Cloudflare R2 (S3 Compatible)'),
        ];

        return $result;
    }
}
