<?php

declare(strict_types=1);

namespace Iyzico\Kolai\Model\Review;

use Iyzico\Kolai\Model\Log\Logger;
use Kolai\Exception\Errors;
use Kolai\Exception\KolaiException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Review\Model\Review;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Zend_Db_Expr;

/**
 * Product reviews of the current store, read with plain SQL into the neutral
 * arrays Kolai\Mapper\ReviewMapper consumes.
 *
 * SQL rather than the review collection: the rating filter needs the rounded
 * average of a review's votes, which the collection cannot filter on, and
 * "verified buyer" is one batched order query instead of one per review.
 *
 * Author e-mail, IP and user agent are never selected (KVKK/GDPR), so there is
 * nothing here that could leak them.
 */
class ReviewReader
{
    public const DEFAULT_PER_PAGE = 100;
    public const MAX_PER_PAGE     = 200;

    /** Kolai (WooCommerce) status name => Magento review status id. 'trash' has no Magento equivalent. */
    private const STATUS_FILTER = [
        'approved' => Review::STATUS_APPROVED,
        'hold'     => Review::STATUS_PENDING,
        'spam'     => Review::STATUS_NOT_APPROVED,
        'trash'    => null,
        'all'      => null,
    ];

    private const STATUS_NAMES = [
        Review::STATUS_APPROVED     => 'approved',
        Review::STATUS_PENDING      => 'hold',
        Review::STATUS_NOT_APPROVED => 'spam',
    ];

    private ResourceConnection $resource;

    private StoreManagerInterface $storeManager;

    private Logger $logger;

    public function __construct(ResourceConnection $resource, StoreManagerInterface $storeManager, Logger $logger)
    {
        $this->resource     = $resource;
        $this->storeManager = $storeManager;
        $this->logger       = $logger;
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,perPage:int}
     * @throws KolaiException
     */
    public function forProduct(int $productId, int $page, int $perPage, string $status, ?int $rating, ?int $since): array
    {
        $started = microtime(true);
        $page    = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $status  = strtolower(trim($status)) ?: 'approved';

        if (!array_key_exists($status, self::STATUS_FILTER)) {
            throw Errors::invalidReviewRequest(
                'Invalid status filter; allowed: ' . implode(', ', array_keys(self::STATUS_FILTER))
            );
        }
        if ($rating !== null && ($rating < 1 || $rating > 5)) {
            throw Errors::invalidRating('rating must be between 1 and 5');
        }

        $this->assertProductExists($productId);

        if ($status === 'trash') {
            return ['items' => [], 'total' => 0, 'page' => $page, 'perPage' => $perPage];
        }

        $connection = $this->resource->getConnection();
        $select     = $this->baseSelect()->where('r.entity_pk_value = ?', $productId);

        if (self::STATUS_FILTER[$status] !== null) {
            $select->where('r.status_id = ?', self::STATUS_FILTER[$status]);
        }
        if ($rating !== null) {
            $select->where('ROUND(v.avg_value) = ?', $rating);
        }
        if ($since !== null) {
            $select->where('r.created_at >= ?', gmdate('Y-m-d H:i:s', $since));
        }

        $count = clone $select;
        $count->reset(Select::COLUMNS)->reset(Select::ORDER)->columns(['cnt' => new Zend_Db_Expr('COUNT(*)')]);
        $total = (int) $connection->fetchOne($count);

        $rows = $total > 0
            ? $connection->fetchAll($select->order('r.review_id DESC')->limitPage($page, $perPage))
            : [];

        $items = $this->format($rows, $productId);

        $this->logger->info('kolai.review: list query finished', [
            'product_id'  => $productId,
            'status'      => $status,
            'rating'      => $rating,
            'count'       => count($items),
            'total'       => $total,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /**
     * @return array<string,mixed>
     * @throws KolaiException
     */
    public function one(int $reviewId): array
    {
        $row = $this->resource->getConnection()->fetchRow(
            $this->baseSelect()->where('r.review_id = ?', $reviewId)
        );

        if (!$row) {
            $this->logger->warning('kolai.review: review not found', ['review_id' => $reviewId]);
            throw Errors::reviewNotFound();
        }

        return $this->format([$row], (int) $row['entity_pk_value'])[0];
    }

    /**
     * Product reviews visible in the current store, with the average vote.
     */
    private function baseSelect(): Select
    {
        $connection = $this->resource->getConnection();
        $storeId    = (int) $this->storeManager->getStore()->getId();

        $votes = $connection->select()
            ->from($this->resource->getTableName('rating_option_vote'), [
                'review_id',
                'avg_value' => new Zend_Db_Expr('AVG(value)'),
            ])
            ->group('review_id');

        return $connection->select()
            ->from(['r' => $this->resource->getTableName('review')], [
                'review_id', 'created_at', 'entity_pk_value', 'status_id',
            ])
            ->join(
                ['e' => $this->resource->getTableName('review_entity')],
                'e.entity_id = r.entity_id',
                []
            )
            ->join(
                ['d' => $this->resource->getTableName('review_detail')],
                'd.review_id = r.review_id',
                ['nickname', 'detail', 'customer_id']
            )
            ->join(
                ['s' => $this->resource->getTableName('review_store')],
                $connection->quoteInto('s.review_id = r.review_id AND s.store_id = ?', $storeId),
                []
            )
            ->joinLeft(['v' => $votes], 'v.review_id = r.review_id', ['avg_value'])
            ->where('e.entity_code = ?', Review::ENTITY_PRODUCT_CODE);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function format(array $rows, int $productId): array
    {
        $buyers = $this->verifiedBuyers($productId, array_filter(array_map(
            static function (array $row): int {
                return (int) ($row['customer_id'] ?? 0);
            },
            $rows
        )));

        $items = [];
        foreach ($rows as $row) {
            $customerId = (int) ($row['customer_id'] ?? 0);
            $items[]    = [
                'id'             => (int) $row['review_id'],
                'product_id'     => (int) $row['entity_pk_value'],
                'rating'         => $row['avg_value'] !== null ? (int) round((float) $row['avg_value']) : null,
                'author'         => (string) $row['nickname'],
                'content'        => (string) $row['detail'],
                // review.created_at is stored in UTC.
                'date'           => gmdate('c', (int) strtotime((string) $row['created_at'] . ' UTC')),
                'status'         => self::STATUS_NAMES[(int) $row['status_id']] ?? (string) $row['status_id'],
                'verified_buyer' => $customerId > 0 && isset($buyers[$customerId]),
            ];
        }

        return $items;
    }

    /**
     * Magento keeps no "verified" flag, so it is derived: the reviewer's account
     * has a non-cancelled order containing this product.
     *
     * @param int[] $customerIds
     * @return array<int,true>
     */
    private function verifiedBuyers(int $productId, array $customerIds): array
    {
        $customerIds = array_values(array_unique($customerIds));
        if ($customerIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select     = $connection->select()
            ->distinct()
            ->from(['i' => $this->resource->getTableName('sales_order_item')], [])
            ->join(['o' => $this->resource->getTableName('sales_order')], 'o.entity_id = i.order_id', ['customer_id'])
            ->where('i.product_id = ?', $productId)
            ->where('o.customer_id IN (?)', $customerIds)
            ->where('o.state <> ?', Order::STATE_CANCELED);

        return array_fill_keys(array_map('intval', $connection->fetchCol($select)), true);
    }

    /**
     * @throws KolaiException
     */
    private function assertProductExists(int $productId): void
    {
        $connection = $this->resource->getConnection();
        $exists     = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_entity'), ['entity_id'])
                ->where('entity_id = ?', $productId)
        );

        if (!$exists) {
            throw Errors::productNotFound();
        }
    }
}
