<?php
namespace MageZero\CloudflareR2\Plugin\Swatches;

use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\Swatches\Helper\Media;
use MageZero\CloudflareR2\Model\Config;

class MediaPlugin
{
    private Config $config;
    private MediaConfig $mediaConfig;
    private Database $fileStorageDb;

    public function __construct(
        Config $config,
        MediaConfig $mediaConfig,
        Database $fileStorageDb
    ) {
        $this->config = $config;
        $this->mediaConfig = $mediaConfig;
        $this->fileStorageDb = $fileStorageDb;
    }

    /**
     * Handle moving swatch images from tmp to permanent storage in R2
     */
    public function beforeMoveImageFromTmp(Media $subject, $file)
    {
        if ($this->fileStorageDb->checkDbUsage() && $this->config->isR2Selected()) {
            if (strrpos($file, '.tmp') === strlen($file) - 4) {
                $updatedFile = substr($file, 0, -4);
            } else {
                $updatedFile = $file;
            }

            $destinationFile = $this->getUniqueFileName($updatedFile);

            $this->fileStorageDb->copyFile(
                $this->mediaConfig->getTmpMediaShortUrl($updatedFile),
                $subject->getAttributeSwatchPath($destinationFile)
            );

            $file = '/' . ltrim($file, '/');
        }

        return [$file];
    }

    private function getUniqueFileName($file): string
    {
        return $this->fileStorageDb->getUniqueFilename(
            $this->mediaConfig->getBaseMediaUrlAddition(),
            $file
        );
    }
}
