<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;

/**
 * sales_model_service_quote_submit_before: copies the Kolai discount from the
 * totals address to the order.
 *
 * A fieldset.xml copy does not work here: Quote\Address\ToOrder hands the
 * fieldset data to DataObjectHelper::populateWithArray(), which drops keys the
 * OrderInterface has no setter for, so custom order columns stay NULL.
 */
class CopyDiscountToOrder implements ObserverInterface
{
    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getData('quote');
        $order = $observer->getEvent()->getData('order');
        if (!$quote instanceof Quote || !$order instanceof Order) {
            return;
        }

        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        $amount  = (float) $address->getData('kolai_discount_amount');
        if ($amount === 0.0) {
            return;
        }

        $order->setData('kolai_discount_amount', $amount);
        $order->setData('base_kolai_discount_amount', (float) $address->getData('base_kolai_discount_amount'));
    }
}
