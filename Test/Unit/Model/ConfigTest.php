<?php
namespace MageZero\CloudflareR2\Test\Unit\Model;

use MageZero\CloudflareR2\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testIsR2SelectedReturnsTrueWhenStorageMatches(): void
    {
        $values = [
            Config::XML_PATH_STORAGE_MEDIA => 2,
        ];

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use ($values) {
                return $values[$path] ?? null;
            }
        );

        $config = new Config($scopeConfig);
        $this->assertTrue($config->isR2Selected());
    }

    public function testEndpointUsesAccountIdWhenEndpointEmpty(): void
    {
        $values = [
            Config::XML_PATH_ENDPOINT => '',
            Config::XML_PATH_ACCOUNT_ID => 'abc123',
        ];

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use ($values) {
                return $values[$path] ?? null;
            }
        );

        $config = new Config($scopeConfig);
        $this->assertSame('https://abc123.r2.cloudflarestorage.com', $config->getEndpoint());
    }

    public function testRegionDefaultsToAuto(): void
    {
        $values = [
            Config::XML_PATH_REGION => '',
        ];

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use ($values) {
                return $values[$path] ?? null;
            }
        );

        $config = new Config($scopeConfig);
        $this->assertSame('auto', $config->getRegion());
    }
}
