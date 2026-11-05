<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Total\Creditmemo;

use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Total\AbstractTotal;

/**
 * Settles the order's Kolai discount and installment adjustment on the LAST
 * credit memo only.
 *
 * A partial credit memo refunds item prices; those adjustments belong to the
 * order as a whole, and spreading them per item would be a guess. Whatever has
 * not been refunded yet is added when the credit memo closes out the order, so
 * the refunds sum to exactly what was paid. The admin can still move money
 * between memos with Magento's own Adjustment Refund / Adjustment Fee fields.
 */
class Adjustment extends AbstractTotal
{
    private const FIELDS = [
        'kolai_discount_amount'      => 'base_kolai_discount_amount',
        'kolai_installment_fee'      => 'base_kolai_installment_fee',
    ];

    /**
     * @return $this
     */
    public function collect(Creditmemo $creditmemo)
    {
        foreach (self::FIELDS as $field => $baseField) {
            $creditmemo->setData($field, 0)->setData($baseField, 0);
        }

        if (!$creditmemo->isLast()) {
            return $this;
        }

        $order     = $creditmemo->getOrder();
        $taken     = array_fill_keys(array_keys(self::FIELDS), 0.0);
        $baseTaken = array_fill_keys(array_keys(self::FIELDS), 0.0);
        // false for an order that is not saved yet.
        foreach ($order->getCreditmemosCollection() ?: [] as $previous) {
            if ($previous->getId() === null || (int) $previous->getId() === (int) $creditmemo->getId()
                || (int) $previous->getState() === Creditmemo::STATE_CANCELED) {
                continue;
            }
            foreach (self::FIELDS as $field => $baseField) {
                $taken[$field]     += (float) $previous->getData($field);
                $baseTaken[$field] += (float) $previous->getData($baseField);
            }
        }

        $sum     = 0.0;
        $baseSum = 0.0;
        foreach (self::FIELDS as $field => $baseField) {
            $amount     = round((float) $order->getData($field) - $taken[$field], 4);
            $baseAmount = round((float) $order->getData($baseField) - $baseTaken[$field], 4);
            if (abs($amount) < 0.00001 && abs($baseAmount) < 0.00001) {
                continue;
            }
            $creditmemo->setData($field, $amount)->setData($baseField, $baseAmount);
            $sum     += $amount;
            $baseSum += $baseAmount;
        }

        $creditmemo->setGrandTotal($creditmemo->getGrandTotal() + $sum);
        $creditmemo->setBaseGrandTotal($creditmemo->getBaseGrandTotal() + $baseSum);

        return $this;
    }
}
