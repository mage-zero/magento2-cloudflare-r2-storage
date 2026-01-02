<?php
namespace MageZero\CloudflareR2\Controller\Media;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\UrlInterface;
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
    private RedirectFactory $redirectFactory;
    private Config $config;
    private TemporaryProcessor $tempProcessor;
    private FileExistenceCache $fileExistenceCache;
    private UrlInterface $url;
    private LoggerInterface $logger;
    private Http $response;

    public function __construct(
        RequestInterface $request,
        RedirectFactory $redirectFactory,
        Config $config,
        TemporaryProcessor $tempProcessor,
        FileExistenceCache $fileExistenceCache,
        UrlInterface $url,
        LoggerInterface $logger,
        Http $response
    ) {
        $this->request = $request;
        $this->redirectFactory = $redirectFactory;
        $this->config = $config;
        $this->tempProcessor = $tempProcessor;
        $this->fileExistenceCache = $fileExistenceCache;
        $this->url = $url;
        $this->logger = $logger;
        $this->response = $response;
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
        $tempDir = dirname($tempResized);

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
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

    private function resizeImage(
        string $sourcePath,
        string $destPath,
        ?int $width,
        ?int $height,
        int $quality
    ): void {
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            throw new \RuntimeException('Invalid image: ' . $sourcePath);
        }

        [$origWidth, $origHeight, $type] = $imageInfo;

        // Load source
        $source = match($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
            IMAGETYPE_GIF => imagecreatefromgif($sourcePath),
            IMAGETYPE_WEBP => imagecreatefromwebp($sourcePath),
            default => throw new \RuntimeException('Unsupported image type')
        };

        // Calculate dimensions
        [$newWidth, $newHeight] = $this->calculateDimensions($origWidth, $origHeight, $width, $height);

        // Create dest
        $dest = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF) {
            imagealphablending($dest, false);
            imagesavealpha($dest, true);
            $transparent = imagecolorallocatealpha($dest, 255, 255, 255, 127);
            imagefilledrectangle($dest, 0, 0, $newWidth, $newHeight, $transparent);
        }

        // Resize
        imagecopyresampled($dest, $source, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

        // Save
        match($type) {
            IMAGETYPE_JPEG => imagejpeg($dest, $destPath, $quality),
            IMAGETYPE_PNG => imagepng($dest, $destPath, (int)(9 - ($quality / 100) * 9)),
            IMAGETYPE_GIF => imagegif($dest, $destPath),
            IMAGETYPE_WEBP => imagewebp($dest, $destPath, $quality)
        };

        imagedestroy($source);
        imagedestroy($dest);
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
