<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Payment;

use Iyzico\Kolai\Model\Db\IsolatedConnection;
use Kolai\Payment\RefundAllocator;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Answers "did Magento keep the credit memo for this refund operation?".
 *
 * RefundService finishes an operation INSIDE the credit memo transaction, and
 * its own trail is written outside it. If Magento then rolls the credit memo
 * back, iyzico has the money back but the store has no record of it. Commit
 * callbacks cannot tell us: CreditmemoService commits on the raw adapter, which
 * never runs them. So a finished operation is left "awaiting_credit_memo" and
 * settled later, from what the database actually holds:
 *
 *  - its refund transaction (sales_payment_transaction, txn_id below) exists →
 *    the credit memo was committed → "success";
 *  - otherwise, if the money iyzico returned is not covered by the order's
 *    committed total_refunded, the credit memo is really missing → stays
 *    awaiting, and a same-amount retry records it without sending money again;
 *  - otherwise someone recorded it another way (an offline credit memo) →
 *    "success", so a later refund of the same amount is sent for real.
 */
class RefundRecord
{
    private ResourceConnection $resource;

    private IsolatedConnection $isolated;

    public function __construct(ResourceConnection $resource, IsolatedConnection $isolated)
    {
        $this->resource = $resource;
        $this->isolated = $isolated;
    }

    /** The payment transaction id the credit memo's refund is stored under. */
    public static function transactionId(string $paymentId, int $orderId, string $operationId): string
    {
        return ($paymentId !== '' ? $paymentId : 'kolai-' . $orderId) . '-refund-' . substr($operationId, 0, 8);
    }

    /**
     * Resolves every "awaiting_credit_memo" operation, oldest first.
     *
     * @param array<string,mixed> $data     The order's kolai_order row, decoded.
     * @param bool                $isolated Read committed data only — what the
     *                                      refund path must do, since it runs
     *                                      inside the credit memo transaction.
     * @return array<int,array<string,mixed>> The operations, settled.
     */
    public function settle(int $orderId, array $data, bool $isolated = false): array
    {
        $operations = array_values(array_filter((array) ($data['refund_operations'] ?? []), 'is_array'));

        $awaiting = array_keys(array_filter($operations, static function (array $operation): bool {
            return ($operation['status'] ?? '') === RefundService::STATUS_AWAITING_CREDIT_MEMO;
        }));
        if ($awaiting === []) {
            return $operations;
        }

        $connection = $isolated ? $this->isolated->get() : $this->resource->getConnection('sales');
        $paymentId  = (string) ($data['payment_id'] ?? '');
        $recorded   = $this->recordedTransactions($connection, $orderId);

        // Money at iyzico that no committed credit memo accounts for.
        $gap = round(
            array_sum(array_map('floatval', (array) ($data['refunded_transactions'] ?? [])))
            - $this->committedTotalRefunded($connection, $orderId),
            2
        );

        foreach ($awaiting as $i) {
            $txnId  = self::transactionId($paymentId, $orderId, (string) ($operations[$i]['operationId'] ?? ''));
            $amount = round((float) ($operations[$i]['requested'] ?? 0), 2);

            if (isset($recorded[$txnId])) {
                $operations[$i]['status']  = RefundService::STATUS_SUCCESS;
                $operations[$i]['message'] = '';
                continue;
            }

            if ($gap + RefundAllocator::EPSILON >= $amount) {
                // Still missing; this operation claims its share of the gap.
                $gap = round($gap - $amount, 2);
                $operations[$i]['message'] = 'iyzico refunded, credit memo not saved. Create the credit memo again with the same amount (Refund): nothing is sent to iyzico again.';
                continue;
            }

            $operations[$i]['status']  = RefundService::STATUS_SUCCESS;
            $operations[$i]['message'] = 'Recorded by another credit memo (e.g. offline).';
        }

        return $operations;
    }

    /** @return array<string,true> */
    private function recordedTransactions(AdapterInterface $connection, int $orderId): array
    {
        $ids = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('sales_payment_transaction'), ['txn_id'])
                ->where('order_id = ?', $orderId)
                ->where('txn_type = ?', 'refund')
        );

        return array_fill_keys(array_map('strval', $ids), true);
    }

    private function committedTotalRefunded(AdapterInterface $connection, int $orderId): float
    {
        return (float) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('sales_order'), ['total_refunded'])
                ->where('entity_id = ?', $orderId)
        );
    }
}
