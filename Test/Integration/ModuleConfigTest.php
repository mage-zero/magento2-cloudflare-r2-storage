<?php
namespace MageZero\CloudflareR2\Test\Integration;

use Magento\Framework\Component\ComponentRegistrar;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for module configuration.
 *
 * These tests only verify module registration.
 * Full integration tests with Magento framework run in CI.
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
}
