<?php
declare(strict_types=1);

namespace MageZero\CloudflareR2\Model;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;

class ConnectionValidator
{
    public const XML_PATH_CONNECTION_VALID = 'magezero_r2/general/connection_valid';
    private const OBSCURED_VALUE = '******';

    private Config $config;
    private ScopeConfigInterface $scopeConfig;
    private WriterInterface $configWriter;
    private TypeListInterface $cacheTypeList;

    public function __construct(
        Config $config,
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList
    ) {
        $this->config = $config;
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
    }

    /**
     * Test connection with optionally provided credentials (from unsaved form)
     */
    public function testConnection(
        ?string $accountId = null,
        ?string $endpoint = null,
        ?string $region = null,
        ?string $bucket = null,
        ?string $accessKey = null,
        ?string $secretKey = null,
        ?bool $pathStyle = null
    ): array {
        // Use provided values or fall back to saved config
        $accountId = $this->resolveValue($accountId, $this->config->getAccountId());
        $endpoint = $this->resolveValue($endpoint, $this->config->getEndpoint());
        $region = $this->resolveValue($region, $this->config->getRegion());
        $bucket = $this->resolveValue($bucket, $this->config->getBucket());
        $accessKey = $this->resolveValue($accessKey, $this->config->getAccessKey());
        $secretKey = $this->resolveSecretValue($secretKey, $this->config->getSecretKey());
        $pathStyle = $pathStyle ?? $this->config->usePathStyle();

        // Build endpoint from account ID if not provided
        if (empty($endpoint) && !empty($accountId)) {
            $endpoint = sprintf('https://%s.r2.cloudflarestorage.com', $accountId);
        }

        // Validate required fields
        $missingFields = [];
        if (empty($endpoint)) {
            $missingFields[] = 'Endpoint (or Account ID)';
        }
        if (empty($bucket)) {
            $missingFields[] = 'Bucket';
        }
        if (empty($accessKey)) {
            $missingFields[] = 'Access Key ID';
        }
        if (empty($secretKey)) {
            $missingFields[] = 'Secret Access Key';
        }

        if (!empty($missingFields)) {
            return [
                'success' => false,
                'message' => 'Missing required fields: ' . implode(', ', $missingFields),
            ];
        }

        try {
            $client = $this->createClient($endpoint, $region, $accessKey, $secretKey, $pathStyle);

            // Test by listing objects in the bucket (limited to 1)
            $client->listObjectsV2([
                'Bucket' => $bucket,
                'MaxKeys' => 1,
            ]);

            $this->saveConnectionStatus(true);

            return [
                'success' => true,
                'message' => 'Connection successful! Bucket "' . $bucket . '" is accessible.',
            ];
        } catch (AwsException $e) {
            $this->saveConnectionStatus(false);
            $errorCode = $e->getAwsErrorCode() ?? 'Unknown';
            $errorMessage = $e->getAwsErrorMessage() ?? $e->getMessage();

            // Debug info for signature issues
            $debug = sprintf(
                ' [Debug: endpoint=%s, region=%s, accessKey=%s, secretKeyLen=%d, pathStyle=%s]',
                $endpoint,
                $region,
                substr($accessKey, 0, 4) . '...',
                strlen($secretKey),
                $pathStyle ? 'true' : 'false'
            );

            return [
                'success' => false,
                'message' => sprintf('Connection failed (%s): %s', $errorCode, $errorMessage) . $debug,
            ];
        } catch (\Exception $e) {
            $this->saveConnectionStatus(false);
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check if a valid connection has been established
     */
    public function isConnectionValid(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_PATH_CONNECTION_VALID);
    }

    /**
     * Save connection validity status
     */
    private function saveConnectionStatus(bool $isValid): void
    {
        $this->configWriter->save(self::XML_PATH_CONNECTION_VALID, $isValid ? '1' : '0');
        $this->cacheTypeList->cleanType('config');
    }

    private function resolveValue(?string $provided, string $saved): string
    {
        if ($provided === null || $provided === '') {
            return $saved;
        }
        return trim($provided);
    }

    private function resolveSecretValue(?string $provided, string $saved): string
    {
        if ($provided === null || $provided === '') {
            return $saved;
        }
        $trimmed = trim($provided);
        // Check if value is obscured (all asterisks) - use saved value instead
        if ($trimmed === '' || preg_match('/^\*+$/', $trimmed)) {
            return $saved;
        }
        return $trimmed;
    }

    private function createClient(
        string $endpoint,
        string $region,
        string $accessKey,
        string $secretKey,
        bool $pathStyle
    ): S3Client {
        return new S3Client([
            'version' => 'latest',
            'region' => $region ?: 'auto',
            'endpoint' => $endpoint,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
            'use_path_style_endpoint' => $pathStyle,
            'signature_version' => 'v4',
        ]);
    }
}
