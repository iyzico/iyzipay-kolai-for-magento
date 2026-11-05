<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Payment;

use Iyzico\Kolai\Model\Log\Logger;
use Iyzico\Kolai\Model\Order\KolaiOrderRepository;
use Magento\Sales\Model\Order;
use Throwable;

/**
 * Cancels the whole iyzico payment when a Kolai order is cancelled.
 *
 * Magento only lets an order be cancelled before it is invoiced, and Kolai
 * orders are invoiced when PATCH /orders reports them paid. So this covers the
 * narrow paid-but-not-invoiced case; invoiced orders are refunded through a
 * credit memo instead. iyzico itself accepts a cancel only on the payment day.
 *
 * Never throws: an iyzico refusal must not undo the store's cancel. The
 * outcome goes into cancel_result, an order comment and the log.
 */
class CancelService
{
    private KolaiOrderRepository $repository;

    private IyzicoClientFactory $clientFactory;

    private Logger $logger;

    public function __construct(
        KolaiOrderRepository $repository,
        IyzicoClientFactory $clientFactory,
        Logger $logger
    ) {
        $this->repository    = $repository;
        $this->clientFactory = $clientFactory;
        $this->logger        = $logger;
    }

    public function cancel(Order $order): void
    {
        $orderId = (int) $order->getId();

        try {
            $data = $this->repository->get($orderId, true);

            if (!empty($data['cancel_result']['success'])) {
                return;
            }

            $paymentId = trim((string) ($data['payment_id'] ?? ''));
            if ($paymentId === '') {
                // Never paid through Kolai (e.g. an expired pending order): nothing to cancel.
                $this->logger->info('kolai.payment: cancel skipped, no paymentId', ['order_id' => $orderId]);

                return;
            }

            $client = $this->clientFactory->forOrder($order);
            if (!$client->isConfigured()) {
                $this->logger->warning('kolai.payment: cancel skipped, iyzico keys not configured', [
                    'order_id' => $orderId,
                ]);
                $order->addCommentToStatusHistory(__(
                    'Sipariş iptal edildi ancak iyzico API anahtarları ayarlı olmadığı için iyzico iptali yapılamadı (paymentId %1).',
                    $paymentId
                ));

                return;
            }

            $result = $client->cancel($paymentId, 'order-' . $orderId . '-cancel');

            $this->repository->saveIsolated($orderId, [
                'cancel_result' => [
                    'success'      => !empty($result['success']),
                    'errorCode'    => $result['errorCode'] ?? null,
                    'errorMessage' => $result['errorMessage'] ?? null,
                    'at'           => gmdate('c'),
                ],
            ]);

            if (!empty($result['success'])) {
                $order->addCommentToStatusHistory(__('iyzico iptal başarılı: paymentId %1', $paymentId));

                return;
            }

            $order->addCommentToStatusHistory(__(
                'iyzico iptal BAŞARISIZ: paymentId %1: %2. Ödeme gününden sonra iptal yapılamaz; tutarı iyzico panelinden iade edin.',
                $paymentId,
                (string) ($result['errorMessage'] ?: __('bilinmeyen hata'))
            ));
        } catch (Throwable $e) {
            $this->logger->error('kolai.payment: cancel failed unexpectedly', [
                'order_id'  => $orderId,
                'exception' => $e,
            ]);
            try {
                $order->addCommentToStatusHistory(__(
                    'iyzico iptali sırasında beklenmeyen hata; iyzico panelinden kontrol edin.'
                ));
            } catch (Throwable $ignored) {
                // The cancel itself must go through.
            }
        }
    }
}
