<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Block\Adminhtml\System\Config;

use Kolai\Contract\ContractTemplates;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Contract textarea plus the placeholder list, read straight from the library so
 * a placeholder added there shows up here without a second list to maintain.
 */
class ContractField extends Field
{
    protected $_template = 'Iyzico_Kolai::system/config/contract_field.phtml';

    /** @return array<string,array<string,string>> group title => placeholder => description */
    public function getPlaceholderGroups(): array
    {
        return [
            (string) __('Satıcı — ayarlardan doldurulur') => ContractTemplates::sellerPlaceholders(),
            (string) __('Alıcı / sipariş — çıkışta Kolai doldurur') => ContractTemplates::orderPlaceholders(),
        ];
    }

    /**
     * Placeholders nobody will ever fill: a typo, or one the store invented.
     * Shown as a warning because in a legal text it reaches the customer as
     * literal curly braces.
     *
     * @return string[]
     */
    public function getUnknownPlaceholders(): array
    {
        $element = $this->getElement();

        return $element === null ? [] : ContractTemplates::unknownPlaceholders((string) $element->getValue());
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $this->setData('element', $element);

        return $element->getElementHtml() . $this->_toHtml();
    }
}
