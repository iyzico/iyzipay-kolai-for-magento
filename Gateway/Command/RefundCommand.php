<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Gateway\Command;

use Iyzico\Kolai\Model\Order\KolaiOrderRepository;
use Iyzico\Kolai\Model\Payment\RefundRecord;
use Iyzico\Kolai\Model\Payment\RefundService;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;

/**
 * "Refund" (online) on a Kolai order's credit memo. Throwing aborts the credit
 * memo: Magento rolls back its transaction and shows the message to the admin.
 */
class RefundCommand implements CommandInterface
{
    private RefundService $refundService;

    private KolaiOrderRepository $repository;

    public function __construct(RefundService $refundService, KolaiOrderRepository $repository)
    {
        $this->refundService = $refundService;
        $this->repository    = $repository;
    }

    /**
     * @param array<string,mixed> $commandSubject
     * @return null
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute(array $commandSubject)
    {
        /** @var Payment $payment */
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();
        /** @var Order $order */
        $order = $payment->getOrder();

        // Magento hands the gateway the BASE amount. iyzico was charged in the
        // order currency, so refund the credit memo's own total in that currency.
        $creditmemo = $payment->getCreditmemo();
        if ($creditmemo) {
            $amount   = (float) $creditmemo->getGrandTotal();
            $currency = (string) $order->getOrderCurrencyCode();
        } else {
            $amount   = (float) SubjectReader::readAmount($commandSubject);
            $currency = (string) $order->getBaseCurrencyCode();
        }

        $operationId = $this->refundService->refund($order, $amount, $currency);

        // Unique per refund, so several partial credit memos each get their own
        // transaction instead of colliding on one id. RefundRecord looks for this
        // id to tell whether the credit memo was committed.
        $paymentId = (string) ($this->repository->get((int) $order->getId())['payment_id'] ?? '');
        $payment->setTransactionId(RefundRecord::transactionId($paymentId, (int) $order->getId(), $operationId));
        $payment->setIsTransactionClosed(true);

        return null;
    }
}
