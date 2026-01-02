<?php
namespace MageZero\CloudflareR2\Plugin\Swatches;

use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\Swatches\Helper\Media;
use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\ImageProcessor\TemporaryProcessor;
use Psr\Log\LoggerInterface;

class MediaPlugin
{
    private Config $config;
    private MediaConfig $mediaConfig;
    private Database $fileStorageDb;
    private TemporaryProcessor $tempProcessor;
    private FileDriver $fileDriver;
    private LoggerInterface $logger;
    private array $swatchImageTypes = ['swatch_image', 'swatch_thumb'];

    public function __construct(
        Config $config,
        MediaConfig $mediaConfig,
        Database $fileStorageDb,
        TemporaryProcessor $tempProcessor,
        FileDriver $fileDriver,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->mediaConfig = $mediaConfig;
        $this->fileStorageDb = $fileStorageDb;
        $this->tempProcessor = $tempProcessor;
        $this->fileDriver = $fileDriver;
        $this->logger = $logger;
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
        if (!$this->fileStorageDb->checkDbUsage() || !$this->config->isR2Selected()) {
            return $proceed($imageUrl);
        }

        // Read-only mode: process swatches in /tmp
        if ($this->config->isReadOnlyMode()) {
            return $this->generateSwatchVariationsInTemp($subject, $proceed, $imageUrl);
        }

        // Traditional mode: download to local filesystem
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

    private function generateSwatchVariationsInTemp(Media $subject, callable $proceed, string $imageUrl): bool
    {
        try {
            // Download original swatch image from R2 to /tmp
            $fileToRestore = $subject->getAttributeSwatchPath($imageUrl);
            $tempOriginal = $this->tempProcessor->downloadToTemp($fileToRestore);

            if (!$tempOriginal) {
                $this->logger->error('Failed to download swatch image from R2', ['path' => $fileToRestore]);
                return false;
            }

            // Create temp directory structure for variations
            $tempMediaPath = $this->tempProcessor->getTempDir() . '/swatch_variations';
            if (!$this->fileDriver->isDirectory($tempMediaPath)) {
                $this->fileDriver->createDirectory($tempMediaPath, 0755);
            }

            // Temporarily copy to swatch path structure for processing
            $tempSwatchPath = $tempMediaPath . '/' . $fileToRestore;
            $tempSwatchDir = dirname($tempSwatchPath);
            if (!$this->fileDriver->isDirectory($tempSwatchDir)) {
                $this->fileDriver->createDirectory($tempSwatchDir, 0755);
            }
            $this->fileDriver->copy($tempOriginal, $tempSwatchPath);

            // Generate variations (Magento will write to pub/media or similar, but we'll handle it)
            // This might fail if filesystem is truly read-only, but swatch generation
            // typically happens during product save when images are uploaded
            $result = $proceed($imageUrl);

            // Upload each variation to R2
            foreach ($this->swatchImageTypes as $swatchType) {
                $imageConfig = $subject->getImageConfig();
                $fileName = $this->prepareFileName($imageUrl);
                $swatchPath = $subject->getSwatchCachePath($swatchType)
                    . $subject->getFolderNameSize($swatchType, $imageConfig)
                    . $fileName['path'] . '/' . $fileName['name'];

                // Check if variation was created in temp location
                $tempVariationPath = $this->tempProcessor->getTempPath($swatchPath);
                if ($this->fileDriver->isFile($tempVariationPath)) {
                    $this->tempProcessor->uploadToR2($tempVariationPath, $swatchPath);
                    $this->tempProcessor->cleanup($tempVariationPath);
                }
            }

            // Cleanup original temp file
            $this->tempProcessor->cleanup($tempOriginal);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to generate swatch variations in read-only mode',
                ['imageUrl' => $imageUrl, 'error' => $e->getMessage()]
            );
            return false;
        }
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
