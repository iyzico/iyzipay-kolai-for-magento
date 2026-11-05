<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Auth;

use Kolai\Auth\NonceStore as NonceStoreInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;

/**
 * Single-use salt store on a primary key, which is what makes it atomic across
 * PHP-FPM workers: of two concurrent copies of a replayed request, exactly one
 * INSERT wins. Magento's cache frontend has no add-if-absent, so a
 * load-then-save there would let both copies through.
 */
class NonceStore implements NonceStoreInterface
{
    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    public function consume(string $key, int $ttlSeconds): bool
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('kolai_nonce');
        $hash       = hash('sha256', $key);
        $now        = time();
        $row        = ['nonce_hash' => $hash, 'expires_at' => $now + $ttlSeconds];

        try {
            $connection->insert($table, $row);

            return true;
        } catch (DuplicateException $e) {
            // Claimed before. Free it only if that claim has lapsed; the DELETE is
            // the arbiter, so two late copies cannot both reclaim it.
            $freed = $connection->delete($table, ['nonce_hash = ?' => $hash, 'expires_at <= ?' => $now]);
            if ($freed !== 1) {
                return false;
            }
            try {
                $connection->insert($table, $row);

                return true;
            } catch (DuplicateException $raced) {
                return false;
            }
        }
    }

    /** @return int Rows deleted. */
    public function purgeExpired(): int
    {
        return (int) $this->resource->getConnection()->delete(
            $this->resource->getTableName('kolai_nonce'),
            ['expires_at <= ?' => time()]
        );
    }
}
