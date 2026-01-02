<?php
namespace MageZero\CloudflareR2\Test\Unit\Model;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\R2ClientFactory;
use PHPUnit\Framework\TestCase;

class R2ClientFactoryTest extends TestCase
{
    public function testFactoryBuildsClientWithEndpoint(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getRegion')->willReturn('auto');
        $config->method('getAccessKey')->willReturn('key');
        $config->method('getSecretKey')->willReturn('secret');
        $config->method('usePathStyle')->willReturn(true);
        $config->method('getEndpoint')->willReturn('https://example.r2.cloudflarestorage.com');

        $factory = new R2ClientFactory($config);
        $client = $factory->create();

        $this->assertSame('https://example.r2.cloudflarestorage.com', (string)$client->getConfig('endpoint'));
        $this->assertTrue($client->getConfig('use_path_style_endpoint'));
        $this->assertSame('auto', $client->getConfig('region'));
    }
}
