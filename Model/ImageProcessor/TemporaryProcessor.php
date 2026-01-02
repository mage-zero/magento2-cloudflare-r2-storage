<?php
namespace MageZero\CloudflareR2\Model\ImageProcessor;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\FileExistenceCache;
use MageZero\CloudflareR2\Model\MediaStorage\File\Storage\R2Factory;
use Psr\Log\LoggerInterface;

class TemporaryProcessor
{
    private Config $config;
    private R2Factory $r2Factory;
    private FileDriver $fileDriver;
    private LoggerInterface $logger;
    private FileExistenceCache $fileExistenceCache;
    private string $tmpDir;

    public function __construct(
        Config $config,
        R2Factory $r2Factory,
        FileDriver $fileDriver,
        LoggerInterface $logger,
        FileExistenceCache $fileExistenceCache,
        Filesystem $filesystem
    ) {
        $this->config = $config;
        $this->r2Factory = $r2Factory;
        $this->fileDriver = $fileDriver;
        $this->logger = $logger;
        $this->fileExistenceCache = $fileExistenceCache;

        // Use Magento's tmp directory
        $tmpDirectory = $filesystem->getDirectoryWrite(DirectoryList::TMP);
        $this->tmpDir = $tmpDirectory->getAbsolutePath('magezero_r2');

        // Ensure tmp directory exists
        if (!$this->fileDriver->isDirectory($this->tmpDir)) {
            $this->fileDriver->createDirectory($this->tmpDir, 0755);
        }
    }

    /**
     * Download file from R2 to /tmp
     *
     * @param string $relativePath Path relative to media root
     * @return string|null Absolute path to temp file, or null on failure
     */
    public function downloadToTemp(string $relativePath): ?string
    {
        try {
            $r2 = $this->r2Factory->create();
            $r2->loadByFilename($relativePath);

            if (!$r2->getId()) {
                $this->logger->debug('File not found in R2', ['path' => $relativePath]);
                return null;
            }

            $tempPath = $this->getTempPath($relativePath);
            $tempDir = dirname($tempPath);

            if (!$this->fileDriver->isDirectory($tempDir)) {
                $this->fileDriver->createDirectory($tempDir, 0755);
            }

            $this->fileDriver->filePutContents($tempPath, $r2->getContent());

            return $tempPath;
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to download file from R2 to temp',
                ['path' => $relativePath, 'error' => $e->getMessage()]
            );
            return null;
        }
    }

    /**
     * Upload file from /tmp to R2
     *
     * @param string $tempPath Absolute path to temp file
     * @param string $relativePath Destination path in R2 (relative to media root)
     * @return bool
     */
    public function uploadToR2(string $tempPath, string $relativePath): bool
    {
        try {
            if (!$this->fileDriver->isFile($tempPath)) {
                $this->logger->error('Temp file not found', ['path' => $tempPath]);
                return false;
            }

            $content = $this->fileDriver->fileGetContents($tempPath);
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $filename = basename($relativePath);

            $r2 = $this->r2Factory->create();
            $r2->saveFile([
                'filename' => $filename,
                'content' => $content,
                'directory' => dirname($relativePath)
            ]);

            // Invalidate cache for this file
            $this->fileExistenceCache->set($relativePath, true);

            return true;
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to upload file from temp to R2',
                ['temp_path' => $tempPath, 'r2_path' => $relativePath, 'error' => $e->getMessage()]
            );
            return false;
        }
    }

    /**
     * Clean up temp file
     *
     * @param string $tempPath Absolute path to temp file
     * @return void
     */
    public function cleanup(string $tempPath): void
    {
        try {
            if ($this->fileDriver->isFile($tempPath)) {
                $this->fileDriver->deleteFile($tempPath);
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to cleanup temp file',
                ['path' => $tempPath, 'error' => $e->getMessage()]
            );
        }
    }

    /**
     * Get temporary file path for a given media file
     *
     * @param string $relativePath
     * @return string
     */
    public function getTempPath(string $relativePath): string
    {
        // Create unique temp path that preserves directory structure
        return $this->tmpDir . '/' . ltrim($relativePath, '/');
    }

    /**
     * Get temp directory path
     *
     * @return string
     */
    public function getTempDir(): string
    {
        return $this->tmpDir;
    }

    /**
     * Clean up old temp files (older than 1 hour)
     *
     * @return void
     */
    public function cleanupOldFiles(): void
    {
        try {
            $cutoffTime = time() - 3600; // 1 hour ago
            $this->cleanupDirectory($this->tmpDir, $cutoffTime);
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to cleanup old temp files',
                ['error' => $e->getMessage()]
            );
        }
    }

    private function cleanupDirectory(string $dir, int $cutoffTime): void
    {
        if (!$this->fileDriver->isDirectory($dir)) {
            return;
        }

        $items = $this->fileDriver->readDirectory($dir);
        foreach ($items as $item) {
            if ($this->fileDriver->isDirectory($item)) {
                $this->cleanupDirectory($item, $cutoffTime);
                // Remove empty directories
                if (count($this->fileDriver->readDirectory($item)) === 0) {
                    $this->fileDriver->deleteDirectory($item);
                }
            } elseif ($this->fileDriver->isFile($item)) {
                $stat = $this->fileDriver->stat($item);
                if (isset($stat['mtime']) && $stat['mtime'] < $cutoffTime) {
                    $this->fileDriver->deleteFile($item);
                }
            }
        }
    }
}
