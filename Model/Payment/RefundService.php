<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Payment;

use Iyzico\Kolai\Model\Log\Logger;
use Iyzico\Kolai\Model\Order\KolaiOrderRepository;
use Kolai\Exception\KolaiException;
use Kolai\Payment\RefundAllocator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Throwable;

/**
 * Sends a credit memo's amount to iyzico, spread over the order's
 * itemTransactions (iyzico refunds per paymentTransactionId, never per order).
 *
 * Magento calls this INSIDE the credit memo's DB transaction and rolls the
 * credit memo back when it throws. Money already moved at iyzico cannot be
 * rolled back, so everything that records it — the ledger and the operation
 * trail — goes through the isolated connection and survives the rollback.
 *
 * A failure after some steps succeeded leaves an operation in status
 * "partial". Re-submitting a credit memo for the SAME amount resumes it and
 * sends only what is still outstanding; the credit memo is recorded only once
 * the whole amount has moved. A different amount abandons the open operation
 * (kept for manual reconciliation) and starts afresh — the ledger still caps
 * each transaction at its paidPrice, so nothing is refunded twice.
 */
class RefundService
{
    public const STATUS_IN_PROGRESS          = 'in_progress';
    public const STATUS_SUCCESS              = 'success';
    public const STATUS_PARTIAL              = 'partial';
    public const STATUS_FAILED               = 'failed';
    public const STATUS_NEEDS_RECONCILIATION = 'needs_reconciliation';
    public const STATUS_ABANDONED            = 'abandoned';

    /** Statuses a same-amount retry may continue. */
    private const RESUMABLE = [self::STATUS_PARTIAL, self::STATUS_IN_PROGRESS];

    private const MAX_OPERATIONS = 50;

    private KolaiOrderRepository $repository;

    private IyzicoClientFactory $clientFactory;

    private OrderLock $lock;

    private Logger $logger;

    public function __construct(
        KolaiOrderRepository $repository,
        IyzicoClientFactory $clientFactory,
        OrderLock $lock,
        Logger $logger
    ) {
        $this->repository    = $repository;
        $this->clientFactory = $clientFactory;
        $this->lock          = $lock;
        $this->logger        = $logger;
    }

    /**
     * @param float  $amount   In the order's currency.
     * @param string $currency Order currency code.
     * @return string The operation id, for the refund transaction id.
     * @throws LocalizedException The credit memo must not be recorded.
     */
    public function refund(Order $order, float $amount, string $currency): string
    {
        $orderId = (int) $order->getId();
        $amount  = round($amount, 2);

        if ($amount <= RefundAllocator::EPSILON) {
            throw new LocalizedException(__('İade tutarı sıfırdan büyük olmalıdır.'));
        }

        $client = $this->clientFactory->forOrder($order);
        if (!$client->isConfigured()) {
            throw new LocalizedException(__(
                'iyzico API anahtarları ayarlanmamış (Mağazalar › Yapılandırma › Satışlar › Kolai). İade iyzico\'ya gönderilmedi.'
            ));
        }

        if (!$this->lock->acquire($orderId)) {
            throw new LocalizedException(__(
                'Bu sipariş için başka bir iade işlemi devam ediyor. Lütfen birkaç saniye sonra tekrar deneyin.'
            ));
        }

        try {
            return $this->run($order, $client, $amount, $currency);
        } finally {
            $this->lock->release($orderId);
        }
    }

    /**
     * @param \Kolai\Payment\IyzicoClient $client
     * @throws LocalizedException
     */
    private function run(Order $order, $client, float $amount, string $currency): string
    {
        $orderId = (int) $order->getId();

        // Re-read under the lock, through the connection we write with.
        $data         = $this->repository->get($orderId, true);
        $transactions = (array) ($data['item_transactions'] ?? []);
        $ledger       = self::floats((array) ($data['refunded_transactions'] ?? []));
        $operations   = array_values((array) ($data['refund_operations'] ?? []));

        if ($transactions === []) {
            throw new LocalizedException(__(
                'Bu sipariş için iyzico işlem bilgisi (itemTransactions) bulunamadı; iade iyzico\'ya gönderilemez. Gerekirse çevrimdışı iade oluşturun.'
            ));
        }

        $index     = $this->openOperation($operations, $amount, $orderId);
        $succeeded = $index === null ? [] : self::floats((array) ($operations[$index]['allocations'] ?? []));
        $moved     = self::sum($succeeded);
        $request   = round($amount - $moved, 2);

        if ($index === null) {
            $operations[] = [
                'operationId' => RefundAllocator::newOperationId(),
                'requested'   => $amount,
                'allocations' => [],
                'status'      => self::STATUS_IN_PROGRESS,
                'message'     => '',
                'at'          => gmdate('c'),
            ];
            $index = count($operations) - 1;
        }
        $operationId = (string) $operations[$index]['operationId'];

        // An earlier attempt moved everything but failed before recording the
        // credit memo: nothing left to send, let this credit memo record it.
        if ($request <= RefundAllocator::EPSILON) {
            $operations = $this->mark($operations, $index, self::STATUS_SUCCESS, $succeeded, '');
            $this->persistOperations($orderId, $operations);
            $order->addCommentToStatusHistory(__(
                'iyzico iadesi daha önce tamamlanmıştı (%1, işlem %2); iade kaydı oluşturuldu.',
                self::money($amount),
                $operationId
            ));

            return $operationId;
        }

        try {
            $plan = RefundAllocator::plan($transactions, $ledger, $request);
        } catch (KolaiException $e) {
            throw new LocalizedException(__($e->getMessage()));
        }

        if ($plan['unallocated'] > RefundAllocator::EPSILON) {
            // Nothing was sent. A brand-new operation leaves no trace; a resumed
            // one keeps its partial status for the next attempt.
            if ($moved <= RefundAllocator::EPSILON) {
                array_splice($operations, $index, 1);
            }
            $this->persistOperations($orderId, $operations);

            throw new LocalizedException(__(
                'İade edilebilir tutar yetersiz. iyzico\'da iade edilebilir kalan: %1, karşılanamayan tutar: %2. Hiçbir iade gönderilmedi.',
                self::money(RefundAllocator::refundableTotal($transactions, $ledger)),
                self::money($plan['unallocated'])
            ));
        }

        // Leave a trace before the first call, so a crash mid-loop is visible.
        $this->persistOperations($orderId, $operations);

        $this->logger->info('kolai.payment: refund started', [
            'order_id'     => $orderId,
            'operation_id' => $operationId,
            'requested'    => $amount,
            'resumed_from' => $moved,
            'steps'        => count($plan['steps']),
        ]);

        $notes = [];
        foreach ($plan['steps'] as $step) {
            $transactionId = $step['paymentTransactionId'];
            $result        = $client->refund(
                $transactionId,
                $step['amount'],
                $currency,
                RefundAllocator::conversationId((string) $orderId, $transactionId, $operationId)
            );

            if (empty($result['success'])) {
                $this->fail($order, $operations, $index, $succeeded, $amount, $step, $result);
            }

            $ledger    = RefundAllocator::record($ledger, $transactionId, $step['amount']);
            $succeeded = RefundAllocator::record($succeeded, $transactionId, $step['amount']);
            $operations = $this->mark($operations, $index, self::STATUS_PARTIAL, $succeeded, '');

            try {
                $this->repository->saveIsolated($orderId, [
                    'refunded_transactions' => $ledger,
                    'refund_operations'     => self::capped($operations),
                ]);
            } catch (Throwable $e) {
                $this->logger->error('kolai.payment: refund ledger write failed after iyzico refund', [
                    'order_id'             => $orderId,
                    'operation_id'         => $operationId,
                    'paymentTransactionId' => $transactionId,
                    'amount'               => $step['amount'],
                    'exception'            => $e,
                ]);
                $operations = $this->mark(
                    $operations,
                    $index,
                    self::STATUS_NEEDS_RECONCILIATION,
                    $succeeded,
                    'refund ledger persistence failed'
                );
                $this->persistOperations($orderId, $operations);

                throw new LocalizedException(__(
                    'iyzico iadesi ALINDI (%1, işlem %2) ancak yerel iade defteri güncellenemedi. Mükerrer iadeyi önlemek için tekrar denemeden önce durumu iyzico panelinden MANUEL kontrol edin.',
                    self::money($step['amount']),
                    $transactionId
                ));
            }

            $notes[] = __('iyzico iade başarılı: %1 (işlem %2)', self::money($step['amount']), $transactionId);
        }

        $operations = $this->mark($operations, $index, self::STATUS_SUCCESS, $succeeded, '');
        $this->persistOperations($orderId, $operations);

        // These ride the credit memo transaction — fine, it commits on success.
        foreach ($notes as $note) {
            $order->addCommentToStatusHistory($note);
        }

        $this->logger->info('kolai.payment: refund completed', [
            'order_id'     => $orderId,
            'operation_id' => $operationId,
            'amount'       => $amount,
        ]);

        return $operationId;
    }

    /**
     * Picks the open operation to resume, abandoning any that cannot be.
     *
     * @param array<int,array<string,mixed>> $operations Modified in place.
     */
    private function openOperation(array &$operations, float $amount, int $orderId): ?int
    {
        $resume = null;
        foreach ($operations as $i => $operation) {
            if (!in_array($operation['status'] ?? '', self::RESUMABLE, true)) {
                continue;
            }
            $sameAmount = abs((float) ($operation['requested'] ?? 0) - $amount) < RefundAllocator::EPSILON;
            if ($sameAmount && $resume === null) {
                $resume = $i;
                continue;
            }

            $operations[$i]['status']  = self::STATUS_ABANDONED;
            $operations[$i]['message'] = trim(($operation['message'] ?? '') . ' Abandoned: a refund of a different amount was requested; reconcile manually.');
            $this->logger->warning('kolai.payment: open partial refund abandoned', [
                'order_id'     => $orderId,
                'operation_id' => $operation['operationId'] ?? null,
                'requested'    => $operation['requested'] ?? null,
                'refunded'     => self::sum(self::floats((array) ($operation['allocations'] ?? []))),
                'new_amount'   => $amount,
            ]);
        }

        return $resume;
    }

    /**
     * Records the failed step and throws.
     *
     * @param array<int,array<string,mixed>> $operations
     * @param array<string,float>            $succeeded
     * @param array{paymentTransactionId:string,amount:float} $step
     * @param array<string,mixed>            $result
     * @throws LocalizedException
     */
    private function fail(
        Order $order,
        array $operations,
        int $index,
        array $succeeded,
        float $amount,
        array $step,
        array $result
    ): void {
        $orderId = (int) $order->getId();
        $moved   = self::sum($succeeded);
        $reason  = (string) ($result['errorMessage'] ?: __('iyzico iade işlemi başarısız.'));
        // No errorCode: the call threw before iyzico answered, so it may or may
        // not have been executed there.
        $unknown = ($result['errorCode'] ?? null) === null;
        // An unknown outcome is never resumed automatically: the step may have
        // moved money the ledger does not know about. It stays flagged in the
        // order's Kolai box until someone checks the iyzico panel.
        if ($unknown) {
            $status = self::STATUS_NEEDS_RECONCILIATION;
        } else {
            $status = $moved > RefundAllocator::EPSILON ? self::STATUS_PARTIAL : self::STATUS_FAILED;
        }

        $operations = $this->mark($operations, $index, $status, $succeeded, $reason);
        if ($unknown) {
            $operations[$index]['uncertainStep'] = $step;
        }
        $this->persistOperations($orderId, $operations);

        $this->logger->error('kolai.payment: refund step failed', [
            'order_id'             => $orderId,
            'operation_id'         => $operations[$index]['operationId'] ?? null,
            'paymentTransactionId' => $step['paymentTransactionId'],
            'amount'               => $step['amount'],
            'refunded_so_far'      => $moved,
            'errorCode'            => $result['errorCode'] ?? null,
            'outcome_unknown'      => $unknown,
        ]);

        $uncertain = $unknown
            ? ' ' . __('iyzico\'dan yanıt alınamadı; işlem %1 iyzico panelinden kontrol edilmeden tekrar denemeyin.', $step['paymentTransactionId'])
            : '';

        if ($unknown) {
            throw new LocalizedException(__(
                'iyzico\'dan yanıt alınamadı (işlem %1, %2). %3 iade edildi. Bu adımın sonucu bilinmiyor; mükerrer iadeyi önlemek için iyzico panelinden kontrol etmeden tekrar denemeyin. Kolai kutusunda "needs_reconciliation" olarak işaretlendi.',
                $step['paymentTransactionId'],
                self::money($step['amount']),
                self::money($moved)
            ));
        }

        if ($status === self::STATUS_PARTIAL) {
            throw new LocalizedException(__(
                'iyzico KISMİ iade: %1 iade edildi, %2 BAŞARISIZ (işlem %3): %4.%5 Kalan tutarı göndermek için aynı tutarla (%6) iade oluşturmayı tekrar deneyin; iade edilmiş kısım tekrar gönderilmez.',
                self::money($moved),
                self::money(round($amount - $moved, 2)),
                $step['paymentTransactionId'],
                $reason,
                $uncertain,
                self::money($amount)
            ));
        }

        throw new LocalizedException(__(
            'iyzico iadesi BAŞARISIZ: %1 (işlem %2): %3.%4 Hiçbir tutar iade edilmedi.',
            self::money($step['amount']),
            $step['paymentTransactionId'],
            $reason,
            $uncertain
        ));
    }

    /**
     * @param array<int,array<string,mixed>> $operations
     * @param array<string,float>            $allocations
     * @return array<int,array<string,mixed>>
     */
    private function mark(array $operations, int $index, string $status, array $allocations, string $message): array
    {
        $operations[$index]['status']      = $status;
        $operations[$index]['allocations'] = $allocations;
        $operations[$index]['message']     = $message;
        $operations[$index]['at']          = gmdate('c');

        return $operations;
    }

    /**
     * Best effort: a failure here is logged, never allowed to mask the outcome
     * being reported.
     *
     * @param array<int,array<string,mixed>> $operations
     */
    private function persistOperations(int $orderId, array $operations): void
    {
        try {
            $this->repository->saveIsolated($orderId, ['refund_operations' => self::capped($operations)]);
        } catch (Throwable $e) {
            $this->logger->error('kolai.payment: refund operation log write failed', [
                'order_id'  => $orderId,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Only the stored copy is trimmed; the in-memory list keeps its indexes.
     *
     * @param array<int,array<string,mixed>> $operations
     * @return array<int,array<string,mixed>>
     */
    private static function capped(array $operations): array
    {
        return array_values(array_slice($operations, -self::MAX_OPERATIONS));
    }

    /**
     * @param array<mixed,mixed> $map
     * @return array<string,float>
     */
    private static function floats(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            if (is_numeric($value)) {
                $out[(string) $key] = round((float) $value, 2);
            }
        }

        return $out;
    }

    /** @param array<string,float> $map */
    private static function sum(array $map): float
    {
        return round(array_sum($map), 2);
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
