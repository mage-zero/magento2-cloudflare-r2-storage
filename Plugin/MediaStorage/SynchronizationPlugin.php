<?php
namespace MageZero\CloudflareR2\Plugin\MediaStorage;

use Magento\MediaStorage\Model\File\Storage\Synchronization;

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class SynchronizationPlugin
{
    /**
     * Keep default synchronization flow untouched.
     *
     * This plugin intentionally does not perform preflight CDN HEAD requests.
     * During image resize, those checks are pure overhead because synchronize()
     * still performs the actual fetch path afterwards.
     */
    public function beforeSynchronize(Synchronization $subject, $relativeFileName): array
    {
        return [$relativeFileName];
    }
}
