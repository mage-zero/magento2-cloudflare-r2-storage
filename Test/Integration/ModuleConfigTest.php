<?php
namespace MageZero\CloudflareR2\Test\Integration;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Module\ModuleList;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for module configuration.
 */
class ModuleConfigTest extends TestCase
{
    private const MODULE_NAME = 'MageZero_CloudflareR2';

    public function testModuleIsRegistered(): void
    {
        $registrar = new ComponentRegistrar();
        $paths = $registrar->getPaths(ComponentRegistrar::MODULE);

        $this->assertArrayHasKey(self::MODULE_NAME, $paths);
    }

    public function testModuleIsEnabled(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $moduleList = $objectManager->get(ModuleList::class);

        $this->assertTrue($moduleList->has(self::MODULE_NAME));
    }

    public function testConfigModelCanBeInstantiated(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $config = $objectManager->get(\MageZero\CloudflareR2\Model\Config::class);

        $this->assertInstanceOf(\MageZero\CloudflareR2\Model\Config::class, $config);
    }

    public function testR2StorageModelCanBeInstantiated(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $r2 = $objectManager->get(\MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2::class);

        $this->assertInstanceOf(\MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2::class, $r2);
    }

    public function testR2StorageReturnsCorrectName(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $r2 = $objectManager->get(\MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2::class);

        $this->assertEquals('Cloudflare R2 (S3 Compatible)', (string) $r2->getStorageName());
    }
}
