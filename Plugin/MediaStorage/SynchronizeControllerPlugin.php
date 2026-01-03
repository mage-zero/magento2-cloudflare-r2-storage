<?php
declare(strict_types=1);

namespace MageZero\CloudflareR2\Plugin\MediaStorage;

use Magento\MediaStorage\Controller\Adminhtml\System\Config\System\Storage\Synchronize;
use Magento\MediaStorage\Model\File\Storage\Flag;

/**
 * Plugin to set sync flag state to NOTIFIED after sync completes.
 *
 * Magento's core Synchronize controller sets state to FINISHED (2), but
 * getSyncStorageParams() only returns the synced storage type when state
 * is NOTIFIED (3). Without this fix, the admin config save validation fails
 * after page refresh because the synced storage isn't recognized.
 */
class SynchronizeControllerPlugin
{
    private Flag $flag;

    public function __construct(Flag $flag)
    {
        $this->flag = $flag;
    }

    public function afterExecute(Synchronize $subject, $result): void
    {
        $flag = $this->flag->loadSelf();

        // If sync finished successfully (state FINISHED and no errors), set to NOTIFIED
        if ($flag->getState() == Flag::STATE_FINISHED) {
            $flagData = $flag->getFlagData();
            if (is_array($flagData) && empty($flagData['has_errors'])) {
                $flag->setState(Flag::STATE_NOTIFIED)->save();
            }
        }
    }
}
