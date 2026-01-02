<?php
namespace MageZero\CloudflareR2\Plugin\MediaStorage;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\MediaStorage\ImageCacheSynchronizer;
use Magento\MediaStorage\Service\ImageResize;

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class ImageResizePlugin
{
    private Config $config;
    private ImageCacheSynchronizer $cacheSynchronizer;

    public function __construct(
        Config $config,
        ImageCacheSynchronizer $cacheSynchronizer
    ) {
        $this->config = $config;
        $this->cacheSynchronizer = $cacheSynchronizer;
    }

    public function aroundResizeFromThemes(
        ImageResize $subject,
        callable $proceed,
        ?array $themes = null,
        bool $skipHiddenImages = false
    ): \Generator {
        $generator = $proceed($themes, $skipHiddenImages);
        if (!$this->config->isR2Selected()) {
            return $generator;
        }

        return (function () use ($generator) {
            try {
                yield from $generator;
            } finally {
                $this->cacheSynchronizer->sync();
            }
        })();
    }
}
