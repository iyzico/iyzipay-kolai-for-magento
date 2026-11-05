<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Order;

use Iyzico\Kolai\Model\Db\IsolatedConnection;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Kolai's per-order data (kolai_order): iyzico paymentId, itemTransactions, the
 * refund ledger and audit trail, cancel result, invoice fields.
 *
 * Kept out of sales_order on purpose — it is protocol state, not order state,
 * and the ledger must be writable outside the order's transaction. Use
 * saveIsolated() for anything that records money already moved at iyzico
 * (see IsolatedConnection for why); save() for everything else.
 *
 * JSON columns are decoded on read: item_transactions, refunded_transactions,
 * refund_operations, cancel_result come back as arrays ([] when empty).
 */
class KolaiOrderRepository
{
    public const JSON_FIELDS = ['item_transactions', 'refunded_transactions', 'refund_operations', 'cancel_result'];

    private const COLUMNS = [
        'payment_id', 'item_transactions', 'refunded_transactions', 'refund_operations',
        'cancel_result', 'installment_count', 'invoice_type', 'tax_id', 'tax_office',
    ];

    private ResourceConnection $resource;

    private IsolatedConnection $isolated;

    public function __construct(ResourceConnection $resource, IsolatedConnection $isolated)
    {
        $this->resource = $resource;
        $this->isolated = $isolated;
    }

    /**
     * @param bool $isolated Read committed data through the isolated connection —
     *                       what the refund path must do so it sees the same
     *                       ledger it writes.
     * @return array<string,mixed> Empty array when the order has no Kolai row.
     */
    public function get(int $orderId, bool $isolated = false): array
    {
        $connection = $isolated ? $this->isolated->get() : $this->resource->getConnection();
        $row        = $connection->fetchRow(
            $connection->select()->from($this->table())->where('order_id = ?', $orderId)
        );

        if (!$row) {
            return [];
        }

        foreach (self::JSON_FIELDS as $field) {
            $decoded     = isset($row[$field]) && $row[$field] !== '' ? json_decode((string) $row[$field], true) : null;
            $row[$field] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    /**
     * Upsert only the given fields; arrays in JSON_FIELDS are encoded.
     *
     * @param array<string,mixed> $fields
     */
    public function save(int $orderId, array $fields): void
    {
        $this->write($this->resource->getConnection(), $orderId, $fields);
    }

    /**
     * Same as save(), committed immediately and independently of any open
     * transaction on the default connection.
     *
     * @param array<string,mixed> $fields
     */
    public function saveIsolated(int $orderId, array $fields): void
    {
        $this->write($this->isolated->get(), $orderId, $fields);
    }

    /**
     * @param array<string,mixed> $fields
     */
    private function write(AdapterInterface $connection, int $orderId, array $fields): void
    {
        $row = ['order_id' => $orderId];
        foreach ($fields as $field => $value) {
            if (!in_array($field, self::COLUMNS, true)) {
                continue;
            }
            if (in_array($field, self::JSON_FIELDS, true) && is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $row[$field] = $value;
        }

        if (count($row) === 1) {
            return;
        }

        // UPDATE, not INSERT ... ON DUPLICATE KEY, whenever the row exists: an
        // INSERT attempt runs the FK check on sales_order, which waits for the
        // shared lock on the order row. The isolated connection writes while the
        // default connection's open transaction (credit memo, cancel) holds that
        // row, so an upsert would block until lock wait timeout. An UPDATE that
        // leaves order_id alone skips the check. Isolated writes always find the
        // row: the paymentId / itemTransactions they depend on live in it.
        $exists = (bool) $connection->fetchOne(
            $connection->select()->from($this->table(), ['order_id'])->where('order_id = ?', $orderId)
        );
        if ($exists) {
            $connection->update($this->table(), array_diff_key($row, ['order_id' => true]), ['order_id = ?' => $orderId]);
            return;
        }

        $connection->insert($this->table(), $row);
    }

    private function table(): string
    {
        return $this->resource->getTableName('kolai_order');
    }
}
