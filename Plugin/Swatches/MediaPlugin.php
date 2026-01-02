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
    private array $swatchImageTypes = ['swatch_image', 'swatch_thumb'];

    public function __construct(
        Config $config,
        MediaConfig $mediaConfig,
        Database $fileStorageDb
    ) {
        $this->config = $config;
        $this->mediaConfig = $mediaConfig;
        $this->fileStorageDb = $fileStorageDb;
    }

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

    public function aroundGenerateSwatchVariations(Media $subject, callable $proceed, $imageUrl)
    {
        if ($this->fileStorageDb->checkDbUsage() && $this->config->isR2Selected()) {
            $fileToRestore = $subject->getAttributeSwatchPath($imageUrl);
            $this->fileStorageDb->saveFileToFilesystem($fileToRestore);

            $result = $proceed($imageUrl);

            foreach ($this->swatchImageTypes as $swatchType) {
                $imageConfig = $subject->getImageConfig();
                $fileName = $this->prepareFileName($imageUrl);
                $swatchPath = $subject->getSwatchCachePath($swatchType)
                    . $subject->getFolderNameSize($swatchType, $imageConfig)
                    . $fileName['path'] . '/' . $fileName['name'];
                $this->fileStorageDb->saveFile($swatchPath);
            }

            return $result;
        }

        return $proceed($imageUrl);
    }

    private function prepareFileName($imageUrl): array
    {
        $fileArray = explode('/', $imageUrl);
        $fileName = array_pop($fileArray);
        $filePath = implode('/', $fileArray);

        return ['name' => $fileName, 'path' => $filePath];
    }

    private function getUniqueFileName($file): string
    {
        return $this->fileStorageDb->getUniqueFilename(
            $this->mediaConfig->getBaseMediaUrlAddition(),
            $file
        );
    }
}
