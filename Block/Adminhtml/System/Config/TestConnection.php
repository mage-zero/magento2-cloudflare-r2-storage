<?php
declare(strict_types=1);

namespace MageZero\CloudflareR2\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use MageZero\CloudflareR2\Model\ConnectionValidator;

class TestConnection extends Field
{
    private const TEST_CONNECTION_PATH = 'magezero_r2/system_config/testconnection';

    protected $_template = 'MageZero_CloudflareR2::system/config/testconnection.phtml';

    private ConnectionValidator $connectionValidator;

    public function __construct(
        Context $context,
        ConnectionValidator $connectionValidator,
        array $data = []
    ) {
        $this->connectionValidator = $connectionValidator;
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element): string
    {
        $element->setData('scope', null);
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $this->addData([
            'button_label' => __($element->getOriginalData()['button_label']),
        ]);

        return $this->_toHtml();
    }

    public function isConnectionValid(): bool
    {
        return $this->connectionValidator->isConnectionValid();
    }

    public function getAjaxUrl(): string
    {
        return $this->_urlBuilder->getUrl(
            self::TEST_CONNECTION_PATH,
            ['form_key' => $this->getFormKey()]
        );
    }

    public function getFieldMapping(): array
    {
        return [
            'account_id' => 'magezero_r2_general_account_id',
            'endpoint' => 'magezero_r2_general_endpoint',
            'region' => 'magezero_r2_general_region',
            'bucket' => 'magezero_r2_general_bucket',
            'access_key' => 'magezero_r2_general_access_key',
            'secret_key' => 'magezero_r2_general_secret_key',
            'path_style' => 'magezero_r2_general_path_style',
        ];
    }
}
