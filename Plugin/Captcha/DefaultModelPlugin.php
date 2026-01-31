<?php
namespace MageZero\CloudflareR2\Plugin\Captcha;

use MageZero\CloudflareR2\Model\Config;
use Magento\Captcha\Model\DefaultModel;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\DriverPool;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Psr\Log\LoggerInterface;

/**
 * Uploads generated CAPTCHA images to R2 when R2 is selected as the media storage.
 *
 * Magento's CAPTCHA helper explicitly stores images locally, but when the base media URL
 * is configured to point at an external bucket/CDN (e.g. R2), the generated images must
 * also exist there or the CAPTCHA will 404.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class DefaultModelPlugin
{
    private Config $config;
    private Database $database;
    private Filesystem\Directory\WriteInterface $mediaDirectory;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        Database $database,
        Filesystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->database = $database;
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA, DriverPool::FILE);
        $this->logger = $logger;
    }

    public function afterGenerate(DefaultModel $subject, $result)
    {
        if (!$this->config->isR2Selected()) {
            return $result;
        }

        $imgDir = (string)$subject->getImgDir();
        $id = (string)$subject->getId();
        $suffix = (string)$subject->getSuffix();

        if ($imgDir === '' || $id === '' || $suffix === '') {
            return $result;
        }

        $absolutePath = rtrim($imgDir, '/') . '/' . $id . $suffix;
        $relativePath = ltrim($this->mediaDirectory->getRelativePath($absolutePath), '/');
        if ($relativePath === '' || !$this->mediaDirectory->isFile($relativePath)) {
            return $result;
        }

        try {
            $this->database->getStorageDatabaseModel()->saveFile($relativePath);
        } catch (LocalizedException $exception) {
            $this->logger->warning('R2 Storage: Unable to upload CAPTCHA image to R2: ' . $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->warning('R2 Storage: Unexpected error uploading CAPTCHA image to R2: ' . $exception->getMessage());
        }

        return $result;
    }
}

