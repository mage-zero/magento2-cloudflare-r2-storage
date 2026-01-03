<?php
declare(strict_types=1);

namespace MageZero\CloudflareR2\Controller\Adminhtml\System\Config;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use MageZero\CloudflareR2\Model\ConnectionValidator;

class TestConnection extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageZero_CloudflareR2::config';

    private JsonFactory $resultJsonFactory;
    private ConnectionValidator $connectionValidator;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        ConnectionValidator $connectionValidator
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->connectionValidator = $connectionValidator;
    }

    public function execute(): ResultInterface
    {
        $params = $this->getRequest()->getParams();

        $result = $this->connectionValidator->testConnection(
            $params['account_id'] ?? null,
            $params['endpoint'] ?? null,
            $params['region'] ?? null,
            $params['bucket'] ?? null,
            $params['access_key'] ?? null,
            $params['secret_key'] ?? null,
            isset($params['path_style']) ? (bool) $params['path_style'] : null
        );

        return $this->resultJsonFactory->create()->setData($result);
    }
}
