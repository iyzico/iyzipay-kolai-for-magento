<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Order;

use Iyzico\Kolai\Model\Log\Logger;
use Kolai\Exception\Errors;
use Kolai\Exception\KolaiException;
use Kolai\Order\OrderMath;
use Kolai\Support\Str;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Throwable;

/**
 * PATCH /orders/{id}: status change plus, optionally, what iyzico reported.
 *
 * The paid path is Magento's own capture notification (what a gateway IPN
 * does): it creates the invoice and the capture transaction carrying the
 * iyzico paymentId. That invoice is what makes "Credit Memo > Refund" online
 * available later, and so the iyzico refund.
 *
 * Order of work matters: the installment adjustment changes the grand total,
 * so it runs before the capture, which must capture the adjusted total.
 */
class OrderUpdater
{
    private const PAID_STATES = [Order::STATE_PROCESSING, Order::STATE_COMPLETE];

    private StatusMap $statusMap;

    private KolaiOrderRepository $kolaiOrders;

    private OrderRepositoryInterface $orderRepository;

    private OrderSender $orderSender;

    private Logger $logger;

    public function __construct(
        StatusMap $statusMap,
        KolaiOrderRepository $kolaiOrders,
        OrderRepositoryInterface $orderRepository,
        OrderSender $orderSender,
        Logger $logger
    ) {
        $this->statusMap       = $statusMap;
        $this->kolaiOrders     = $kolaiOrders;
        $this->orderRepository = $orderRepository;
        $this->orderSender     = $orderSender;
        $this->logger          = $logger;
    }

    /**
     * @param array<string,mixed> $payload
     * @throws KolaiException
     */
    public function update(Order $order, array $payload): Order
    {
        $status = trim((string) ($payload['orderStatus'] ?? ''));
        if ($status === '') {
            throw Errors::invalidOrderRequest('orderStatus is required');
        }
        if (!$this->statusMap->isKnown($status)) {
            throw Errors::invalidOrderRequest('Invalid orderStatus: ' . $status);
        }

        $state     = $this->statusMap->stateFor($status, (string) $order->getState());
        $paymentId = Str::clean($payload['paymentId'] ?? '');

        $this->assertNotFinal($order, $status, $state, $paymentId);

        $this->savePaymentData($order, $payload, $paymentId);
        $this->applyInstallmentAdjustment($order, $payload);

        if ($state === Order::STATE_CANCELED) {
            $this->cancel($order, $status);
        } elseif ($paymentId !== '' && in_array($state, self::PAID_STATES, true) && $order->canInvoice()) {
            $this->registerPayment($order, $paymentId, $status, $state);
        } else {
            $this->setStatus($order, $status, $state);
        }

        $this->orderRepository->save($order);

        $this->logger->info('kolai.order: order updated', [
            'orderId' => (int) $order->getEntityId(),
            'status'  => (string) $order->getStatus(),
            'state'   => (string) $order->getState(),
            'paid'    => $paymentId !== '',
        ]);

        if ($paymentId !== '' && in_array((string) $order->getState(), self::PAID_STATES, true)) {
            $this->sendOrderEmail($order);
        }

        return $order;
    }

    /**
     * A canceled or closed order is final: only a repeat cancel is accepted.
     *
     * The case that matters is a payment that succeeds after the order expired
     * (sales_clean_orders canceled it and released the stock). Moving it to a
     * paid status would leave canceled items, no invoice and no reservation.
     * Refusing lets Kolai refund the payment and send the cancel instead. The
     * decision is by state, not by orderExpireAt: until the cron cancels it,
     * the order still holds its stock and can be paid.
     *
     * @throws KolaiException
     */
    private function assertNotFinal(Order $order, string $status, string $state, string $paymentId): void
    {
        $current = (string) $order->getState();
        if (!in_array($current, [Order::STATE_CANCELED, Order::STATE_CLOSED], true)) {
            return;
        }
        if ($current === Order::STATE_CANCELED && $state === Order::STATE_CANCELED) {
            return;
        }

        $this->logger->warning('kolai.order: status change refused, order is final', [
            'orderId'   => (int) $order->getEntityId(),
            'state'     => $current,
            'requested' => $status,
            'paymentId' => $paymentId,
        ]);

        if ($paymentId !== '') {
            // The money moved at iyzico; the merchant should see that it did.
            $order->addCommentToStatusHistory((string) __(
                'Kolai: sipariş "%1" durumundayken ödeme bildirimi geldi (paymentId: %2) ve reddedildi. Ödemenin Kolai tarafından iade edilmesi beklenir.',
                $current,
                $paymentId
            ));
            $this->orderRepository->save($order);
        }

        throw Errors::invalidOrderRequest(sprintf(
            'Order is %s and can no longer be updated to %s',
            $current,
            $status
        ));
    }

    /**
     * paymentId and itemTransactions are what a later iyzico refund/cancel
     * needs; without them the admin can only refund offline.
     *
     * @param array<string,mixed> $payload
     */
    private function savePaymentData(Order $order, array $payload, string $paymentId): void
    {
        $fields = [];
        if ($paymentId !== '') {
            $fields['payment_id'] = $paymentId;
        }

        if (isset($payload['itemTransactions']) && is_array($payload['itemTransactions'])) {
            $transactions = [];
            foreach ($payload['itemTransactions'] as $transaction) {
                if (!is_array($transaction)) {
                    continue;
                }
                $transactions[] = [
                    'itemId'               => Str::clean($transaction['itemId'] ?? ''),
                    'paymentTransactionId' => Str::clean($transaction['paymentTransactionId'] ?? ''),
                    'transactionStatus'    => isset($transaction['transactionStatus'])
                        ? (int) $transaction['transactionStatus'] : null,
                    'price'                => isset($transaction['price']) ? (float) $transaction['price'] : null,
                    'paidPrice'            => isset($transaction['paidPrice']) ? (float) $transaction['paidPrice'] : null,
                ];
            }
            if ($transactions !== []) {
                $fields['item_transactions'] = $transactions;
            }
        }

        $installment = isset($payload['installment']) ? (int) $payload['installment'] : 0;
        if ($installment > 1) {
            $fields['installment_count'] = $installment;
        }

        if ($fields !== []) {
            $this->kolaiOrders->save((int) $order->getEntityId(), $fields);
        }
    }

    /**
     * Reconciles the order total with iyzico's paidPrice as one signed amount
     * (kolai_installment_fee): positive vade farki, negative indirim. The old
     * amount is taken out first, so the same paidPrice sent twice converges on
     * one fee instead of stacking.
     *
     * Only before invoicing: an invoiced total is a document, not a draft. A
     * repeat of an already-applied paidPrice is still accepted, as a no-op.
     *
     * @param array<string,mixed> $payload
     */
    private function applyInstallmentAdjustment(Order $order, array $payload): void
    {
        if (!isset($payload['paidPrice'])) {
            return;
        }

        $currentFee     = (float) $order->getData('kolai_installment_fee');
        $currentBaseFee = (float) $order->getData('base_kolai_installment_fee');
        $baseTotal      = round((float) $order->getGrandTotal() - $currentFee, 2);

        $adjustment = OrderMath::installmentAdjustment($payload, $baseTotal);
        $newFee     = $adjustment['kind'] === 'none' ? 0.0 : (float) $adjustment['amount'];

        if (abs($newFee - $currentFee) < OrderMath::EPSILON) {
            return;
        }

        if (!$order->canInvoice() || (float) $order->getTotalPaid() > 0) {
            $this->logger->warning('kolai.order: installment adjustment skipped, order already invoiced', [
                'orderId'    => (int) $order->getEntityId(),
                'currentFee' => $currentFee,
                'newFee'     => $newFee,
            ]);

            return;
        }

        $rate       = (float) $order->getBaseToOrderRate() ?: 1.0;
        $newBaseFee = round($newFee / $rate, 4);

        $order->setGrandTotal(round((float) $order->getGrandTotal() - $currentFee + $newFee, 4));
        $order->setBaseGrandTotal(round((float) $order->getBaseGrandTotal() - $currentBaseFee + $newBaseFee, 4));
        $order->setData('kolai_installment_fee', $newFee === 0.0 ? null : $newFee);
        $order->setData('base_kolai_installment_fee', $newFee === 0.0 ? null : $newBaseFee);

        if ($newFee !== 0.0) {
            $label = $newFee > 0
                ? ($adjustment['installment'] > 1
                    ? __('%1 taksit için vade farkı: %2', $adjustment['installment'], Str::money($newFee))
                    : __('Vade farkı: %1', Str::money($newFee)))
                : __('İndirim: %1', Str::money($newFee));
            $order->addCommentToStatusHistory((string) __('Kolai: %1', $label));
        }

        $this->logger->info('kolai.order: installment adjustment applied', [
            'orderId'     => (int) $order->getEntityId(),
            'kind'        => $adjustment['kind'],
            'amount'      => $newFee,
            'installment' => $adjustment['installment'],
        ]);
    }

    /**
     * @throws KolaiException
     */
    private function registerPayment(Order $order, string $paymentId, string $status, string $state): void
    {
        $payment = $order->getPayment();
        $payment->setTransactionId($paymentId);
        $payment->setLastTransId($paymentId);
        $payment->setParentTransactionId(null);
        // Left open so credit memos can refer to it.
        $payment->setIsTransactionClosed(false);
        $payment->registerCaptureNotification((float) $order->getBaseTotalDue(), true);

        if ($payment->getIsFraudDetected()) {
            // Amount or currency did not match the order: Magento put it under
            // payment review rather than invoicing. Leave it there for a human.
            $this->logger->warning('kolai.order: capture did not match order total, payment review', [
                'orderId' => (int) $order->getEntityId(),
            ]);

            return;
        }

        $this->setStatus($order, $status, $state);
    }

    /**
     * @throws KolaiException
     */
    private function cancel(Order $order, string $status): void
    {
        if ($order->getState() === Order::STATE_CANCELED) {
            $order->setStatus($status);

            return;
        }
        if (!$order->canCancel()) {
            throw Errors::invalidOrderRequest(
                'Order cannot be canceled in its current state; refund it with a credit memo'
            );
        }

        $order->cancel();
        $order->setStatus($status);
        $order->addCommentToStatusHistory((string) __('Kolai: sipariş iptal edildi.'));
    }

    private function setStatus(Order $order, string $status, string $state): void
    {
        if ($state !== '' && $state !== $order->getState()) {
            $order->setState($state);
        }
        if ($order->getStatus() !== $status) {
            $order->setStatus($status);
            $order->addCommentToStatusHistory((string) __('Kolai: durum "%1" olarak güncellendi.', $status));
        }
    }

    /**
     * The order email is held back while payment is pending (see report: the
     * payment method's order_place_redirect_url), and goes out once paid.
     */
    private function sendOrderEmail(Order $order): void
    {
        if ($order->getEmailSent()) {
            return;
        }
        try {
            $this->orderSender->send($order);
        } catch (Throwable $e) {
            $this->logger->warning('kolai.order: order email could not be sent', [
                'orderId'   => (int) $order->getEntityId(),
                'exception' => $e,
            ]);
        }
    }
}
