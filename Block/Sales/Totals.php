<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Block\Sales;

use Magento\Framework\DataObject;
use Magento\Framework\View\Element\AbstractBlock;

/**
 * Adds the Kolai rows to an order / invoice / credit memo totals block, so
 * subtotal + shipping + tax + these visibly add up to the grand total.
 *
 * Child of the stock totals blocks (order_totals, invoice_totals,
 * creditmemo_totals); the parent calls initTotals() before rendering.
 */
class Totals extends AbstractBlock
{
    /**
     * @return $this
     */
    public function initTotals()
    {
        $parent = $this->getParentBlock();
        if (!$parent || !method_exists($parent, 'getSource') || !method_exists($parent, 'addTotalBefore')) {
            return $this;
        }

        $source = $parent->getSource();
        if (!$source instanceof DataObject) {
            return $this;
        }

        $discount = (float) $source->getData('kolai_discount_amount');
        if (abs($discount) >= 0.00001) {
            $parent->addTotalBefore(new DataObject([
                'code'       => 'kolai_discount',
                'value'      => $discount,
                'base_value' => (float) $source->getData('base_kolai_discount_amount'),
                'label'      => __('Kolai İndirimi'),
            ]), 'grand_total');
        }

        $fee = (float) $source->getData('kolai_installment_fee');
        if (abs($fee) >= 0.00001) {
            $parent->addTotalBefore(new DataObject([
                'code'       => 'kolai_installment_fee',
                'value'      => $fee,
                'base_value' => (float) $source->getData('base_kolai_installment_fee'),
                'label'      => $fee > 0 ? __('Vade Farkı (taksit)') : __('İndirim (taksit)'),
            ]), 'grand_total');
        }

        return $this;
    }
}
