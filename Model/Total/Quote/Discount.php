<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Total\Quote;

use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;

/**
 * The POST /orders `discountAmount`, as one non-taxable line.
 *
 * The amount is set on the quote (already negative) by the order creator; this
 * collector only moves it into the totals of the address that carries the
 * items, so a virtual quote's billing address and a physical quote's shipping
 * address each get it exactly once. Non-taxable on purpose: the total moves by
 * exactly the gross, which is what Kolai charged (see Kolai\Order\OrderMath).
 *
 * Copied to the order by Observer\CopyDiscountToOrder.
 */
class Discount extends AbstractTotal
{
    public const CODE = 'kolai_discount';

    public function __construct()
    {
        $this->setCode(self::CODE);
    }

    /**
     * @return $this
     */
    public function collect(Quote $quote, ShippingAssignmentInterface $shippingAssignment, Total $total)
    {
        parent::collect($quote, $shippingAssignment, $total);

        $address = $shippingAssignment->getShipping()->getAddress();
        $address->setKolaiDiscountAmount(0)->setBaseKolaiDiscountAmount(0);

        if (!count($shippingAssignment->getItems())) {
            return $this;
        }

        $amount     = (float) $quote->getKolaiDiscountAmount();
        $baseAmount = (float) $quote->getBaseKolaiDiscountAmount();
        if (abs($amount) < 0.00001 && abs($baseAmount) < 0.00001) {
            return $this;
        }

        $total->setTotalAmount(self::CODE, $amount);
        $total->setBaseTotalAmount(self::CODE, $baseAmount);
        $total->setKolaiDiscountAmount($amount);
        $total->setBaseKolaiDiscountAmount($baseAmount);

        $address->setKolaiDiscountAmount($amount)->setBaseKolaiDiscountAmount($baseAmount);

        return $this;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function fetch(Quote $quote, Total $total)
    {
        $amount = (float) $total->getKolaiDiscountAmount();
        if (abs($amount) < 0.00001) {
            return null;
        }

        return [
            'code'  => self::CODE,
            'title' => __('Kolai İndirimi'),
            'value' => $amount,
        ];
    }
}
