<?php
namespace MageZero\CloudflareR2\Plugin\Catalog\Product\Image;

use Magento\Catalog\Model\Product\Image\Cache;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\ImageProcessor\TemporaryProcessor;
use Psr\Log\LoggerInterface;

/**
 * Plugin to handle image cache generation in /tmp
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class CachePlugin
{
    private Config $config;
    private TemporaryProcessor $tempProcessor;
    private FileDriver $fileDriver;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        TemporaryProcessor $tempProcessor,
        FileDriver $fileDriver,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->tempProcessor = $tempProcessor;
        $this->fileDriver = $fileDriver;
        $this->logger = $logger;
    }

    /**
     * Intercept resize method to handle /tmp processing
     */
    public function aroundResize(Cache $subject, callable $proceed, $originalImageName, $imageParams = [])
    {
        if (!$this->config->isR2Selected()) {
            return $proceed($originalImageName, $imageParams);
        }

        try {
            // Process image in /tmp and upload to R2
            return $this->resizeInTemp($originalImageName, $imageParams, $proceed);
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to resize image in /tmp',
                ['image' => $originalImageName, 'params' => $imageParams, 'error' => $e->getMessage()]
            );
            // Fallback to standard processing (will fail in read-only filesystem, but logged)
            return $proceed($originalImageName, $imageParams);
        }
    }

    private function resizeInTemp(string $originalImageName, array $imageParams, callable $proceed): string
    {
        // Download original image from R2 to /tmp
        $tempOriginal = $this->tempProcessor->downloadToTemp('catalog/product' . $originalImageName);

        if (!$tempOriginal) {
            throw new \RuntimeException('Failed to download original image from R2: ' . $originalImageName);
        }

        // Create temp directory structure that mimics pub/media
        $tempMediaRoot = $this->tempProcessor->getTempDir() . '/media_root';
        if (!$this->fileDriver->isDirectory($tempMediaRoot)) {
            $this->fileDriver->createDirectory($tempMediaRoot, 0755);
        }

        // Copy original to temp media structure
        $tempCatalogPath = $tempMediaRoot . '/catalog/product' . $originalImageName;
        $tempCatalogDir = $this->fileDriver->getParentDirectory($tempCatalogPath);
        if (!$this->fileDriver->isDirectory($tempCatalogDir)) {
            $this->fileDriver->createDirectory($tempCatalogDir, 0755);
        }
        $this->fileDriver->copy($tempOriginal, $tempCatalogPath);

        $resizedPath = $this->generateResizedImagePath($originalImageName, $imageParams);
        $tempResizedPath = $this->tempProcessor->getTempPath($resizedPath);

        // Ensure directory exists
        $tempResizedDir = $this->fileDriver->getParentDirectory($tempResizedPath);
        if (!$this->fileDriver->isDirectory($tempResizedDir)) {
            $this->fileDriver->createDirectory($tempResizedDir, 0755);
        }

        // Perform actual resize in temp
        $this->resizeImage($tempOriginal, $tempResizedPath, $imageParams);

        // Upload resized image to R2
        $this->tempProcessor->uploadToR2($tempResizedPath, $resizedPath);

        // Cleanup temp files
        $this->tempProcessor->cleanup($tempOriginal);
        $this->tempProcessor->cleanup($tempResizedPath);

        return $resizedPath;
    }

    private function generateResizedImagePath(string $originalImageName, array $imageParams): string
    {
        // Generate cache path similar to Magento's logic
        // catalog/product/cache/{width}x{height}/{quality}/{image_type}/path/to/image.jpg
        $width = $imageParams['width'] ?? null;
        $height = $imageParams['height'] ?? null;
        $quality = $imageParams['quality'] ?? 80;
        $imageType = $imageParams['image_type'] ?? 'image';

        $sizePath = ($width ?: 'auto') . 'x' . ($height ?: 'auto');

        return 'catalog/product/cache/' . $sizePath . '/' . $quality . '/' . $imageType . $originalImageName;
    }

    /**
     * Resize image using GD library
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function resizeImage(string $sourcePath, string $destPath, array $params): void
    {
        $width = $params['width'] ?? null;
        $height = $params['height'] ?? null;
        $quality = $params['quality'] ?? 80;

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            throw new \RuntimeException('Invalid image: ' . $sourcePath);
        }

        [$origWidth, $origHeight, $type] = $imageInfo;

        // Load source image
        $source = $this->loadImage($sourcePath, $type);
        if (!$source) {
            throw new \RuntimeException('Failed to load image: ' . $sourcePath);
        }

        // Calculate dimensions
        [$newWidth, $newHeight] = $this->calculateDimensions($origWidth, $origHeight, $width, $height);

        // Create resized image - phpcs:ignore Magento2.Functions.DiscouragedFunction
        $dest = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG/GIF - phpcs:ignore Magento2.Functions.DiscouragedFunction
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF) {
            imagealphablending($dest, false);
            imagesavealpha($dest, true);
            $transparent = imagecolorallocatealpha($dest, 255, 255, 255, 127);
            imagefilledrectangle($dest, 0, 0, $newWidth, $newHeight, $transparent);
        }

        // Resize - phpcs:ignore Magento2.Functions.DiscouragedFunction
        imagecopyresampled($dest, $source, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

        // Save
        $this->saveImage($dest, $destPath, $type, $quality);

        // Cleanup - phpcs:ignore Magento2.Functions.DiscouragedFunction
        imagedestroy($source);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        imagedestroy($dest);
    }

    /**
     * Load image from file
     *
     * @return \GdImage|false
     */
    private function loadImage(string $path, int $type)
    {
        // phpcs:disable Magento2.Functions.DiscouragedFunction
        switch ($type) {
            case IMAGETYPE_JPEG:
                return imagecreatefromjpeg($path);
            case IMAGETYPE_PNG:
                return imagecreatefrompng($path);
            case IMAGETYPE_GIF:
                return imagecreatefromgif($path);
            case IMAGETYPE_WEBP:
                return imagecreatefromwebp($path);
            default:
                return false;
        }
        // phpcs:enable Magento2.Functions.DiscouragedFunction
    }

    /**
     * Save image to file
     *
     * @param \GdImage $image
     */
    private function saveImage($image, string $path, int $type, int $quality): void
    {
        // phpcs:disable Magento2.Functions.DiscouragedFunction
        switch ($type) {
            case IMAGETYPE_JPEG:
                imagejpeg($image, $path, $quality);
                break;
            case IMAGETYPE_PNG:
                $pngQuality = (int)(9 - ($quality / 100) * 9);
                imagepng($image, $path, $pngQuality);
                break;
            case IMAGETYPE_GIF:
                imagegif($image, $path);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($image, $path, $quality);
                break;
        }
        // phpcs:enable Magento2.Functions.DiscouragedFunction
    }

    private function calculateDimensions(
        int $origWidth,
        int $origHeight,
        ?int $targetWidth,
        ?int $targetHeight
    ): array {
        if (!$targetWidth && !$targetHeight) {
            return [$origWidth, $origHeight];
        }

        $aspectRatio = $origWidth / $origHeight;

        if ($targetWidth && $targetHeight) {
            // Both specified - use as-is
            return [$targetWidth, $targetHeight];
        } elseif ($targetWidth) {
            // Only width specified
            return [$targetWidth, (int)($targetWidth / $aspectRatio)];
        } else {
            // Only height specified
            return [(int)($targetHeight * $aspectRatio), $targetHeight];
        }
    }
}
