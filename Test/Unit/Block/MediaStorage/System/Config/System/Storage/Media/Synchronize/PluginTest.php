<?php
declare(strict_types=1);

namespace MageZero\CloudflareR2\Test\Unit\Block\MediaStorage\System\Config\System\Storage\Media\Synchronize;

use MageZero\CloudflareR2\Block\MediaStorage\System\Config\System\Storage\Media\Synchronize\Plugin;
use PHPUnit\Framework\TestCase;

class PluginTest extends TestCase
{
    public function testAroundGetTemplateReturnsCustomTemplate(): void
    {
        $plugin = new Plugin();

        $result = $plugin->aroundGetTemplate();

        $this->assertEquals(
            'MageZero_CloudflareR2::system/config/system/storage/media/synchronize.phtml',
            $result
        );
    }
}
