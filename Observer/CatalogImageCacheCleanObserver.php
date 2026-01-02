<?php
namespace MageZero\CloudflareR2\Observer;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2Factory;
use Magento\Catalog\Model\Product\Media\ConfigInterface as MediaConfig;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class CatalogImageCacheCleanObserver implements ObserverInterface
{
    private Config $config;
    private R2Factory $storageFactory;
    private MediaConfig $mediaConfig;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        R2Factory $storageFactory,
        MediaConfig $mediaConfig,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->storageFactory = $storageFactory;
        $this->mediaConfig = $mediaConfig;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isR2Selected()) {
            return;
        }

        $cachePath = $this->mediaConfig->getBaseMediaPath() . '/cache';

        try {
            $this->storageFactory->create()->deleteDirectory($cachePath);
        } catch (\Throwable $exception) {
            $this->logger->warning(
                sprintf('Unable to delete R2 catalog image cache "%s": %s', $cachePath, $exception->getMessage())
            );
        }
    }
}
