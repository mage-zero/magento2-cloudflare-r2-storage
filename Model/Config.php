<?php
namespace MageZero\CloudflareR2\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

class Config
{
    public const XML_PATH_STORAGE_MEDIA = 'system/media_storage_configuration/media_storage';
    public const XML_PATH_ACCOUNT_ID = 'magezero_r2/general/account_id';
    public const XML_PATH_ENDPOINT = 'magezero_r2/general/endpoint';
    public const XML_PATH_REGION = 'magezero_r2/general/region';
    public const XML_PATH_BUCKET = 'magezero_r2/general/bucket';
    public const XML_PATH_ACCESS_KEY = 'magezero_r2/general/access_key';
    public const XML_PATH_SECRET_KEY = 'magezero_r2/general/secret_key';
    public const XML_PATH_KEY_PREFIX = 'magezero_r2/general/key_prefix';
    public const XML_PATH_PATH_STYLE = 'magezero_r2/general/path_style';

    private ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function isR2Selected(): bool
    {
        return (int)$this->scopeConfig->getValue(self::XML_PATH_STORAGE_MEDIA) ===
            \MageZero\CloudflareR2\Model\MediaStorage\File\Storage::STORAGE_MEDIA_R2;
    }

    public function getAccountId(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_ACCOUNT_ID));
    }

    public function getEndpoint(): string
    {
        $endpoint = trim((string)$this->scopeConfig->getValue(self::XML_PATH_ENDPOINT));
        if ($endpoint !== '') {
            return $endpoint;
        }

        $accountId = $this->getAccountId();
        if ($accountId === '') {
            return '';
        }

        return sprintf('https://%s.r2.cloudflarestorage.com', $accountId);
    }

    public function getRegion(): string
    {
        $region = trim((string)$this->scopeConfig->getValue(self::XML_PATH_REGION));
        return $region !== '' ? $region : 'auto';
    }

    public function getBucket(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_BUCKET));
    }

    public function getAccessKey(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_ACCESS_KEY);
    }

    public function getSecretKey(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_SECRET_KEY);
    }

    public function getKeyPrefix(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_KEY_PREFIX));
    }

    public function usePathStyle(): bool
    {
        return (bool)$this->scopeConfig->getValue(self::XML_PATH_PATH_STYLE);
    }
}
