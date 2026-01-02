<?php
namespace MageZero\CloudflareR2\Model;

use Aws\S3\S3Client;

class R2ClientFactory
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function create(): S3Client
    {
        $options = [
            'version' => 'latest',
            'region' => $this->config->getRegion(),
            'credentials' => [
                'key' => $this->config->getAccessKey(),
                'secret' => $this->config->getSecretKey(),
            ],
            'use_path_style_endpoint' => $this->config->usePathStyle(),
            'signature_version' => 'v4',
        ];

        $endpoint = $this->config->getEndpoint();
        if ($endpoint !== '') {
            $options['endpoint'] = $endpoint;
        }

        return new S3Client($options);
    }
}
