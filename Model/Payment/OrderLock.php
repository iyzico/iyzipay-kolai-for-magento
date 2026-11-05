<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Payment;

use Iyzico\Kolai\Model\Db\IsolatedConnection;

/**
 * Per-order mutex for refunds, so two admins (or a double-clicked button) cannot
 * plan against the same ledger at once and both refund the same balance.
 *
 * MySQL named locks are held by the session that took them. Taking it on the
 * isolated connection keeps it independent of the credit memo transaction and
 * of the connection pool Magento may reuse. Timeout 0: fail fast, never queue.
 */
class OrderLock
{
    private const PREFIX = 'kolai_refund_';

    private IsolatedConnection $db;

    private ?string $database = null;

    public function __construct(IsolatedConnection $db)
    {
        $this->db = $db;
    }

    public function acquire(int $orderId): bool
    {
        return (string) $this->db->get()->fetchOne('SELECT GET_LOCK(?, 0)', [$this->name($orderId)]) === '1';
    }

    public function release(int $orderId): void
    {
        $this->db->get()->fetchOne('SELECT RELEASE_LOCK(?)', [$this->name($orderId)]);
    }

    /** Lock names are server-wide, so two stores sharing a MySQL server must not collide. */
    private function name(int $orderId): string
    {
        if ($this->database === null) {
            $this->database = (string) $this->db->get()->fetchOne('SELECT DATABASE()');
        }

        // MySQL caps lock names at 64 characters; hash the database part to fit.
        return self::PREFIX . substr(md5($this->database), 0, 12) . '_' . $orderId;
    }
}
