<?php
namespace MageZero\CloudflareR2\Plugin\Catalog\Helper;

use Magento\Catalog\Helper\Image;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\HTTP\ClientInterface;
use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\FileExistenceCache;
use MageZero\CloudflareR2\Model\ImageProcessor\TemporaryProcessor;
use Psr\Log\LoggerInterface;

/**
 * Plugin to trigger on-demand image generation in read-only mode
 */
class ImagePlugin
{
    private Config $config;
    private FileExistenceCache $fileExistenceCache;
    private TemporaryProcessor $tempProcessor;
    private FileDriver $fileDriver;
    private LoggerInterface $logger;
    private ClientInterface $httpClient;

    public function __construct(
        Config $config,
        FileExistenceCache $fileExistenceCache,
        TemporaryProcessor $tempProcessor,
        FileDriver $fileDriver,
        LoggerInterface $logger,
        ClientInterface $httpClient
    ) {
        $this->config = $config;
        $this->fileExistenceCache = $fileExistenceCache;
        $this->tempProcessor = $tempProcessor;
        $this->fileDriver = $fileDriver;
        $this->logger = $logger;
        $this->httpClient = $httpClient;
    }

    /**
     * After getting image URL, ensure the image exists in R2
     * Generate on-demand if missing
     */
    public function afterGetUrl(Image $subject, string $result): string
    {
        if (!$this->config->isR2Selected()) {
            return $result;
        }

        try {
            // Extract relative path from URL
            $baseMediaUrl = $this->config->getBaseMediaUrl();
            if (empty($baseMediaUrl) || strpos($result, $baseMediaUrl) !== 0) {
                // Not a CDN URL or base media URL not configured
                return $result;
            }

            $relativePath = str_replace($baseMediaUrl . '/', '', $result);

            // Check if image exists in cache
            $exists = $this->fileExistenceCache->get($relativePath);

            if ($exists === true) {
                // Image exists in R2, return URL as-is
                return $result;
            }

            if ($exists === false) {
                // We know it doesn't exist, try to generate it
                $this->generateImage($subject, $relativePath);
            } else {
                // Cache miss - check CDN and cache result
                if ($this->checkImageExistsInCdn($relativePath)) {
                    return $result;
                }

                // Image doesn't exist - generate it
                $this->generateImage($subject, $relativePath);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error(
                'Error in on-demand image generation',
                ['url' => $result, 'error' => $e->getMessage()]
            );
            return $result;
        }
    }

    private function checkImageExistsInCdn(string $relativePath): bool
    {
        $cdnUrl = $this->config->getBaseMediaUrl() . '/' . ltrim($relativePath, '/');

        try {
            $this->httpClient->setOptions(['timeout' => 5]);
            $this->httpClient->head($cdnUrl);
            $statusCode = $this->httpClient->getStatus();

            $exists = $statusCode === 200;
            $this->fileExistenceCache->set($relativePath, $exists);

            return $exists;
        } catch (\Exception $e) {
            $this->logger->error(
                'Error checking CDN for image',
                ['path' => $relativePath, 'error' => $e->getMessage()]
            );
            return false;
        }
    }

    private function generateImage(Image $subject, string $relativePath): void
    {
        try {
            // Extract image info from helper
            $width = $subject->getWidth();
            $height = $subject->getHeight();
            $quality = $subject->getQuality() ?: 80;

            // Determine if this is a cached/resized image
            if (strpos($relativePath, 'catalog/product/cache/') !== 0) {
                // Not a resized image - might be original, skip generation
                return;
            }

            // Extract original image path from cache path
            // catalog/product/cache/800x600/80/image/s/h/shoe.jpg -> s/h/shoe.jpg
            $parts = explode('/', $relativePath);
            if (count($parts) < 6) {
                return;
            }

            // Remove catalog/product/cache/WIDTHxHEIGHT/QUALITY/TYPE
            $originalPath = implode('/', array_slice($parts, 6));

            // Download original from R2
            $fullOriginalPath = 'catalog/product/' . $originalPath;
            $tempOriginal = $this->tempProcessor->downloadToTemp($fullOriginalPath);

            if (!$tempOriginal) {
                $this->logger->debug(
                    'Original image not found for on-demand generation',
                    ['original' => $fullOriginalPath, 'requested' => $relativePath]
                );
                // Cache as non-existent to avoid repeated attempts
                $this->fileExistenceCache->set($relativePath, false);
                return;
            }

            // Create temp path for resized image
            $tempResized = $this->tempProcessor->getTempPath($relativePath);
            $tempDir = $this->fileDriver->getParentDirectory($tempResized);

            if (!$this->fileDriver->isDirectory($tempDir)) {
                $this->fileDriver->createDirectory($tempDir, 0755);
            }

            // Resize image
            $this->resizeImage($tempOriginal, $tempResized, $width, $height, $quality);

            // Upload to R2
            $uploaded = $this->tempProcessor->uploadToR2($tempResized, $relativePath);

            if ($uploaded) {
                $this->logger->info(
                    'Generated image on-demand',
                    ['path' => $relativePath, 'size' => $width . 'x' . $height]
                );
            }

            // Cleanup
            $this->tempProcessor->cleanup($tempOriginal);
            $this->tempProcessor->cleanup($tempResized);
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to generate image on-demand',
                ['path' => $relativePath, 'error' => $e->getMessage()]
            );
        }
    }

    /**
     * Resize image using GD library
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function resizeImage(
        string $sourcePath,
        string $destPath,
        ?int $width,
        ?int $height,
        int $quality
    ): void {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            throw new \RuntimeException('Invalid image: ' . $sourcePath);
        }

        [$origWidth, $origHeight, $type] = $imageInfo;

        // Load source - phpcs:ignore Magento2.Functions.DiscouragedFunction
        $source = $this->loadImage($sourcePath, $type);
        if (!$source) {
            throw new \RuntimeException('Unsupported image type');
        }

        // Calculate dimensions
        [$newWidth, $newHeight] = $this->calculateDimensions($origWidth, $origHeight, $width, $height);

        // Create dest - phpcs:ignore Magento2.Functions.DiscouragedFunction
        $dest = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency - phpcs:ignore Magento2.Functions.DiscouragedFunction
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

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
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
            return [$targetWidth, $targetHeight];
        } elseif ($targetWidth) {
            return [$targetWidth, (int)($targetWidth / $aspectRatio)];
        } else {
            return [(int)($targetHeight * $aspectRatio), $targetHeight];
        }
    }
}
