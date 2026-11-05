<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Total\Invoice;

use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Invoice\Total\AbstractTotal;

/**
 * Carries the order's Kolai discount and installment adjustment (both signed)
 * onto the first invoice that has not already taken them.
 *
 * Both are order-level amounts with no item to hang on, so splitting them
 * across partial invoices would be arbitrary. Kolai orders are invoiced in one
 * go by PATCH /orders anyway (registerCaptureNotification), and that invoice
 * must equal what iyzico charged — the order grand total including both.
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
    public function collect(Invoice $invoice)
    {
        $order = $invoice->getOrder();

        foreach (self::FIELDS as $field => $baseField) {
            $invoice->setData($field, 0)->setData($baseField, 0);
        }

        $taken     = array_fill_keys(array_keys(self::FIELDS), 0.0);
        $baseTaken = array_fill_keys(array_keys(self::FIELDS), 0.0);
        foreach ($order->getInvoiceCollection() as $previous) {
            if ($previous->getId() === null || (int) $previous->getId() === (int) $invoice->getId()
                || (int) $previous->getState() === Invoice::STATE_CANCELED) {
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
            $invoice->setData($field, $amount)->setData($baseField, $baseAmount);
            $sum     += $amount;
            $baseSum += $baseAmount;
        }

        $invoice->setGrandTotal($invoice->getGrandTotal() + $sum);
        $invoice->setBaseGrandTotal($invoice->getBaseGrandTotal() + $baseSum);

        return $this;
    }
}
