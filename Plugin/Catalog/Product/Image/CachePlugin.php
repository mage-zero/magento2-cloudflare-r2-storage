<?php
namespace MageZero\CloudflareR2\Plugin\Catalog\Product\Image;

use Magento\Catalog\Model\Product\Image\Cache;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\ImageProcessor\TemporaryProcessor;
use Psr\Log\LoggerInterface;

/**
 * Plugin to handle image cache generation in /tmp
 */
class CachePlugin
{
    private Config $config;
    private TemporaryProcessor $tempProcessor;
    private FileDriver $fileDriver;
    private LoggerInterface $logger;
    private Filesystem\Directory\WriteInterface $mediaDirectory;

    public function __construct(
        Config $config,
        TemporaryProcessor $tempProcessor,
        FileDriver $fileDriver,
        LoggerInterface $logger,
        Filesystem $filesystem
    ) {
        $this->config = $config;
        $this->tempProcessor = $tempProcessor;
        $this->fileDriver = $fileDriver;
        $this->logger = $logger;
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
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
        $tempCatalogDir = dirname($tempCatalogPath);
        if (!$this->fileDriver->isDirectory($tempCatalogDir)) {
            $this->fileDriver->createDirectory($tempCatalogDir, 0755);
        }
        $this->fileDriver->copy($tempOriginal, $tempCatalogPath);

        // Temporarily point media directory to our temp location
        $originalMediaPath = $this->mediaDirectory->getAbsolutePath();

        // Call original resize logic (it will write to pub/media)
        // Since we can't redirect filesystem writes easily, we need a different approach
        // Let's generate the resized path and do the resize ourselves

        $resizedPath = $this->generateResizedImagePath($originalImageName, $imageParams);
        $tempResizedPath = $this->tempProcessor->getTempPath($resizedPath);

        // Ensure directory exists
        $tempResizedDir = dirname($tempResizedPath);
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

    private function resizeImage(string $sourcePath, string $destPath, array $params): void
    {
        $width = $params['width'] ?? null;
        $height = $params['height'] ?? null;
        $quality = $params['quality'] ?? 80;

        // Get image info
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

        // Create resized image
        $dest = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG/GIF
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF) {
            imagealphablending($dest, false);
            imagesavealpha($dest, true);
            $transparent = imagecolorallocatealpha($dest, 255, 255, 255, 127);
            imagefilledrectangle($dest, 0, 0, $newWidth, $newHeight, $transparent);
        }

        // Resize
        imagecopyresampled($dest, $source, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

        // Save
        $this->saveImage($dest, $destPath, $type, $quality);

        // Cleanup
        imagedestroy($source);
        imagedestroy($dest);
    }

    private function loadImage(string $path, int $type)
    {
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
    }

    private function saveImage($image, string $path, int $type, int $quality): void
    {
        switch ($type) {
            case IMAGETYPE_JPEG:
                imagejpeg($image, $path, $quality);
                break;
            case IMAGETYPE_PNG:
                // PNG quality is 0-9, convert from 0-100
                $pngQuality = (int) (9 - ($quality / 100) * 9);
                imagepng($image, $path, $pngQuality);
                break;
            case IMAGETYPE_GIF:
                imagegif($image, $path);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($image, $path, $quality);
                break;
        }
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
