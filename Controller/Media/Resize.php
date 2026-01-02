<?php
namespace MageZero\CloudflareR2\Controller\Media;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\FileExistenceCache;
use MageZero\CloudflareR2\Model\ImageProcessor\TemporaryProcessor;
use Psr\Log\LoggerInterface;

/**
 * On-demand image resize handler
 *
 * Handles requests for missing image sizes by:
 * 1. Checking if the requested size exists in R2
 * 2. If not, downloading original from R2
 * 3. Resizing in /tmp
 * 4. Uploading to R2
 * 5. Redirecting to CDN URL
 */
class Resize implements HttpGetActionInterface
{
    private RequestInterface $request;
    private Config $config;
    private TemporaryProcessor $tempProcessor;
    private FileExistenceCache $fileExistenceCache;
    private LoggerInterface $logger;
    private Http $response;
    private FileDriver $fileDriver;

    public function __construct(
        RequestInterface $request,
        Config $config,
        TemporaryProcessor $tempProcessor,
        FileExistenceCache $fileExistenceCache,
        LoggerInterface $logger,
        Http $response,
        FileDriver $fileDriver
    ) {
        $this->request = $request;
        $this->config = $config;
        $this->tempProcessor = $tempProcessor;
        $this->fileExistenceCache = $fileExistenceCache;
        $this->logger = $logger;
        $this->response = $response;
        $this->fileDriver = $fileDriver;
    }

    public function execute()
    {
        if (!$this->config->isR2Selected()) {
            return $this->response->setStatusCode(404);
        }

        $imagePath = $this->request->getParam('image');
        $width = $this->request->getParam('width');
        $height = $this->request->getParam('height');
        $quality = $this->request->getParam('quality', 80);

        if (!$imagePath) {
            $this->logger->error('On-demand resize: No image path provided');
            return $this->response->setStatusCode(400);
        }

        // Validate and sanitize input
        $imagePath = ltrim($imagePath, '/');
        $width = $width ? (int)$width : null;
        $height = $height ? (int)$height : null;
        $quality = max(1, min(100, (int)$quality));

        // Generate resized image path
        $resizedPath = $this->generateResizedPath($imagePath, $width, $height, $quality);

        // Check if resized version already exists
        if ($this->fileExistenceCache->get($resizedPath) === true) {
            return $this->redirectToCdn($resizedPath);
        }

        try {
            // Generate the resized image
            $success = $this->generateResizedImage($imagePath, $resizedPath, $width, $height, $quality);

            if ($success) {
                $this->fileExistenceCache->set($resizedPath, true);
                return $this->redirectToCdn($resizedPath);
            }

            $this->logger->error('Failed to generate resized image', [
                'original' => $imagePath,
                'resized' => $resizedPath
            ]);
            return $this->response->setStatusCode(500);
        } catch (\Exception $e) {
            $this->logger->error('On-demand resize error', [
                'image' => $imagePath,
                'error' => $e->getMessage()
            ]);
            return $this->response->setStatusCode(500);
        }
    }

    private function generateResizedPath(string $imagePath, ?int $width, ?int $height, int $quality): string
    {
        $sizePath = ($width ?: 'auto') . 'x' . ($height ?: 'auto');
        return 'catalog/product/cache/' . $sizePath . '/' . $quality . '/image' . '/' . $imagePath;
    }

    private function generateResizedImage(
        string $originalPath,
        string $resizedPath,
        ?int $width,
        ?int $height,
        int $quality
    ): bool {
        // Download original from R2 to /tmp
        $fullOriginalPath = 'catalog/product/' . $originalPath;
        $tempOriginal = $this->tempProcessor->downloadToTemp($fullOriginalPath);

        if (!$tempOriginal) {
            $this->logger->error('Original image not found in R2', ['path' => $fullOriginalPath]);
            return false;
        }

        // Create temp path for resized image
        $tempResized = $this->tempProcessor->getTempPath($resizedPath);
        $tempDir = $this->fileDriver->getParentDirectory($tempResized);

        if (!$this->fileDriver->isDirectory($tempDir)) {
            $this->fileDriver->createDirectory($tempDir, 0755);
        }

        // Perform resize
        $this->resizeImage($tempOriginal, $tempResized, $width, $height, $quality);

        // Upload to R2
        $uploaded = $this->tempProcessor->uploadToR2($tempResized, $resizedPath);

        // Cleanup
        $this->tempProcessor->cleanup($tempOriginal);
        $this->tempProcessor->cleanup($tempResized);

        return $uploaded;
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

        // Load source
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

    private function calculateDimensions(int $origWidth, int $origHeight, ?int $targetWidth, ?int $targetHeight): array
    {
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

    private function redirectToCdn(string $imagePath): Http
    {
        $cdnUrl = $this->config->getBaseMediaUrl() . '/' . ltrim($imagePath, '/');
        $this->response->setRedirect($cdnUrl, 302);
        return $this->response;
    }
}
