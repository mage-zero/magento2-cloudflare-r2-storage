<?php
namespace MageZero\CloudflareR2\Model\MediaStorage\File\Storage;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Filesystem\Io\File as IoFile;
use Magento\MediaStorage\Helper\File\Media as MediaHelper;
use Magento\MediaStorage\Helper\File\Storage\Database as StorageHelper;
use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\KeyFormatter;
use MageZero\CloudflareR2\Model\R2ClientFactory;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class R2 extends DataObject
{
    private ?string $mediaBaseDirectory = null;
    private Config $config;
    private MediaHelper $mediaHelper;
    private StorageHelper $storageHelper;
    private LoggerInterface $logger;
    private S3Client $client;
    private KeyFormatter $keyFormatter;
    private FileDriver $driver;
    private IoFile $ioFile;
    private array $errors = [];
    private ?array $storageData = null;

    public function __construct(
        Config $config,
        MediaHelper $mediaHelper,
        StorageHelper $storageHelper,
        LoggerInterface $logger,
        R2ClientFactory $clientFactory,
        FileDriver $driver,
        ?IoFile $ioFile = null
    ) {
        parent::__construct();
        $this->config = $config;
        $this->mediaHelper = $mediaHelper;
        $this->storageHelper = $storageHelper;
        $this->logger = $logger;
        $this->client = $clientFactory->create();
        $this->keyFormatter = new KeyFormatter($this->config->getKeyPrefix());
        $this->driver = $driver;
        $this->ioFile = $ioFile ?? new IoFile();
    }

    public function init(): self
    {
        return $this;
    }

    public function getStorageName(): \Magento\Framework\Phrase
    {
        return __('Cloudflare R2 (S3 Compatible)');
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function clear(): self
    {
        $this->logger->info('R2 Storage: clear() called');
        $keys = $this->listAllKeys();
        $this->logger->info('R2 Storage: clear() found ' . count($keys) . ' keys to delete');
        $this->deleteKeys($keys);
        $this->logger->info('R2 Storage: clear() completed');
        return $this;
    }

    public function getConnectionName(): string
    {
        // Must return a non-empty string for the admin JS validator to work.
        // The JS getConnectionName() returns empty string for storage types > 0
        // with no connection, which prevents the storage from being added to
        // allowedStorages and blocks saving.
        return 'r2';
    }

    public function getStorageData(): array
    {
        if ($this->storageData !== null) {
            return $this->storageData;
        }

        $files = [];
        $directories = [];
        foreach ($this->listAllKeys() as $key) {
            if ($key === '' || substr($key, -1) === '/') {
                continue;
            }

            $files[] = $key;
            $dirName = $this->driver->getParentDirectory($key);
            if ($dirName !== '.' && $dirName !== '') {
                $segments = explode('/', $dirName);
                $path = '';
                foreach ($segments as $segment) {
                    $path = $path === '' ? $segment : $path . '/' . $segment;
                    $directories[$path] = ['name' => $path];
                }
            }
        }

        $this->storageData = [
            'files' => $files,
            'directories' => array_values($directories),
        ];

        return $this->storageData;
    }

    public function exportDirectories($offset = 0, $count = 100)
    {
        $directories = $this->getStorageData()['directories'] ?? [];
        $slice = array_slice($directories, (int)$offset, (int)$count);
        return $slice ?: false;
    }

    public function exportFiles($offset = 0, $count = 100)
    {
        $files = $this->getStorageData()['files'] ?? [];
        $slice = array_slice($files, (int)$offset, (int)$count);
        if (!$slice) {
            return false;
        }

        $result = [];
        foreach ($slice as $filePath) {
            try {
                $object = $this->client->getObject([
                    'Bucket' => $this->getBucket(),
                    'Key' => $this->keyFormatter->toKey($filePath),
                ]);
                if (isset($object['Body'])) {
                    $directory = $this->driver->getParentDirectory($filePath);
                    $result[] = [
                        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- safe: input is R2 API key, not user data
                        'filename' => basename($filePath),
                        'directory' => $directory === '.' ? null : $directory,
                        'content' => (string)$object['Body'],
                    ];
                }
            } catch (AwsException $exception) {
                $this->errors[] = $exception->getMessage();
                $this->logger->critical($exception->getMessage());
            }
        }

        return $result;
    }

    public function importDirectories(array $dirs = [])
    {
        $this->logger->info('R2 Storage: importDirectories() called with ' . count($dirs) . ' directories');
        return $this;
    }

    public function importFiles(array $files = [])
    {
        $this->logger->info('R2 Storage: importFiles() called with ' . count($files) . ' files');
        foreach ($files as $file) {
            try {
                $path = $this->buildPathFromFile($file);
                $this->logger->info('R2 Storage: uploading file ' . $path);
                $this->client->putObject($this->buildPutObjectParams($path, $file['content'] ?? ''));
            } catch (AwsException $exception) {
                $this->errors[] = $exception->getMessage();
                $this->logger->critical($exception->getMessage());
            }
        }
        $this->logger->info('R2 Storage: importFiles() completed');

        return $this;
    }

    public function loadByFilename($filename): self
    {
        $relative = $this->storageHelper->getMediaRelativePath($filename);
        $key = $this->keyFormatter->toKey($relative);

        try {
            $object = $this->client->getObject([
                'Bucket' => $this->getBucket(),
                'Key' => $key,
            ]);

            if (isset($object['Body'])) {
                $this->setData('id', $relative);
                $this->setData('filename', $relative);
                $this->setData('content', (string)$object['Body']);
            } else {
                $this->unsetData();
            }
        } catch (AwsException $exception) {
            $this->unsetData();
            $this->logger->critical($exception->getMessage());
        }

        return $this;
    }

    public function saveFile($file, $overwrite = true)
    {
        if (is_string($file)) {
            $file = $this->mediaHelper->collectFileInfo($this->getMediaBaseDirectory(), $file);
        }

        if (!is_array($file) || empty($file['filename']) || !array_key_exists('content', $file)) {
            throw new LocalizedException(__('Wrong file info format'));
        }

        $path = $this->buildPathFromFile($file);

        if (!$overwrite && $this->fileExists($path)) {
            return false;
        }

        try {
            $this->client->putObject($this->buildPutObjectParams($path, $file['content']));
        } catch (AwsException $exception) {
            $this->logger->critical($exception->getMessage());
            throw new LocalizedException(__('Unable to save file "%1"', $path));
        }

        return true;
    }

    public function fileExists($filePath): bool
    {
        $key = $this->keyFormatter->toKey($filePath);

        try {
            $this->client->headObject([
                'Bucket' => $this->getBucket(),
                'Key' => $key,
            ]);
            return true;
        } catch (AwsException $exception) {
            return false;
        }
    }

    public function copyFile($oldFilePath, $newFilePath): bool
    {
        $sourceKey = $this->keyFormatter->toKey($oldFilePath);
        $targetKey = $this->keyFormatter->toKey($newFilePath);

        try {
            $this->client->copyObject([
                'Bucket' => $this->getBucket(),
                'CopySource' => $this->getBucket() . '/' . $sourceKey,
                'Key' => $targetKey,
            ]);
            return true;
        } catch (AwsException $exception) {
            $this->logger->critical($exception->getMessage());
            return false;
        }
    }

    public function renameFile($oldFilePath, $newFilePath): bool
    {
        $copied = $this->copyFile($oldFilePath, $newFilePath);
        if (!$copied) {
            return false;
        }

        return $this->deleteFile($oldFilePath);
    }

    public function deleteFile($path): bool
    {
        $key = $this->keyFormatter->toKey($path);

        try {
            $this->client->deleteObject([
                'Bucket' => $this->getBucket(),
                'Key' => $key,
            ]);
            return true;
        } catch (AwsException $exception) {
            $this->logger->critical($exception->getMessage());
            return false;
        }
    }

    public function getSubdirectories($path): array
    {
        $prefix = $this->buildKeyPrefix($this->storageHelper->getMediaRelativePath($path));
        $objects = $this->client->listObjectsV2($this->buildListParams($prefix, '/'));

        $directories = [];
        if (!empty($objects['CommonPrefixes'])) {
            foreach ($objects['CommonPrefixes'] as $object) {
                if (!isset($object['Prefix'])) {
                    continue;
                }

                $dir = rtrim($this->keyFormatter->fromKey($object['Prefix']), '/');
                if ($dir !== '') {
                    $directories[] = ['name' => $dir];
                }
            }
        }

        return $directories;
    }

    public function getDirectoryFiles($path): array
    {
        $prefix = $this->buildKeyPrefix($this->storageHelper->getMediaRelativePath($path));
        $objects = $this->client->listObjectsV2($this->buildListParams($prefix, '/'));

        $files = [];
        if (!empty($objects['Contents'])) {
            foreach ($objects['Contents'] as $object) {
                if (!isset($object['Key']) || $object['Key'] === $prefix) {
                    continue;
                }
                try {
                    $content = $this->client->getObject([
                        'Bucket' => $this->getBucket(),
                        'Key' => $object['Key'],
                    ]);
                    if (isset($content['Body'])) {
                        $relative = $this->keyFormatter->fromKey($object['Key']);
                        $directory = $this->driver->getParentDirectory($relative);
                        $files[] = [
                            // phpcs:ignore Magento2.Functions.DiscouragedFunction -- safe: input is R2 API key, not user data
                            'filename' => basename($relative),
                            'directory' => $directory === '.' ? null : $directory,
                            'content' => (string)$content['Body'],
                        ];
                    }
                } catch (AwsException $exception) {
                    $this->logger->critical($exception->getMessage());
                }
            }
        }

        return $files;
    }

    public function deleteDirectory($path): self
    {
        $prefix = $this->buildKeyPrefix($this->storageHelper->getMediaRelativePath($path));
        if ($prefix === '') {
            return $this;
        }

        $keys = $this->listKeysByPrefix($prefix);
        $this->deleteKeys($keys);

        return $this;
    }

    public function getMediaBaseDirectory(): string
    {
        if ($this->mediaBaseDirectory === null) {
            $this->mediaBaseDirectory = $this->storageHelper->getMediaBaseDir();
        }

        return $this->mediaBaseDirectory;
    }

    private function getBucket(): string
    {
        return $this->config->getBucket();
    }

    private function buildPathFromFile(array $file): string
    {
        $directory = trim((string)($file['directory'] ?? ''), '/');
        $filename = ltrim((string)$file['filename'], '/');

        if ($directory === '') {
            return $filename;
        }

        return $directory . '/' . $filename;
    }

    private function buildPutObjectParams(string $path, string $content): array
    {
        $params = [
            'Bucket' => $this->getBucket(),
            'Key' => $this->keyFormatter->toKey($path),
            'Body' => $content,
        ];

        $mime = $this->detectMimeType($path, $content);
        if ($mime !== null) {
            $params['ContentType'] = $mime;
            if ($this->isInlineContentType($mime)) {
                $params['ContentDisposition'] = 'inline';
            }
        }

        return $params;
    }

    private function isInlineContentType(string $mime): bool
    {
        $inlineTypes = [
            'image/',
            'text/plain',
            'text/css',
            'text/html',
            'application/javascript',
            'application/json',
            'application/xml',
            'application/pdf',
            'video/',
            'audio/',
        ];

        foreach ($inlineTypes as $type) {
            if (str_starts_with($mime, $type)) {
                return true;
            }
        }

        return false;
    }

    private function detectMimeType(string $path, string $content): ?string
    {
        // Try to detect from content using finfo
        if ($content !== '') {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($content);
            if ($mime !== false && $mime !== 'application/octet-stream') {
                return $mime;
            }
        }

        // Fall back to extension-based detection
        $pathInfo = $this->ioFile->getPathInfo($path);
        $extension = strtolower($pathInfo['extension'] ?? '');
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp',
            'ico' => 'image/vnd.microsoft.icon',
            'tiff' => 'image/tiff',
            'tif' => 'image/tiff',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'html' => 'text/html',
            'htm' => 'text/html',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg',
            'zip' => 'application/zip',
        ];

        return $mimeTypes[$extension] ?? null;
    }

    private function buildKeyPrefix(string $path): string
    {
        $path = trim($path, '/');
        $key = $this->keyFormatter->toKey($path);
        if ($key === '') {
            return '';
        }

        return rtrim($key, '/') . '/';
    }

    private function buildListParams(string $prefix, string $delimiter = ''): array
    {
        $params = [
            'Bucket' => $this->getBucket(),
            'MaxKeys' => 1000,
        ];

        if ($prefix !== '') {
            $params['Prefix'] = $prefix;
        }

        if ($delimiter !== '') {
            $params['Delimiter'] = $delimiter;
        }

        return $params;
    }

    private function listAllKeys(): array
    {
        return $this->listKeysByPrefix($this->buildKeyPrefix(''));
    }

    private function listKeysByPrefix(string $prefix): array
    {
        $params = $this->buildListParams($prefix);
        $keys = [];
        do {
            $result = $this->client->listObjectsV2($params);
            if (!empty($result['Contents'])) {
                foreach ($result['Contents'] as $object) {
                    if (isset($object['Key'])) {
                        $keys[] = $this->keyFormatter->fromKey($object['Key']);
                    }
                }
            }

            $params['ContinuationToken'] = $result['NextContinuationToken'] ?? null;
        } while (!empty($result['IsTruncated']));

        return $keys;
    }

    private function deleteKeys(array $keys): void
    {
        $keys = array_values(array_filter($keys));
        if (!$keys) {
            return;
        }

        $chunks = array_chunk($keys, 1000);
        foreach ($chunks as $chunk) {
            $objects = array_map(function ($key) {
                return ['Key' => $this->keyFormatter->toKey($key)];
            }, $chunk);

            $this->client->deleteObjects([
                'Bucket' => $this->getBucket(),
                'Delete' => [
                    'Objects' => $objects,
                    'Quiet' => true,
                ],
            ]);
        }
    }
}
