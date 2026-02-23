<?php
namespace MageZero\CloudflareR2\Test\Unit\Plugin\MediaStorage;

use MageZero\CloudflareR2\Plugin\MediaStorage\SynchronizationPlugin;
use Magento\MediaStorage\Model\File\Storage\Synchronization;
use PHPUnit\Framework\TestCase;

class SynchronizationPluginTest extends TestCase
{
    public function testBeforeSynchronizePassesFilenameThrough(): void
    {
        $plugin = new SynchronizationPlugin();
        $subject = $this->createMock(Synchronization::class);

        $result = $plugin->beforeSynchronize($subject, '/test/image.jpg');

        $this->assertSame(['/test/image.jpg'], $result);
    }
}
