<?php
declare(strict_types=1);

namespace MageZero\CloudflareR2\Block\MediaStorage\System\Config\System\Storage\Media\Synchronize;

/**
 * Plugin to override the synchronize template for R2 storage support.
 *
 * The custom template modifies the getConnectionName() JavaScript function
 * to recognize storage type 2 (R2) without requiring a database connection name.
 */
class Plugin
{
    public function aroundGetTemplate(): string
    {
        return 'MageZero_CloudflareR2::system/config/system/storage/media/synchronize.phtml';
    }
}
