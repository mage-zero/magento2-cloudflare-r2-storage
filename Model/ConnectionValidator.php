<?php
declare(strict_types=1);

namespace MageZero\CloudflareR2\Model;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ConnectionValidator
{
    public const XML_PATH_CONNECTION_VALID = 'magezero_r2/general/connection_valid';

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
        $credentials = $this->resolveCredentials(
            $accountId,
            $endpoint,
            $region,
            $bucket,
            $accessKey,
            $secretKey,
            $pathStyle
        );

        $validationError = $this->validateRequiredFields($credentials);
        if ($validationError !== null) {
            return $validationError;
        }

        return $this->attemptConnection($credentials);
    }

    /**
     * Check if a valid connection has been established
     */
    public function isConnectionValid(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_PATH_CONNECTION_VALID);
    }

    /**
     * Resolve credentials from provided values or saved config
     */
    private function resolveCredentials(
        ?string $accountId,
        ?string $endpoint,
        ?string $region,
        ?string $bucket,
        ?string $accessKey,
        ?string $secretKey,
        ?bool $pathStyle
    ): array {
        $resolvedAccountId = $this->resolveValue($accountId, $this->config->getAccountId());
        $resolvedEndpoint = $this->resolveValue($endpoint, $this->config->getEndpoint());

        // Build endpoint from account ID if not provided
        if (empty($resolvedEndpoint) && !empty($resolvedAccountId)) {
            $resolvedEndpoint = sprintf('https://%s.r2.cloudflarestorage.com', $resolvedAccountId);
        }

        return [
            'endpoint' => $resolvedEndpoint,
            'region' => $this->resolveValue($region, $this->config->getRegion()),
            'bucket' => $this->resolveValue($bucket, $this->config->getBucket()),
            'accessKey' => $this->resolveValue($accessKey, $this->config->getAccessKey()),
            'secretKey' => $this->resolveSecretValue($secretKey, $this->config->getSecretKey()),
            'pathStyle' => $pathStyle ?? $this->config->usePathStyle(),
        ];
    }

    /**
     * Validate required fields and return error array if missing
     */
    private function validateRequiredFields(array $credentials): ?array
    {
        $missingFields = [];

        if (empty($credentials['endpoint'])) {
            $missingFields[] = 'Endpoint (or Account ID)';
        }
        if (empty($credentials['bucket'])) {
            $missingFields[] = 'Bucket';
        }
        if (empty($credentials['accessKey'])) {
            $missingFields[] = 'Access Key ID';
        }
        if (empty($credentials['secretKey'])) {
            $missingFields[] = 'Secret Access Key';
        }

        if (!empty($missingFields)) {
            return [
                'success' => false,
                'message' => 'Missing required fields: ' . implode(', ', $missingFields),
            ];
        }

        return null;
    }

    /**
     * Attempt to connect to R2 and return result
     */
    private function attemptConnection(array $credentials): array
    {
        try {
            $client = $this->createClient($credentials);
            $client->listObjectsV2([
                'Bucket' => $credentials['bucket'],
                'MaxKeys' => 1,
            ]);

            $this->saveConnectionStatus(true);

            return [
                'success' => true,
                'message' => 'Connection successful! Bucket "' . $credentials['bucket'] . '" is accessible.',
            ];
        } catch (AwsException $e) {
            return $this->handleAwsException($e, $credentials);
        } catch (\Exception $e) {
            $this->saveConnectionStatus(false);
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Handle AWS exception and return error array
     */
    private function handleAwsException(AwsException $e, array $credentials): array
    {
        $this->saveConnectionStatus(false);
        $errorCode = $e->getAwsErrorCode() ?? 'Unknown';
        $errorMessage = $e->getAwsErrorMessage() ?? $e->getMessage();

        $debug = sprintf(
            ' [Debug: endpoint=%s, region=%s, accessKey=%s, secretKeyLen=%d, pathStyle=%s]',
            $credentials['endpoint'],
            $credentials['region'],
            substr($credentials['accessKey'], 0, 4) . '...',
            strlen($credentials['secretKey']),
            $credentials['pathStyle'] ? 'true' : 'false'
        );

        return [
            'success' => false,
            'message' => sprintf('Connection failed (%s): %s', $errorCode, $errorMessage) . $debug,
        ];
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

    private function createClient(array $credentials): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => $credentials['region'] ?: 'auto',
            'endpoint' => $credentials['endpoint'],
            'credentials' => [
                'key' => $credentials['accessKey'],
                'secret' => $credentials['secretKey'],
            ],
            'use_path_style_endpoint' => $credentials['pathStyle'],
            'signature_version' => 'v4',
        ]);
    }
}
