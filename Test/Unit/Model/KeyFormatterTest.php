<?php
namespace MageZero\CloudflareR2\Test\Unit\Model;

use MageZero\CloudflareR2\Model\KeyFormatter;
use PHPUnit\Framework\TestCase;

class KeyFormatterTest extends TestCase
{
    public function testToKeyAddsPrefix(): void
    {
        $formatter = new KeyFormatter('media');

        $this->assertSame('media/catalog/product.jpg', $formatter->toKey('catalog/product.jpg'));
        $this->assertSame('media/catalog/product.jpg', $formatter->toKey('/catalog/product.jpg'));
    }

    public function testFromKeyRemovesPrefix(): void
    {
        $formatter = new KeyFormatter('media');

        $this->assertSame('catalog/product.jpg', $formatter->fromKey('media/catalog/product.jpg'));
        $this->assertSame('catalog/product.jpg', $formatter->fromKey('/media/catalog/product.jpg'));
    }

    public function testPrefixOptional(): void
    {
        $formatter = new KeyFormatter('');

        $this->assertSame('catalog/product.jpg', $formatter->toKey('catalog/product.jpg'));
        $this->assertSame('catalog/product.jpg', $formatter->fromKey('catalog/product.jpg'));
    }
}
